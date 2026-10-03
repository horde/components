<?php

/**
 * Components_Qc_Task_Lint:: runs a syntax check on the component.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Components_Qc_Task_Lint:: runs a syntax check on the component.
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
class Lint extends Base
{
    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName(): string
    {
        return 'syntax check';
    }

    /**
     * Top-level subdirectories of the component root that are excluded from linting.
     */
    private const EXCLUDED_DIRS = ['vendor', 'var'];

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return int Number of errors.
     */
    public function run(array &$options = []): int
    {
        $lib = realpath($this->getPath());

        // Prune excluded top-level directories before recursing into them.
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS),
            function (SplFileInfo $current) use ($lib): bool {
                $relative = substr($current->getPathname(), strlen($lib) + 1);
                $topDir   = explode(DIRECTORY_SEPARATOR, $relative)[0];
                return !in_array($topDir, self::EXCLUDED_DIRS, true);
            }
        );

        $errors = 0;
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $errors += $this->_lint($file->getPathname());
            }
        }
        return $errors;
    }

    private function _lint($file): bool
    {
        $command = 'php -l ' . escapeshellarg((string) $file);

        if (\DIRECTORY_SEPARATOR == '\\') {
            $command = '"' . $command . '"';
        }
        $output = shell_exec($command);
        if (str_contains($output, 'Errors parsing')) {
            $this->getOutput()->plain($output);
            return true;
        }
        return false;
    }
}
