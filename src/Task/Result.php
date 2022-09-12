<?php
/**
 * ResultInterface - Output of a CLI task.
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Task;

use Stringable;
use ValueError;

/**
 * ResultInterface - Output of a CLI task.
 *
 * Copyright 2011-2022 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * Inspired by PSR-7 ResponseInterface
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Result implements ResultInterface
{
    public function __construct(
        private bool $success = true,
        private int $returnCode = 0,
        private string $message = 'ok',
        private array $details = []
    ) {
        // Ensure we fail on putting the wrong things in
        foreach ($details as $detail) {
            if (!is_string($detail) && !($detail instanceof Stringable)) {
                throw new ValueError('Passed details must be of type string or Stringable');
            }
        }
    }
    /**
     * Overall evaluation of outcome
     *
     * @return bool
     */
     public function succeeded(): bool
     {
         return $this->success;
     }

     /**
      * Provide a machine-friendly return code
      *
      * @return integer
      */
     public function getReturnCode(): int
     {
         return $this->returnCode;
     }

     /**
      * Get a concise human-readable outcome
      *
      * @return string
      */
     public function getReturnMessage(): string
     {
         return $this->message;
     }

     /**
      * Offer a list of notable insights to the run.
      *
      * @return array<Stringable|string>
      */
     public function getDetails(): array
     {
         return $this->details;
     }
}
