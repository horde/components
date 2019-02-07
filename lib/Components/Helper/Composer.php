<?php
/**
 * Copyright 2013-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013-2017 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */

/**
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2013-2017 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class Components_Helper_Composer
{

    protected $_repositories = array();

    /**
     * Updates the composer.json file.
     *
     * @param Components_Wrapper_HordeYml $package  The package definition
     * @param array  $options  The set of options for the operation.
     */
    public function generateComposeJson(Components_Wrapper_HordeYml $package, array $options = array())
    {
        $filename = dirname($package->getFullPath()) . '/composer.json';
        $composerDefinition = new stdClass();
        $this->_setName($package, $composerDefinition);
        // Is this intentional? "description" seems always longer than full
        $composerDefinition->description = $package['full'];
        $this->_setType($package, $composerDefinition);
        $composerDefinition->homepage = 'https://www.horde.org';
        $composerDefinition->license = $package['license']['identifier'];
        $this->_setAuthors($package, $composerDefinition);
        $composerDefinition->version = $package['version']['release'];
        $composerDefinition->time = (new Horde_Date(mktime()))->format('Y-m-d');
        $composerDefinition->repositories = [];
        $this->_setRequire($package, $composerDefinition);
        $this->_setSuggest($package, $composerDefinition);
        $this->_setRepositories($package, $composerDefinition);
        $this->_setAutoload($package, $composerDefinition);
        // Development dependencies?
        // Replaces ? Only needed for special cases. Default cases are handled implicitly
        // provides? apps can depend on provided APIs rather than other apps
        file_put_contents($filename, json_encode($composerDefinition, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        if (isset($options['logger'])) {
            $options['logger']->ok(
                'Created composer.json file.'
            );
        }
    }

    protected function _setName(Components_Wrapper_HordeYml $package, stdClass $composerDefinition)
    {
        $vendor = 'horde'; // TODO: Make configurable for horde-like separately owned code
        $name = Horde_String::lower($package['name']);
        $composerDefinition->name = "$vendor/$name";
    }

    protected function _setType(Components_Wrapper_HordeYml $package, stdClass $composerDefinition)
    {
        if ($package['type'] == 'library') {
            $composerDefinition->type = 'horde-library';
        }
        if ($package['type'] == 'application') {
            $composerDefinition->type = 'horde-application';
        }
        if ($package['type'] == 'component') {
            $composerDefinition->type = 'horde-application';
        }
        // No type is perfectly valid for composer. Types for themes, bundles?
    }

    protected function _setAuthors(Components_Wrapper_HordeYml $package, stdClass $composerDefinition)
    {
        $composerDefinition->authors = array();
        foreach ($package['authors'] as $author) {
            $person = new stdClass();
            $person->name = $author['name'];
            $person->email = $author['email'];
            $person->role = $author['role'];
            array_push($composerDefinition->authors, $person);
        }
    }

    protected function _setAutoload(Components_Wrapper_HordeYml $package, stdClass $composerDefinition)
    {
        $name = $package['type'] == 'library' ? 'Horde_' . $package['name'] : $package['name'];
        $composerDefinition->autoload = ['psr-0' => [$name  => 'lib/']];
    }

    /**
     * Convert .horde.yml requirements to composer format
     *
     * References to the horde pear channel will be changed to composer vcs/github
     */
    protected function _setRequire(Components_Wrapper_HordeYml $package, stdClass $composerDefinition)
    {
        if (empty($package['dependencies']['required'])) {
            return;
        }
        $composerDefinition->require = array();
        foreach ($package['dependencies']['required'] as $element => $required) {
            if ($element == 'pear') {
                foreach ($required as $pear => $version) {
                    list($repo, $basename) = explode('/', $pear);
                    // If it's on our packagist whitelist, convert to composer-native
                    // If it's a horde pear component, rather use composer-native and add github vcs as repository
                    if ($repo == 'pear.horde.org') {
                        $vendor = 'horde';
                        if ($basename == 'horde') {
                            // the "horde" app lives in the "base" repo.
                            $repo = 'base';
                        } elseif(substr($basename, 0, 6) === 'Horde_') {
                            $basename = $repo = substr($basename, 6);
                        } else {
                            // regular app
                            $repo = $basename;
                        }
                        $composerDefinition->require["$vendor/$basename"] = $version;
                        // Developer mode - don't add horde vcs repos in releases, use packagist
                        $this->_repositories["$vendor/$basename"] = ['url' => "https://github.com/$vendor/$repo", 'type' => 'vcs'];
                        continue;
                    }
                    // Else, require from pear and add pear as a source.
                    $composerDefinition->require['pear-' . $pear] = $version;
                    $this->_addPearRepo($pear);
                }
            }
            if ($element == 'php') {
                $composerDefinition->require[$element] = $required;
            }
            if ($element == 'ext') {
               foreach ($required as $ext => $version) {
                   if (is_array($version)) {
                       $version = empty($version['version']) ? '*' : $version['version'];
                   }
                   $composerDefinition->require['ext-' . $ext] = $version;
               }
            }
        }
    }

    protected function _addPearRepo($pear)
    {
        $repo = substr($pear, 0, strrpos($pear, '/'));
        $this->_repositories['pear-' . $repo] = ['uri' => 'https://' . $repo, 'type' => 'pear'];
    }

    /**
     * Convert .horde.yml suggestions to composer format
     *
     * References to the horde pear channel will be changed to composer vcs/github
     */
    protected function _setSuggest(Components_Wrapper_HordeYml $package, stdClass $composerDefinition)
    {
        $composerDefinition->suggest = array();
        if (empty($package['dependencies']['optional'])) {
            return;
        }
        foreach ($package['dependencies']['optional'] as $element => $suggested) {
            if ($element == 'pear') {
                foreach ($suggested as $pear => $version) {
                    list($repo, $basename) = explode('/', $pear);
                    // If it's on our packagist whitelist, convert to composer-native
                    // If it's a horde pear component, rather use composer-native and add github vcs as repository
                    if ($repo == 'pear.horde.org') {
                        $vendor = 'horde';
                        if ($basename == 'horde') {
                            // the "horde" app lives in the "base" repo.
                            $repo = 'base';
                        } elseif(substr($basename, 0, 6) === 'Horde_') {
                            $basename = $repo = substr($basename, 6);
                        } else {
                            // regular app
                            $repo = $basename;
                        }
                        $composerDefinition->suggest["$vendor/$basename"] = $version;
                        // Developer mode - don't add horde vcs repos in releases, use packagist
                        $this->_repositories["$vendor/$basename"] = ['uri' => "https://github.com/$vendor/$repo", 'type' => 'vcs'];
                        continue;
                    }
                    // Else, take from pear and add pear as a source.
                    $composerDefinition->suggest['pear-' . $pear] = $version;
                    $this->_addPearRepo($pear);
                }
            }
            if ($element == 'php') {
                $composerDefinition->suggest[$element] = $suggested;
            }
            if ($element == 'ext') {
               foreach ($suggested as $ext => $version) {
                   if (is_array($version)) {
                       $version = empty($version['version']) ? '*' : $version['version'];
                   }
                   $composerDefinition->suggest['ext-' . $ext] = $version;
               }
            }
        }

    }

    protected function _setRepositories(Components_Wrapper_HordeYml $package, stdClass $composerDefinition)
    {
        $composerDefinition->repositories = array_values($this->_repositories);
    }
}
