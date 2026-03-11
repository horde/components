<?php

/**
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\Helper\Commit as HelperCommit;
use Horde\Components\Helper\Version as HelperVersion;
use Horde\Components\Output;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Component\Source;
use Horde_Pear_Exception;

/**
 * Components_Runner_Update updates the package files of a Horde component.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @author   Jan Schneider <jan@horde.org>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Update
{
    /**
     * Constructor.
     *
     * @param Component $component The component
     * @param array $arguments CLI arguments
     * @param array $options CLI options
     * @param Output $output The output handler
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $arguments,
        private readonly array $options,
        private readonly Output $output
    ) {}

    /**
     * @throws Exception
     * @throws Horde_Pear_Exception
     */
    public function run(): void
    {
        $options = array_merge(
            ['new_version' => false, 'new_api' => false, 'new_state' => false, 'new_apistate' => false, 'theme' => false],
            $this->options
        );

        if (!empty($options['updatexml'])
            || (isset($this->arguments[0]) && $this->arguments[0] == 'update')) {
            $action = !empty($options['action'])
                ? $options['action']
                : 'update';
            if (!empty($options['pretend']) && $action == 'update') {
                $action = 'diff';
            }
            $options['action'] = $action;
            if (!empty($options['commit'])) {
                $options['commit'] = new HelperCommit(
                    $this->output,
                    $options
                );
            }
            /** @var Source $component */
            $component = $this->component;
            if (!empty($options['new_version'])
                || !empty($options['new_api'])) {
                $result = $component->setVersion(
                    $options['new_version'],
                    $options['new_api'],
                    $options
                );
                if ($action != 'print' && $action != 'diff') {
                    $this->output->ok($result);
                }
                if (!empty($options['new_version'])
                    && !empty($options['sentinel'])) {
                    $notes = new ReleaseNotes($this->output);
                    $notes->setComponent($component);
                    $application_version
                        = HelperVersion::pearToHordeWithBranch(
                            $options['new_version'] . '-git',
                            $notes->getBranch()
                        );
                    $sentinel_result = $component->currentSentinel(
                        $options['new_version'] . '-git',
                        $application_version,
                        $options
                    );
                    foreach ($sentinel_result as $file) {
                        $this->output->ok($file);
                    }
                }
            }
            if (!empty($options['new_state'])
                || !empty($options['new_apistate'])) {
                $result = $component->setState(
                    $options['new_state'],
                    $options['new_apistate'],
                    $options
                );
                if ($action != 'print' && $action != 'diff') {
                    $this->output->ok($result);
                } else {
                    $this->output->info($result);
                }
            }
            $result = $component->updatePackage($action, $options);
            if (!empty($options['commit'])) {
                $options['commit']->commit(
                    'Components updated the package files.'
                );
            }
            if ($result === true) {
                $this->output->ok(
                    'Successfully updated package files of '
                    . $component->getName() . '.'
                );
            } else {
                $this->output->plain($result);
            }
        }
    }
}
