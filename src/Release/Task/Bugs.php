<?php
/**
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
namespace Horde\Components\Release\Task;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Helper\Version as HelperVersion;

/**
 * Components_Release_Task_Bugs adds the new release to the issue tracker.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Bugs extends Base
{
    /**
     * Queue id.
     *
     * @var string|boolean
     */
    private $_qid;

    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options)
    {
        $errors = array();
        if (empty($options['horde_user'])) {
            $errors[] = 'The "horde_user" option has no value. Who is updating bugs.horde.org?';
        }
        if (empty($options['horde_pass'])) {
            $errors[] = 'The "horde_pass" option has no value. What is your password for updating bugs.horde.org?';
        }
        if (!class_exists('Horde_Release_Whups')) {
            $errors[] = 'The \Horde_Release package is missing (specifically the class \Horde_Release_Whups)!';
        }
        try {
            $this->_qid = $this->_getBugs($options)
                ->getQueueId($this->getComponent()->getName());
        } catch (\Horde_Exception $e) {
            $errors[] = sprintf(
                'Failed accessing bugs.horde.org: %s', $e->getMessage()
            );
        }
        if (!$this->_qid) {
            $errors[] = 'No queue on bugs.horde.org available. The new version will not be added to the bug tracker!';
        }
        return $errors;
    }

    /**
     * Return the handler for bugs.horde.org.
     *
     * @param array $options Additional options.
     *
     * @return void
     */
    public function _getBugs($options)
    {
        if (!isset($options['horde_user']) || !isset($options['horde_user'])) {
            throw new Exception('Missing credentials!');
        }
        return new \Horde_Release_Whups(
            array(
                'client' => new \Horde_Http_Client(
                    array(
                        'request.username' => $options['horde_user'],
                        'request.password' => $options['horde_pass'],
                        'request.timeout' => 10
                    )
                ),
                'url' => 'https://dev.horde.org/horde/rpc.php',
            )
        );
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     *
     * @return void
     */
    public function run(&$options)
    {
        if (!$this->_qid) {
            $this->getOutput()->warn(
                'No queue on bugs.horde.org available. The new version will not be added to the bug tracker!'
            );
            return;
        }

        $ticket_version = $this->getComponent()->getVersion();

        $ticket_description = HelperVersion::pearToTicketDescription(
            $this->getComponent()->getVersion()
        );
        $branch = $this->getNotes()->getBranch();
        if (!empty($branch)) {
            $ticket_description = $branch
                . preg_replace('/([^ ]+) (.*)/', ' (\1) \2', $ticket_description);
        }
        $ticket_description = $this->getNotes()->getName() . ' ' . $ticket_description;

        if (!$this->getTasks()->pretend()) {
            try {
                $this->_getBugs($options)->addNewVersion(
                    $this->getComponent()->getName(),
                    $ticket_version,
                    $ticket_description
                );
            } catch (\Horde_Exception $e) {
                $this->getOutput()->warn('Cannot update version on bugs.horde.org.');
                $this->getOutput()->warn($e->getMessage());
            }
        } else {
            $this->getOutput()->info(
                sprintf(
                    'Would add new version "%s: %s" to queue "%s".',
                    $ticket_version,
                    $ticket_description,
                    $this->getComponent()->getName()
                )
            );
        }
    }
}