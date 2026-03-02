<?php

/**
 * Identifies the requested component based on an argument and delivers a
 * corresponding component instance.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Component;

use Horde\Components\Component;
use Horde\Components\Components;
use Horde\Components\ConfigProvider\ConfigProvider;
use Horde\Components\Exception;

/**
 * Identifies the requested component based on an argument and delivers a
 * corresponding component instance.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Identify
{
    /**
     * Constructor.
     *
     * @param ConfigProvider $configProvider The configuration provider.
     * @param array $actions The list of available actions.
     * @param Factory $componentFactory The component factory.
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
        private readonly array $actions,
        private readonly Factory $componentFactory
    ) {}

    /**
     * Identify component from CLI arguments.
     *
     * @param array &$arguments CLI arguments (modified by reference as args are consumed)
     * @param string $workingDir Current working directory
     * @return array{0: Component|null, 1: string|null, 2: array} [component, path, remaining args]
     */
    public function identifyComponent(array &$arguments, string $workingDir): array
    {
        [$component, $path] = $this->_determineComponent($arguments, $workingDir);

        if ($component && $path !== null) {
            // Resolve relative paths
            if (str_starts_with($path, './') || str_starts_with($path, '../')) {
                $path = realpath($workingDir . '/' . $path);
            }
            return [$component, $path, $arguments];
        }

        return [null, null, $arguments];
    }

    /**
     * Determine the requested component.
     *
     * @param array &$arguments The arguments (modified by reference)
     * @param string $workingDir Current working directory
     *
     * @return ?array Two elements: The selected component as
     *               Components_Component instance and optionally a string
     *               representing the path to the specified source component.
     */
    private function _determineComponent(array &$arguments, string $workingDir): ?array
    {
        if (isset($arguments[0])) {
            if (in_array($arguments[0], $this->actions['missing_argument'])) {
                return null;
            }

            if ($this->_isPackageXml($arguments[0]) || $this->_isHordeYml($arguments[0])) {
                $path = dirname($arguments[0]);
                array_shift($arguments);  // Consume argument
                return [$this->componentFactory->createSource($path), $path];
            }

            if (!in_array($arguments[0], $this->actions['list'])) {
                if ($this->_isDirectory($arguments[0])) {
                    $path = $arguments[0];
                    array_shift($arguments);  // Consume argument
                    return [$this->componentFactory->createSource($path), $path];
                }

                if ($this->configProvider->hasSetting('allow_remote')
                    && $this->configProvider->getSetting('allow_remote')) {
                    // Get all options for resolver
                    $options = [];
                    foreach ($this->configProvider->getAvailableKeys() as $key) {
                        if ($this->configProvider->hasSetting($key)) {
                            $options[$key] = $this->configProvider->getSetting($key);
                        }
                    }

                    $result = $this->componentFactory
                        ->getResolver()
                        ->resolveName(
                            $arguments[0],
                            'pear.horde.org',
                            $options
                        );
                    if ($result !== false) {
                        array_shift($arguments);  // Consume argument
                        return [$result, ''];
                    }
                }

                throw new Exception(
                    sprintf(Components::ERROR_NO_ACTION_OR_COMPONENT, $arguments[0])
                );
            }
        }

        // Try current working directory and parent directories
        $cwd = $workingDir;
        // Usability: check if we are in a subdir of a component
        do {
            if (
                $this->_isDirectory($cwd)
                && ($this->_containsPackageXml($cwd) || $this->_containsHordeYml($cwd))
            ) {
                return [$this->componentFactory->createSource($cwd), $cwd];
            }
            $cwd = dirname($cwd, 1);
        } while ($cwd != '/');
        if (!$this->_isDirectory($cwd)) {
            throw new Exception(Components::ERROR_NO_COMPONENT);
        }
        /**
         * Whitelist specific argument lists to run outside components
         *
         * Format is [
         *   ['whitlisted_module1', 'whitelistedarg2forthismodule'],
         *   ['whitelisted_module2']
         * ]
         * This feels a little hacky. Should we implement
         * a more generic "no component but valid" solution?
         */
        $whitelist = [
            ['init'],        // The init command creates new metadata
            ['git', 'clone'], // git clone must run on empty base dir
        ];
        foreach ($whitelist as $componentArgs) {
            foreach ($componentArgs as $argPos => $argValue) {
                if (empty($arguments[$argPos])) {
                    continue 2; // Next tuple
                }
                if ($arguments[$argPos] != $argValue) {
                    continue 2; // Next tuple
                }
            }
            // Tuple successfully checked
            return [$this->componentFactory->createSource($cwd), $cwd];
        }
        // Finally fail, all good options gone
        throw new Exception(Components::ERROR_NO_COMPONENT);
    }

    /**
     * Checks if the provided directory is a directory.
     *
     * @param string $path The path to the directory.
     *
     * @return bool True if it is a directory
     */
    private function _isDirectory($path)
    {
        return (!empty($path) && is_dir($path));
    }

    /**
     * Checks if the directory contains a package.xml file.
     *
     * @param string $path The path to the directory.
     *
     * @return bool True if the directory contains a package.xml file.
     */
    private function _containsPackageXml($path)
    {
        return file_exists($path . '/package.xml');
    }

    /**
     * Checks if the file name is a package.xml file.
     *
     * @param string $path The path.
     *
     * @return bool True if the provided file name points to a package.xml
     *                 file.
     */
    private function _isPackageXml($path)
    {
        if (basename($path) == 'package.xml' && file_exists($path)) {
            return true;
        }
        return false;
    }

    /**
     * Checks if the file name is a .horde.yml file.
     *
     * @param string $path The path.
     *
     * @return bool True if the provided file name points to a .horde.yml
     *                 file.
     */
    private function _isHordeYml($path)
    {
        if (basename($path) == '.horde.yml' && file_exists($path)) {
            return true;
        }
        return false;
    }

    /**
     * Checks if the directory contains a .horde.yml file.
     *
     * @param string $path The path to the directory.
     *
     * @return bool True if the directory contains the file.
     */
    private function _containsHordeYml($path)
    {
        return file_exists($path . '/.horde.yml');
    }
}
