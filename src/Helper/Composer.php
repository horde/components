<?php
/**
 * Copyright 2013-2019 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013-2019 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
namespace Horde\Components\Helper;
use Horde\Components\Exception;
use Horde\Components\Wrapper\HordeYml as WrapperHordeYml;

/**
 * @author    Michael Slusarz <slusarz@horde.org>
 * @author    Ralf Lang <lang@horde.org>
 * @category  Horde
 * @copyright 2013-2019 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class Composer
{

    /**
     * @var array A list of repositories to add as sources for dependencies
     */
    protected $_repositories = array();
    /**
     * @var array A list of pear packages to replace by known alternatives
     */
    protected $_substitutes = array();

    /**
     * @var string Govern which repos to add to the composer file
     * use 'vcs' for an individual git repo per horde dependency
     * use 'satis:https://url' for a single composer repo source
     * leave empty for no custom repos
     */
    protected $_composerRepo = '';

    /**
     * @var string Overwrite horde dependency versions
     */
    protected $_composerVersion = '';

    protected $_vendor = '';

    protected $_gitRepoBase = '';

    /**
     * Updates the composer.json file.
     *
     * @param WrapperHordeYml $package  The package definition
     * @param array           $options  The set of options for the operation.
     * 
     * @return string The composer.json file
     */
    public function generateComposerJson(WrapperHordeYml $package, array $options = array())
    {
        if (!empty($options['composer_opts']['pear-substitutes']))
        {
            $this->_substitutes = $options['composer_opts']['pear-substitutes'];
        }

        // Handle cases where vendor is not horde.
        $this->_vendor = $options['vendor'] ?? 'horde';
        // The git repo base URL, defaults to github/vendor.
        $this->_gitRepoBase = $options['git_repo_base'] ??
            'https://github.com/' . $this->_vendor . '/';
        // Decide on repo type hints
        if (!empty($options['composer_repo'])) {
            if ($options['composer_repo'] == 'vcs') {
                $this->_composerRepo = 'vcs';
            }
            if (substr($options['composer_repo'], 0, 6) == 'satis:') {
                $this->_composerRepo = 'composer';
                $this->_repositories['composer'] = [
                    'type' => 'composer',
                    'url' => substr($options['composer_repo'], 6)
                ];
            }
        }
        // Override horde dependency versions
        if (!empty($options['composer_version'])) {
            $this->_composerVersion = $options['composer_version'];
        }

        $filename = dirname($package->getFullPath()) . '/composer.json';
        $composerDefinition = new \stdClass();
        $this->_setName($package, $composerDefinition);
        // Is this intentional? "description" seems always longer than full
        $composerDefinition->description = $package['full'];
        $this->_setType($package, $composerDefinition);
        $composerDefinition->homepage = $package['homepage'] ?? 'https://www.horde.org';
        $composerDefinition->license = $package['license']['identifier'];
        $this->_setAuthors($package, $composerDefinition);
        // cut off any -git or similar
        list($version) = explode('-', $package['version']['release']);
        $composerDefinition->version = $version;
        $composerDefinition->time = (new \Horde_Date(time()))->format('Y-m-d');
        $composerDefinition->repositories = [];
        $this->_setRequire($package, $composerDefinition);
        $this->_setSuggest($package, $composerDefinition);
        $this->_setRepositories($package, $composerDefinition);
        $this->_setAutoload($package, $composerDefinition);
        $this->_setVendorBin($package, $composerDefinition);
        // Development dependencies?
        // Replaces ? Only needed for special cases. Default cases are handled implicitly
        // provides? apps can depend on provided APIs rather than other apps

        // Enforce suggest to be a json object rather than array
        if (empty($composerDefinition->suggest)) {
            $composerDefinition->suggest = new \stdClass();
        }
        $jsonDefinition = json_encode($composerDefinition, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        file_put_contents($filename, $jsonDefinition);

        if (isset($options['logger'])) {
            $options['logger']->ok(
                'Created composer.json file.'
            );
        }
        return $jsonDefinition;
    }

    /**
     * Build a list of commands which should be exposed to vendor/bin.
     *
     * Default to all direct executable files of bindir
     * Otherwise use provided whitelist "commands"
     * and blacklist "nocommands" (blacklist wins)
     */
    protected function _setVendorBin(WrapperHordeYml $package, \stdClass $composerDefinition)
    {
        $commands = [];
        $noCommands = [];
        if (!empty($package['nocommands'])) {
            $noCommands = $package['nocommands'];
        }
        /**
         * If the package sports an explicit list of commands, use only these
         */
        if (!empty($package['commands'])) {
            $commands = $package['commands'];
        } else {
            // No explicit list - search bindir
            $binDir = dirname($package->getFullPath()) . '/bin/';
            if (is_dir($binDir)) {
                foreach (new \DirectoryIterator($binDir) as $file) {
                    if ($file->isExecutable() and $file->isFile()) {
                        $commands[] = 'bin/' . $file->getFilename();
                    }
                }
            }
        }
        /**
         * If the package provides a blacklist, filter.
         *
         */
        if ($noCommands) {
            $commands = array_diff($commands, $noCommands);
        }
        if ($commands) {
            $composerDefinition->bin = array_values($commands);
        }
    }

    protected function _setName(WrapperHordeYml $package, \stdClass $composerDefinition)
    {
        $vendor = $this->_vendor;
        $name = \Horde_String::lower($package['name']);
        $composerDefinition->name = "$vendor/$name";
    }

    protected function _setType(WrapperHordeYml $package, \stdClass $composerDefinition)
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

    protected function _setAuthors(WrapperHordeYml $package, \stdClass $composerDefinition)
    {
        $composerDefinition->authors = array();
        foreach ($package['authors'] as $author) {
            $person = new \stdClass();
            $person->name = $author['name'];
            $person->email = $author['email'];
            $person->role = $author['role'];
            array_push($composerDefinition->authors, $person);
        }
    }

    /**
     * Configure Autoloading
     * 
     * The default is to autoload both PSR-0 and PSR-4 if no rule is found
     * 
     * @param WrapperHordeYml A Yaml definition of the package
     * @param stdClass the composer definition file to build
     */
    protected function _setAutoload(WrapperHordeYml $package, \stdClass $composerDefinition)
    {
        $composerDefinition->autoload = [];

        $Psr0Name = $package['type'] == 'library' ? 'Horde_' . $package['name'] : $package['name'];
        /**
         * TODO: Support other vendor strings
         */
        $parts = explode('_', $package['name']);
        $Psr4Name = 'Horde\\';
        foreach ($parts as $part) {
            $Psr4Name .= ucfirst($part) . '\\';
        }
        if (!empty($package['autoload'])) {
            foreach ($package['autoload'] as $type => $definition) {
                if ($type == 'classmap') {
                    $composerDefinition->autoload['classmap']  =  $definition;
                }
                if ($type == 'psr-0') {
                    $composerDefinition->autoload['psr-0']  =  $definition;
                }
                if ($type == 'psr-4') {
                    $composerDefinition->autoload['psr-4']  =  $definition;
                }
            }
        } else {
            $composerDefinition->autoload['psr-0']  = [$Psr0Name  => 'lib/'];
            $composerDefinition->autoload['psr-4']  = [$Psr4Name  => 'src/'];
        }
    }

    /**
     * Convert .horde.yml requirements to composer format
     *
     * References to the horde pear channel will be changed to composer
     * Depending on options, assume horde packages live on either
     * - packagist/default repo
     * - a satis repo
     * - individual git repos
     */
    protected function _setRequire(WrapperHordeYml $package, \stdClass $composerDefinition)
    {
        $version = ($this->_composerVersion) ?: '*';
        $composerDefinition->require = array('horde/horde-installer-plugin' => $version);

        if (empty($package['dependencies']['required'])) {
            return;
        }
        foreach ($package['dependencies']['required'] as $element => $required) {
            if ($element == 'composer') {
                // composer dependencies which have no pear equivalent, i.e. unbundling
                foreach ($required as $dep => $version) {
                    // Do we need to override versions or the likes here?
                    $composerDefinition->require[$dep] = $version;
                    continue;
                }
            }
            if ($element == 'pear') {
                foreach ($required as $pear => $version) {
                    list($repo, $basename) = explode('/', $pear);
                    // If it's on our substitute whitelist, convert to composer-native
                    if ($this->_substitute($pear, $version, $composerDefinition->require)) {
                        continue;
                    }
                    // If it's a horde pear component, rather use composer-native and add github vcs as repository
                    if ($repo == 'pear.horde.org') {
                        $vendor = $this->_vendor;
                        if (!empty($this->_composerVersion)) {
                            $version = $this->_composerVersion;
                        }
                        if ($basename == 'horde') {
                            // the "horde" app lives in the "base" repo.
                            $repo = 'base';
                        } elseif (substr($basename, 0, 6) === 'Horde_') {
                            $basename = $repo = substr($basename, 6);
                        } else {
                            // regular app
                            $repo = $basename;
                        }
                        $this->_handleVersion($version, $composerDefinition->require, 'horde', $repo, $basename, $vendor);
                        continue;
                    }
                    if ($repo == 'pecl.php.net') {
                        $this->_handleVersion($version, $composerDefinition->require, 'ext', $repo, $basename);
                        continue;
                    }
                    // Else, require from pear and add pear as a source.
                    $this->_handleVersion($version, $composerDefinition->require, 'pear', $repo, $basename);
                }
            }
            if ($element == 'php') {
                $composerDefinition->require[$element] = $required;
            }
            if ($element == 'ext') {
               foreach ($required as $ext => $version) {
                    $this->_handleVersion($version, $composerDefinition->require, 'ext', $repo, $ext);
               }
            }
        }
    }

    // Deal with packages appropriately
    protected function _handleVersion($version, &$stack, $type, $repo, $basename, $vendor = '')
    {
        $ext = '';
        if (is_array($version)) {
            $ext = empty($version['providesextension']) ? '' : $version['providesextension'];
            $version = empty($version['version']) ? '*' : $version['version'];
        }
        if ($type == 'ext') {
            $ext = $basename;
        }
        if ($ext) {
            $stack['ext-' . $ext] = $version;
        } elseif ($type == 'pear') {
            $stack['pear-' . "$repo/$basename"] = $version;
            $this->_repositories['pear-' . $repo] = ['url' => 'https://' . $repo, 'type' => 'pear'];
        } else {
            // Most likely, this is always composer
            $stack[\Horde_String::lower("$vendor/$basename")] = $version;
            if ($this->_composerRepo == 'vcs') {
                $this->_repositories["$vendor/$basename"] = ['url' => "https://github.com/$vendor/$repo", 'type' => 'vcs'];
            }
        }
    }

    protected function _addPearRepo($pear)
    {
        $repo = substr($pear, 0, strrpos($pear, '/'));
        $this->_repositories['pear-' . $repo] = ['url' => 'https://' . $repo, 'type' => 'pear'];
    }

    /**
     * Convert .horde.yml suggestions to composer format
     *
     * References to the horde pear channel will be changed to composer vcs/github
     */
    protected function _setSuggest(WrapperHordeYml $package, \stdClass $composerDefinition)
    {
        $composerDefinition->suggest = array();
        if (empty($package['dependencies']['optional'])) {
            return;
        }
        foreach ($package['dependencies']['optional'] as $element => $suggested) {
            if ($element == 'composer') {
                // composer dependencies which have no pear equivalent, i.e. unbundling
                foreach ($suggested as $dep => $version) {
                    // Do we need to override versions or the likes here?
                    $composerDefinition->suggest[$dep] = $version;
                    continue;
                }
            }
            if ($element == 'pear') {
                foreach ($suggested as $pear => $version) {
                    list($repo, $basename) = \explode('/', $pear);
                    // If it's on our substitute whitelist, convert to composer-native
                    if ($this->_substitute($pear, $version, $composerDefinition->suggest)) {
                        continue;
                    }
                    // If it's a horde pear component, rather use composer-native and add github vcs as repository
                    if ($repo == 'pear.horde.org') {
                        $vendor = $this->_vendor;
                        if ($basename == 'horde') {
                            // the "horde" app lives in the "base" repo.
                            $repo = 'base';
                        } elseif(\substr($basename, 0, 6) === 'Horde_') {
                            $basename = $repo = \substr($basename, 6);
                        } else {
                            // regular app
                            $repo = $basename;
                        }
                        $this->_handleVersion($version, $composerDefinition->suggest, 'horde', $repo, $basename, $vendor);
                        continue;
                    }
                    if ($repo == 'pecl.php.net') {
                        $this->_handleVersion($version, $composerDefinition->suggest, 'ext', $repo, $basename);
                        continue;
                    }
                    // Else, take from pear and add pear as a source.
                    $this->_handleVersion($version, $composerDefinition->suggest, 'pear', $repo, $basename);
                }
            }
            if ($element == 'php') {
                $composerDefinition->suggest[$element] = $suggested;
            }
            if ($element == 'ext') {
                foreach ($suggested as $ext => $version) {
                    $repo = '';
                    $this->_handleVersion($version, $composerDefinition->suggest, 'ext', $repo, $ext);
                }
            }
        }
    }

    // Handle the substitution list
    protected function _substitute($pear, $version, &$stack)
    {
        if (!empty($this->_substitutes[$pear])) {
            $stack[$this->_substitutes[$pear]['name']] = $version;
            if ($this->_substitutes[$pear]['source'] != 'Packagist')
            {
                throw new Exception("Non-Packagist substitutes not yet implemented:" . $this->_substitutes[$pear]['source']);
            }
            return true;
        }
        return false;
    }

    protected function _setRepositories(WrapperHordeYml $package, \stdClass $composerDefinition)
    {
        $composerDefinition->repositories = array_values($this->_repositories);
    }
}
