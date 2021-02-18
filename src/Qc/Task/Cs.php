<?php
/**
 * Components_Qc_Task_Cs:: runs a code style check on the component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Qc\Task;
use Horde\Components\Constants;
use PHP_CodeSniffer\Autoload;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\FileList;
use PHP_CodeSniffer\Reporter;
use PHP_CodeSniffer\Ruleset;

/**
 * Components_Qc_Task_Cs:: runs a code style check on the component.
 *
 * Copyright 2011-2021 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Cs extends Base
{
    /**
     * Get the name of this task.
     *
     * @return string The task name.
     */
    public function getName()
    {
        return 'code style check';
    }

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function validate(array $options = []): array
    {
        if (!class_exists('PHP_CodeSniffer\\Autoload')) {
            return ['PHP CodeSniffer is not available!'];
        }
        return [];
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return integer Number of errors.
     */
    public function run(array &$options = [])
    {
        $lib_dir = realpath($this->_config->getPath() . '/lib');

        $cli_args = [
            '--basepath=' . $lib_dir,
            '--report=emacs',
            '--standard=' . Constants::getDataDirectory() . '/qc_standards/phpcs.xml',
            $lib_dir
        ];

        define('PHP_CODESNIFFER_CBF', false);
        define('PHP_CODESNIFFER_VERBOSITY', false);

        $config = new Config($cli_args);
        $reporter = new Reporter($config);
        $ruleset = new Ruleset($config);

        $file_list = new FileList($config, $ruleset);

        foreach ($file_list as $path => $file) {
            $file->process();
            $reporter->cacheFileReport($file, $config);
        }
        echo $reporter->printReport('emacs');
        return $reporter->totalErrors;
    }
}