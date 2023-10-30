<?php
/**
 * Components_Helper_Installer:: provides an installation helper.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Helper;

use Horde\Components\Component;
use Horde\Components\Component\Archive;
use Horde\Components\Component\Match;
use Horde\Components\Component\Matcher;
use Horde\Components\Component\Remote;
use Horde\Components\Component\Source;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Pear\Environment as PearEnvironment;

/**
 * Components_Helper_Installer:: provides an installation helper.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Installer
{
    /**
     * The environment the helper installs into.
     *
     * @var PearEnvironment
     */
    private $_environment;

    /**
     * The output handler.
     *
     * @param Output
     */
    private $_output;

    /**
     * The list of components already installed.
     *
     * @var array
     */
    private $_installed_components = [];

    /**
     * Per component options.
     *
     * @var array
     */
    private $_per_component_options = [];

    /**
     * Constructor.
     *
     * @param Output $output The output handler.
     */
    public function __construct(Output $output)
    {
        $this->_output = $output;
    }

    /**
     * Install a component with its dependencies into the environment.
     *
     * @param PearEnvironment     $environment The environment we
     *                                                     install into.
     * @param Component            $component   The component that
     *                                                     should be installed.
     * @param array                           $options     Install options.
     * @param string                          $reason      Optional reason for
     *                                                     adding the package.
     *
     * @return void
     */
    public function installTree(
        PearEnvironment $environment,
        Component $component,
        $options = [],
        $reason = ''
    ) {
        $key = $component->getChannel() . '/' . $component->getName();
        if (!in_array($key, $this->_installed_components)) {
            $this->_installed_components[] = $key;
            if (empty($options['nodeps'])) {
                $this->_installDependencies(
                    $environment,
                    $component,
                    $options,
                    $reason
                );
            }
            $this->_installComponent($environment, $component, $options, $reason);
        }
    }

    /**
     * Install the dependencies of a component.
     *
     * @param PearEnvironment     $environment The environment we
     *                                                     install into.
     * @param Component            $component   The component that
     *                                                     should be installed.
     * @param array                           $options     Install options.
     * @param string                          $reason      Optional reason for
     *                                                     adding the package.
     *
     * @return void
     */
    private function _installDependencies(
        PearEnvironment $environment,
        Component $component,
        $options = [],
        $reason = ''
    ) {
        foreach ($component->getDependencyList() as $dependency) {
            if (!$dependency->isPhp() && $dependency->isPackage()) {
                $c_options = $this->_getPerComponentOptions(
                    $dependency,
                    $options
                );
                if ($dependency->isRequired() ||
                    !empty($c_options['include'])) {
                    $dep = $dependency->getComponent($c_options);
                    if (!($dep instanceof Archive) &&
                        !empty($options['build_distribution'])) {
                        if (empty($options['allow_remote']) &&
                            !($component instanceof Source)) {
                            throw new Exception(
                                sprintf(
                                    'Cannot add component "%s". Remote access has been disabled (activate with --allow-remote)!',
                                    $component->getName()
                                )
                            );
                        }
                        if (!empty($options['sourcepath'])) {
                            $source = $options['sourcepath'] . '/'
                                . $component->getChannel();
                            if (!file_exists($source)) {
                                @mkdir(dirname($source), 0777, true);
                            }
                            if ($dep instanceof Source) {
                                $environment->provideChannel(
                                    $dep->getChannel(),
                                    $options,
                                    sprintf(' [required by %s]', $dep->getName())
                                );
                            }
                            $dep->placeArchive($source);
                            if ($dep instanceof Remote) {
                                $this->_output->warn(
                                    sprintf(
                                        'Downloaded component %s via network to %s.',
                                        $dep->getName(),
                                        $source
                                    )
                                );
                            } else {
                                $this->_output->ok(
                                    sprintf(
                                        'Generated archive for component %s in %s.',
                                        $dep->getName(),
                                        $source
                                    )
                                );
                            }
                            $dep = $dependency->getComponent($c_options);
                        }
                    }
                    if ($dep === false) {
                        throw new Exception(
                            sprintf(
                                'Failed resolving component %s/%s!',
                                $dependency->getChannel(),
                                $dependency->getName()
                            )
                        );
                    } else {
                        $this->installTree(
                            $environment,
                            $dep,
                            $options,
                            sprintf(
                                ' [required by %s]',
                                $component->getName()
                            )
                        );
                    }
                }
            }
        }
    }
    /**
     * Ensure that the component is available within the installation
     * environment.
     *
     * @param PearEnvironment $environment The environment we
     *                                                 install into.
     * @param Component        $component   The component that
     *                                                 should be installed.
     * @param array                       $options     Install options.
     * @param string                      $reason      Optional reason for
     *                                                 adding the package.
     *
     * @return void
     */
    private function _installComponent(
        PearEnvironment $environment,
        Component $component,
        $options = [],
        $reason = ''
    ) {
        if (empty($options['pretend'])) {
            $component->install(
                $environment,
                $this->_getPerComponentOptions(
                    $component,
                    $options
                ),
                $reason
            );
        } else {
            $this->_output->ok(
                sprintf(
                    'Would install component %s%s.',
                    $component->getName(),
                    $reason
                )
            );
        }
    }

    /**
     * Identify the per component options.
     *
     * @param mixed $component The component.
     * @param array $options   The global options.
     *
     * @return array The specific options for the component.
     */
    private function _getPerComponentOptions($component, $options)
    {
        $channel = $component->getChannel();
        $name = $component->getName();
        $key = $channel . '/' . $name;
        if (!isset($this->_per_component_options[$key])) {
            $this->_per_component_options[$key] = $options;
            if (isset($options['instructions'])) {
                foreach ($options['instructions'] as $id => $c_options) {
                    if (Matcher::matches($name, $channel, $id)) {
                        $this->_deletePrevious(
                            $key,
                            $c_options,
                            ['include', 'exclude']
                        );
                        $this->_deletePrevious(
                            $key,
                            $c_options,
                            [
                                'git',
                                'snapshot',
                                'stable',
                                'beta',
                                'alpha',
                                'devel'
                            ]
                        );
                        $this->_per_component_options[$key] = array_merge(
                            $this->_per_component_options[$key],
                            $c_options
                        );
                    }
                }
            }
        }
        return $this->_per_component_options[$key];
    }

    private function _deletePrevious($key, $options, $previous)
    {
        foreach ($previous as $entry) {
            if (!empty($options[$entry])) {
                foreach ($previous as $delete) {
                    unset($this->_per_component_options[$key][$delete]);
                }
            }
        }
    }
}
