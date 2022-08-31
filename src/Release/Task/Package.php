<?php
/**
 * Components_Release_Task_Package:: prepares and uploads a release package.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Release\Task;

use Horde\Components\Exception;
use Horde\Components\Helper\Version as HelperVersion;

/**
 * Components_Release_Task_Package:: prepares and uploads a release package.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Package extends Base
{
    /**
     * Can the task be skipped?
     *
     * @param array $options Additional options.
     *
     * @return boolean True if it can be skipped.
     */
    public function skip($options): bool
    {
        return false;
    }

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     *
     * @throws Horde\Components\Exception
     */
    public function preValidate($options): array
    {
        $errors = [];
        $testpkg = \Horde_Util::getTempFile();
        $archive = new \Archive_Tar($testpkg, 'gz');
        $archive->addString('a', 'a');
        $archive->addString('b', 'b');
        $results = exec('tar tzvf ' . $testpkg . ' 2>&1');
        // MacOS tar doesn't error out, but only returns the first string (ending in 'a');
        if (str_contains($results, 'lone zero block') || substr($results, -1, 1) == 'a') {
            $errors[] = 'Broken Archive_Tar, upgrade first.';
        }

        if (empty($options['releaseserver'])) {
            $errors[] = 'The "releaseserver" option has no value. Where should the release be uploaded?';
        }
        if (empty($options['releasedir'])) {
            $errors[] = 'The "releasedir" option has no value. Where is the remote pirum install located?';
        }

        if ($errors) {
            return $errors;
        }

        $remote = new \Horde_Pear_Remote($options['releaseserver']);
        try {
            $exists = $remote->releaseExists(
                $this->getComponent()->getName(),
                $this->getComponent()->getVersion()
            );
            if ($exists) {
                $errors[] = sprintf(
                    'The remote server already has version "%s" for component "%s".',
                    $this->getComponent()->getVersion(),
                    $this->getComponent()->getName()
                );
            }
        } catch (\Horde_Http_Exception $e) {
            $errors[] = 'Failed accessing the remote PEAR server.';
        }
        try {
            HelperVersion::validateReleaseStability(
                $this->getComponent()->getVersion(),
                $this->getComponent()->getState('release')
            );
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
        try {
            HelperVersion::validateApiStability(
                $this->getComponent()->getVersion(),
                $this->getComponent()->getState('api')
            );
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
        return $errors;
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * throws Horde\Components\Exception
     */
    public function run(&$options): void
    {
        if (!$this->getTasks()->pretend()) {
            $archive_options = $options;
            $archive_options['keep_version'] = true;
            $archive_options['logger'] = $this->getOutput();
            $result = $this->getComponent()->placeArchive(getcwd(), $archive_options);
            if (isset($result[2])) {
                $this->getOutput()->pear($result[2]);
            }
            if (!empty($result[1])) {
                $this->getOutput()->fail(
                    'Generating package failed with:'. "\n\n" . join("\n", $result[1])
                );
                return;
            }
            $path = $result[0];
        } else {
            $path = '[PATH TO RESULTING]/[PACKAGE.TGZ - PRETEND MODE]';
            $this->getOutput()->info(
                sprintf(
                    'Would package %s now.',
                    $this->getComponent()->getName()
                )
            );
        }

        if (!empty($options['upload'])) {
            $this->system('scp ' . $path . ' ' . $options['releaseserver'] . ':~/');
            $this->system('ssh '. $options['releaseserver'] . ' "umask 0002 && pirum add ' . $options['releasedir'] . ' ~/' . basename((string) $path) . ' && rm ' . basename((string) $path) . '"');
            if (!$this->getTasks()->pretend()) {
                unlink($path);
            }
        }
    }
}
