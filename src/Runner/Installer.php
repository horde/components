<?php

/**
 * Components_Runner_Installer:: installs a Horde component including its
 * dependencies.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\Exception;
use Horde\Components\Exception\Pear as ExceptionPear;
use Horde\Components\Helper\Installer as HelperInstaller;
use Horde\Components\Output;
use Horde\Components\Pear\Factory as PearFactory;

/**
 * Components_Runner_Installer:: installs a Horde component including its
 * dependencies.
 *
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
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
    * Constructor.
    *
     * @param Component $component The component
     * @param array $options CLI options
     * @param HelperInstaller $installer The install helper
     * @param PearFactory $factory The factory for PEAR dependencies
     * @param Output $output The output handler
    */
    public function __construct(
        private readonly Component $component,
        private readonly array $options,
        private readonly HelperInstaller $installer,
        private readonly PearFactory $factory,
        private readonly Output $output
    ) {}

    public function run(): void
    {
        $options = $this->options;
        if (!empty($options['destination'])) {
            $environment = realpath($options['destination']);
            if (!$environment) {
                $environment = $options['destination'];
            }
        } else {
            throw new Exception('You MUST specify the path to the installation environment with the --destination flag!');
        }

        if (empty($options['pearrc'])) {
            $options['pearrc'] = $environment . '/pear.conf';
            $this->output->info(
                sprintf(
                    'Undefined path to PEAR configuration file (--pearrc). Assuming %s for this installation.',
                    $options['pearrc']
                )
            );
        }

        if (empty($options['horde_dir'])) {
            $options['horde_dir'] = $environment;
            $this->output->info(
                sprintf(
                    'Undefined path to horde web root (--horde-dir). Assuming %s for this installation.',
                    $options['horde_dir']
                )
            );
        }

        if (!empty($options['instructions'])) {
            if (!file_exists($options['instructions'])) {
                throw new Exception(
                    sprintf(
                        'Instructions file "%s" is missing!',
                        $options['instructions']
                    )
                );
            }
            $lines = explode("\n", file_get_contents($options['instructions']));
            $result = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (empty($trimmed) || preg_match('/^#/', $trimmed)) {
                    continue;
                }
                preg_match('/(.*):(.*)/', $trimmed, $matches);
                $id = $matches[1];
                $c_options = $matches[2];
                foreach (explode(',', (string) $c_options) as $option) {
                    $result[trim((string) $id)][trim($option)] = true;
                }
            }
            $options['instructions'] = $result;
        }

        $target = $this->factory->createEnvironment(
            $environment,
            $options['pearrc']
        );
        $target->setResourceDirectories($options);

        //@todo: fix role handling
        $target->provideChannel('pear.horde.org', $options);
        $target->getPearConfig()->setChannels(['pear.horde.org', true]);
        $target->getPearConfig()->set('horde_dir', $options['horde_dir'], 'user', 'pear.horde.org');
        ExceptionPear::catchError($target->getPearConfig()->store());
        $this->installer->installTree(
            $target,
            $this->component,
            $options
        );
    }
}
