<?php
/**
 * Components_Release_Task_Announce:: announces new releases to the mailing
 * lists.
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

/**
 * Components_Release_Task_Announce:: announces new releases to the mailing
 * lists.
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
class Announce extends Base
{
    /**
     * Validate the preconditions required for this release task.
     *
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options): array
    {
        $errors = [];
        if (!$this->getNotes()->hasNotes()) {
            $errors[] = 'No release announcements available! No information will be sent to the mailing lists.';
        }
        if (empty($options['from'])) {
            $errors[] = 'The "from" option has no value. Who is sending the announcements?';
        }
        if (!class_exists(\Horde_Release_MailingList::class)) {
            $errors[] = 'The \Horde_Release package is missing (specifically the class \Horde_Release_MailingList)!';
        }
        return $errors;
    }

    /**
     * Run the task.
     *
     * @param array &$options Additional options.
     */
    public function run(&$options): void
    {
        if (!$this->getNotes()->hasNotes()) {
            $this->getOutput()->warn(
                'No release announcements available! No information will be sent to the mailing lists.'
            );
            return;
        }

        $mailer = new \Horde_Release_MailingList(
            $this->getComponent()->getName(),
            $this->getNotes()->getName(),
            $this->getNotes()->getBranch(),
            $options['from'],
            $this->getNotes()->getList(),
            $this->getComponent()->getVersion(),
            $this->getNotes()->getSecurity()
        );
        $mailer->append($this->getNotes()->getAnnouncement());
        $mailer->append(
            "\n\n" .
            'The full list of changes can be viewed here:' .
            "\n\n" .
            $this->getComponent()->getChangelogLink() .
            "\n\n" .
            'Have fun!' .
            "\n\n" .
            'The Horde Team.'
        );

        if (!$this->getTasks()->pretend()) {
            try {
                //@todo: Make configurable again
                $class = \Horde_Mail_Transport_Sendmail::class;
                $mailer->getMail()->send(new $class([]));
            } catch (Exception $e) {
                $this->getOutput()->warn((string)$e);
            }
        } else {
            if (!empty($options['dump'])) {
                $this->getOutput()->plain($mailer->getBody());
                return;
            }

            $info = 'ANNOUNCEMENT

Message headers
---------------

';
            foreach ($mailer->getHeaders() as $key => $value) {
                $info .= $key . ': ' . $value . "\n";
            }
            $info .= '
Message body
------------

';
            $info .= $mailer->getBody();

            $this->getOutput()->info($info);
        }
    }
}
