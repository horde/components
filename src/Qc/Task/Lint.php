<?php
/**
 * Components_Qc_Task_Lint:: runs a syntax check on the component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Qc\Task;

/**
 * Components_Qc_Task_Lint:: runs a syntax check on the component.
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
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return int Number of errors.
     */
    public function run(array &$options = []): int
    {
        $lib = realpath($this->_config->getPath());
        $recursion = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($lib)
        );
        $errors = 0;
        foreach ($recursion as $file) {
            if ($file->isFile() && preg_match('/.php$/', (string) $file->getFilename()) &&
            !str_starts_with((string) $file->getPathname(), $lib . DIRECTORY_SEPARATOR . 'vendor')
            ) {
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
