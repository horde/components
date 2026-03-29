<?php

/**
 * Components_Qc_Task_Hordeyml:: checks .horde.yml for quality issues.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Qc\Task;

use Horde\HordeYmlFile\HordeYmlFile;

/**
 * Components_Qc_Task_Hordeyml:: checks .horde.yml for quality issues.
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Hordeyml extends Base
{
    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string
    {
        return '.horde.yml quality check';
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return int Number of errors.
     */
    public function run(array &$options = []): int
    {
        $componentPath = $this->getPath();

        if (empty($componentPath)) {
            $componentPath = getcwd();
        }

        $hordeYmlPath = $componentPath . '/.horde.yml';

        if (!file_exists($hordeYmlPath)) {
            $this->getOutput()->error('No .horde.yml file found');
            return 1;
        }

        $this->getOutput()->info('Checking .horde.yml at: ' . $hordeYmlPath);

        $issues = 0;

        // Load .horde.yml
        try {
            $yml = new HordeYmlFile($hordeYmlPath);
        } catch (\Exception $e) {
            $this->getOutput()->error('Failed to load .horde.yml: ' . $e->getMessage());
            return 1;
        }

        // Check for keywords field
        $keywords = $yml->getKeywords();
        if (empty($keywords)) {
            $this->getOutput()->warn('Missing or empty keywords field');
            $this->getOutput()->info('  Keywords improve discoverability on Packagist');
            $issues++;

            // Fix if requested
            if (!empty($options['fix_qc_issues'])) {
                return $this->addEmptyKeywordsField($yml);
            }
        } else {
            $this->getOutput()->ok('Keywords field present with ' . count($keywords) . ' keyword(s)');
        }

        return $issues;
    }

    /**
     * Add empty keywords field to .horde.yml.
     *
     * @param HordeYmlFile $yml The HordeYmlFile instance.
     *
     * @return int Number of errors (0 on success).
     */
    private function addEmptyKeywordsField(HordeYmlFile $yml): int
    {
        try {
            $yml->setKeywords([]);
            $yml->save();
            $this->getOutput()->ok('Added empty keywords field to .horde.yml');
            $this->getOutput()->info('  Please add relevant keywords for better Packagist discoverability');
            return 0;
        } catch (\Exception $e) {
            $this->getOutput()->error('Failed to update .horde.yml: ' . $e->getMessage());
            return 1;
        }
    }
}
