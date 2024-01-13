<?php
/**
 * Copyright 2013-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013-2024 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */

namespace Horde\Components\Helper;

use Horde\Components\Exception;
use Horde\Components\Wrapper\HordeYml as WrapperHordeYml;
use RuntimeException;
use Horde\Components\Component\Task\SystemCallResult;
use Horde\Components\Component\Task\SystemCall;
use stdClass;

/**
 * @author    Michael Slusarz <slusarz@horde.org>
 * @author    Ralf Lang <lang@horde.org>
 * @category  Horde
 * @copyright 2013-2024 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class Composer
{
    use SystemCall;
    /**
     * @var array A list of repositories to add as sources for dependencies
     */
    protected $_repositories = [];
    /**
     * @var array A list of pear packages to replace by known alternatives
     */
    protected $_substitutes = [];

    /**
     * @var string Govern which repos to add to the composer file
     * use 'vcs' for an individual git repo per horde dependency
     * use 'satis:https://url' for a single composer repo source
     * leave empty for no custom repos
     */
    protected $_composerRepo = '';

    /**
     * @var string Amend horde dependency versions
     */
    protected $_composerVersion = '';

    protected $_vendor = '';

    protected $_gitRepoBase = '';
    /**
     * Check some well known locations, fallback to which
     *
     * @return string Fully qualified location of git command
     * @throws RuntimeException
     */
    public function detectComposerBin(): string
    {
        $candidates = [
            dirname(__FILE__, 3) . '/vendor/bin/composer',
            '/usr/bin/composer',
            '/usr/bin/composer2',
            '/usr/local/bin/composer',
            '/usr/local/bin/composer2',
            '/usr/local/bin/composer.phar',
        ];
        foreach ($candidates as $candidatePath) {
            if (file_exists($candidatePath)) {
                return realpath($candidatePath);
            }
        }
        throw new RuntimeException('Could not detect composer runtime');
    }

    /**
     * Make composer add a dependency, dev dependency or suggestion
     *
     * This will not install the package or check if it works on the development platform
     * This will not touch the horde metadata file.
     *
     * @param string $package
     * @return void
     */
    public function setDependency(string $packageDir, string $package, string $versionConstraint = '*', $type = 'require')
    {
        if ($type === 'require' || $type === 'requires') {
            $command = 'require';
        } elseif ($type === 'suggest' || $type === 'suggests' || $type === 'optional') {
            $command = 'suggests';
        } elseif ($type === 'dev' || $type === 'require-dev') {
            $command = 'require --dev';
        }
        $cmd = $this->detectComposerBin() . " $command --ignore-platform-reqs --no-install $package '$versionConstraint'";
        $this->execInDirectory($cmd, $packageDir);
    }

    public function setMinimumStability(string $packageDir, string $stability)
    {
        $cmd = $this->detectComposerBin() . " config minimum-stability $stability";
        $this->execInDirectory($cmd, $packageDir);
    }

    /**
     * Update the lock file and install dependencies
     *
     * Implicitly updates autoloader
     *
     * @return void
     */
    public function update(string $packageDir, string $constraint = '')
    {
        $cmd = $this->detectComposerBin() . ' update ' . $constraint;
        $this->execInDirectory($cmd, $packageDir);
    }

    /**
     * Updates the composer.json file.
     *
     * @param WrapperHordeYml $package  The package definition
     * @param array           $options  The set of options for the operation.
     *
     * @return string The composer.json file
     */
    public function generateComposerJson(WrapperHordeYml $package, array $options = []): string|bool
    {
        if (!empty($options['composer_opts']['pear-substitutes'])) {
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
            if (substr((string) $options['composer_repo'], 0, 6) == 'satis:') {
                $this->_composerRepo = 'composer';
                $this->_repositories['composer'] = [
                    'type' => 'composer',
                    'url' => substr((string) $options['composer_repo'], 6)
                ];
            }
        }
        // Override horde dependency versions
        if (!empty($options['composer_version'])) {
            $this->_composerVersion = $options['composer_version'];
        }

        $filename = dirname($package->getFullPath()) . '/composer.json';
        $composerDefinition = new \stdClass();
        /**
         * Allow setting a minimum stability.
         * Normally we would either want the default (empty, stable)
         * or delegate that setting to some baseplate (horde/horde-deployment)
         *
         * However, during a CI unit test it might make sense to deploy a temporary
         * composer.json which just accepts development dependencies.
         *
         */
        if (!empty($options['minimum-stability'])) {
            $composerDefinition->{'minimum-stability'} = $options['minimum-stability'];
        }

        $this->_setName($package, $composerDefinition);
        // Is this intentional? "description" seems always longer than full
        $composerDefinition->description = $package['full'];
        $this->_setType($package, $composerDefinition);
        $composerDefinition->homepage = $package['homepage'] ?? 'https://www.horde.org';
        $composerDefinition->license = $package['license']['identifier'];
        $this->_setAuthors($package, $composerDefinition);
        // cut off any -git or similar
        [$version] = explode('-', (string) $package['version']['release']);
        // Composer docs advise against writing the version tag to file
        // https://getcomposer.org/doc/04-schema.md#version
        // $composerDefinition->version = $version;
        $composerDefinition->time = $package['time'] ?? (new \Horde_Date(time()))->format('Y-m-d');
        $composerDefinition->repositories = [];
        $this->_setRequire($package, $composerDefinition);
        $this->_setDevRequire($package, $composerDefinition);
        $this->_setSuggest($package, $composerDefinition);
        $this->_setRepositories($package, $composerDefinition);
        $this->_setAutoload($package, $composerDefinition);
        $this->_setAutoloadDev($package, $composerDefinition);
        $this->_setVendorBin($package, $composerDefinition);
        $this->_setConfig($package, $composerDefinition);
        // Development dependencies?
        // Replaces ? Only needed for special cases. Default cases are handled implicitly
        // provides? apps can depend on provided APIs rather than other apps
        $this->_setProvides($package, $composerDefinition);

        // Enforce suggest to be a json object rather than array
        if (empty($composerDefinition->suggest)) {
            $composerDefinition->suggest = new \stdClass();
        }
        if (empty($composerDefinition->{'require-dev'})) {
            $composerDefinition->{'require-dev'} = new \stdClass();
        }
        $jsonDefinition = json_encode($composerDefinition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($filename, $jsonDefinition);

        if (isset($options['logger'])) {
            $options['logger']->ok(
                'Created composer.json file.'
            );
        }
        return $jsonDefinition;
    }

    /**
     * Turn a provides: block in yml into a composer.json provide: block
     */
    public function _setProvides(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        if (empty($package['provides'])) {
            return;
        }
        foreach ($package['provides'] as $impl => $version) {
            if (empty($composerDefinition->provide)) {
                $composerDefinition->provide = [];
            }
            $composerDefinition->provide[$impl] = $version;
        }
    }
    /**
     * Build a list of commands which should be exposed to vendor/bin.
     *
     * Default to all direct executable files of bindir
     * Otherwise use provided whitelist "commands"
     * and blacklist "nocommands" (blacklist wins)
     */
    protected function _setVendorBin(WrapperHordeYml $package, \stdClass $composerDefinition): void
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
            sort($commands);
            $composerDefinition->bin = array_values($commands);
        }
    }

    protected function _setName(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $vendor = $this->_vendor;
        $name = \Horde_String::lower($package['name']);
        $composerDefinition->name = "$vendor/$name";
    }

    protected function _setType(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        if ($package['type'] == 'library') {
            // Only use custom type horde-library if we have to
            // expose something under /web/
            $dir = dirname($package->getFullPath());
            if (is_dir($dir . '/js')) {
                $composerDefinition->type = 'horde-library';
            } else {
                $composerDefinition->type = 'library';
            }
        }
        if ($package['type'] == 'application') {
            $composerDefinition->type = 'horde-application';
        }
        if ($package['type'] == 'component') {
            $composerDefinition->type = 'horde-application';
        }
        if ($package['type'] == 'horde-theme') {
            $composerDefinition->type = 'horde-theme';
        }
        // No type is perfectly valid for composer. Types for bundles?
    }

    protected function _setAuthors(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $composerDefinition->authors = [];
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
    protected function _setAutoload(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $composerDefinition->autoload = [];

        $Psr0Name = $package['type'] == 'library' ? 'Horde_' . $package['name'] : $package['name'];
        /**
         * TODO: Support other vendor strings
         */
        $parts = explode('_', (string) $package['name']);
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
            // Autosense
            $dir = dirname($package->getFullPath());
            if (is_dir($dir . '/lib')) {
                $composerDefinition->autoload['psr-0']  = [$Psr0Name  => 'lib/'];
            }
            if (is_dir($dir . '/src')) {
                $composerDefinition->autoload['psr-4']  = [$Psr4Name  => 'src/'];
            }
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
    protected function _setAutoloadDev(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $composerDefinition->{'autoload-dev'} = [];
        $parts = explode('_', (string) $package['name']);
        $parts[] = 'Test';
        $Psr4Name = 'Horde\\';
        foreach ($parts as $part) {
            $Psr4Name .= ucfirst($part) . '\\';
        }
        if (!empty($package['autoload-dev'])) {
            foreach ($package['autoload'] as $type => $definition) {
                if ($type == 'classmap') {
                    $composerDefinition->{'autoload-dev'}['classmap']  =  $definition;
                }
                if ($type == 'psr-0') {
                    $composerDefinition->{'autoload-dev'}['psr-0']  =  $definition;
                }
                if ($type == 'psr-4') {
                    $composerDefinition->{'autoload-dev'}['psr-4']  =  $definition;
                }
            }
        } else {
            // Autosense
            $dir = dirname($package->getFullPath());
            if (is_dir($dir . '/test')) {
                $composerDefinition->{'autoload-dev'}['psr-4']  = [$Psr4Name  => 'test/'];
            }
        }
        // If still empty, make sure we use an object instead.
        $composerDefinition->{'autoload-dev'} = new stdClass;
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
    protected function _setRequire(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $version = ($this->_composerVersion) ? $this->_composerVersion . " || ^2" : '^2';
        // Only require the installer if we really need it
        if (!in_array($composerDefinition->type, ['library', 'project', 'application'])) {
            $composerDefinition->require = ['horde/horde-installer-plugin' => $version];
        }

        if (empty($package['dependencies']['required'])) {
            return;
        }
        foreach ($package['dependencies']['required'] as $element => $required) {
            if ($element == 'composer') {
                // composer dependencies which have no pear equivalent, i.e. unbundling
                foreach ($required as $dep => $version) {
                    if ($this->_composerVersion && substr((string) $dep, 0, 5) == 'horde') {
                        $composerDefinition->require[$dep] = "$version || $this->_composerVersion" ;
                    } else {
                        $composerDefinition->require[$dep] = "$version";
                    }
                    continue;
                }
            }
            if ($element == 'pear') {
                foreach ($required as $pear => $version) {
                    [$repo, $basename] = explode('/', (string) $pear);
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
                        } elseif (str_starts_with($basename, 'Horde_')) {
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
                    $repo = '';
                    $this->_handleVersion($version, $composerDefinition->require, 'ext', $repo, $ext);
                }
            }
        }
    }

    // Deal with packages appropriately
    protected function _handleVersion($version, &$stack, $type, $repo, $basename, $vendor = ''): void
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

    protected function _addPearRepo($pear): void
    {
        $repo = substr((string) $pear, 0, strrpos((string) $pear, '/'));
        $this->_repositories['pear-' . $repo] = ['url' => 'https://' . $repo, 'type' => 'pear'];
    }

    /**
     * Convert .horde.yml suggestions to composer format
     *
     * References to the horde pear channel will be changed to composer vcs/github
     */
    protected function _setSuggest(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $composerDefinition->suggest = [];
        if (empty($package['dependencies']['optional'])) {
            return;
        }
        foreach ($package['dependencies']['optional'] as $element => $suggested) {
            if ($element == 'composer') {
                // composer dependencies which have no pear equivalent, i.e. unbundling
                foreach ($suggested as $dep => $version) {
                    if ($this->_composerVersion && substr((string) $dep, 0, 5) == 'horde') {
                        $composerDefinition->suggest[$dep] = "$version || $this->_composerVersion" ;
                    } else {
                        $composerDefinition->suggest[$dep] = "$version";
                    }
                    continue;
                }
            }
            if ($element == 'pear') {
                foreach ($suggested as $pear => $version) {
                    [$repo, $basename] = \explode('/', (string) $pear);
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
                        } elseif (str_starts_with($basename, 'Horde_')) {
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


    /**
     * Handle .horde.yml dev dependencies
     *
     * No known pear equivalent, this is composer-only
     * We still require the composer: key for consistency with optional
     */
    protected function _setDevRequire(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $composerDefinition->{'require-dev'} = [];
        if (empty($package['dependencies']['dev'])) {
            return;
        }
        foreach ($package['dependencies']['dev'] as $element => $suggested) {
            if ($element == 'composer') {
                // composer dependencies which have no pear equivalent, i.e. unbundling
                foreach ($suggested as $dep => $version) {
                    if ($this->_composerVersion && substr((string) $dep, 0, 5) == 'horde') {
                        $composerDefinition->{'require-dev'}[$dep] = "$version || $this->_composerVersion" ;
                    } else {
                        $composerDefinition->{'require-dev'}[$dep] = "$version";
                    }
                    continue;
                }
            }
            if ($element == 'ext') {
                foreach ($suggested as $ext => $version) {
                    $repo = '';
                    $this->_handleVersion($version, $composerDefinition->{'require-dev'}, 'ext', $repo, $ext);
                }
            }
        }
    }


    // Handle the substitution list
    protected function _substitute($pear, $version, &$stack): bool
    {
        if (!empty($this->_substitutes[$pear])) {
            $stack[$this->_substitutes[$pear]['name']] = $version;
            if ($this->_substitutes[$pear]['source'] != 'Packagist') {
                throw new Exception("Non-Packagist substitutes not yet implemented:" . $this->_substitutes[$pear]['source']);
            }
            return true;
        }
        return false;
    }

    protected function _setRepositories(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $composerDefinition->repositories = array_values($this->_repositories);
    }

    protected function _setConfig(WrapperHordeYml $package, \stdClass $composerDefinition): void
    {
        $composerDefinition->config = [
            'allow-plugins' => [
                "horde/horde-installer-plugin" => true,
            ],
        ];
    }

    // Stub of a pretent method
    public function pretend(): bool
    {
        return false;
    }
}
