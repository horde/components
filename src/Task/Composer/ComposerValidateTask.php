<?php

/**
 * Copyright 2024-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author   Ralf Lang <lang@b1-systems.de>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Components
 */

declare(strict_types=1);

namespace Horde\Components\Task\Composer;

use Horde\Components\Helper\Shell as ShellHelper;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Exception;

/**
 * Run composer validate on composer.json.
 *
 * Validates that composer.json is well-formed and contains required fields.
 *
 * Emitted Facts:
 * - composer.validated (bool) - True if validation completed
 * - composer.valid (bool) - True if composer.json is valid
 * - composer.validation_errors (array) - List of validation errors (if any)
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class ComposerValidateTask extends AbstractTask
{
    public function __construct(
        Output $output,
        private readonly ShellHelper $shellHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }


    public function getName(): string
    {
        return "Composer Validate";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();
        $composerJsonPath = $componentPath . '/composer.json';
        $composerLockPath = $componentPath . '/composer.lock';

        // Check composer.json exists
        if (!file_exists($composerJsonPath)) {
            throw new Exception("composer.json not found: {$composerJsonPath}");
        }

        // Delete composer.lock if it exists (will be out of sync after updating composer.json)
        if (file_exists($composerLockPath)) {
            if (!$this->pretend) {
                unlink($composerLockPath);
                $this->output->info('Deleted composer.lock (will be regenerated on next composer install)');
            } else {
                $this->output->info('Would delete composer.lock');
            }
        }

        // Run composer validate
        $result = $this->shellHelper->run(
            'composer validate --no-check-publish --no-check-all',
            $componentPath
        );

        $isValid = $result->getReturnValue() === 0;
        $errors = [];

        if (!$isValid) {
            // Parse errors from output
            $output = $result->getOutputString();
            $lines = explode("\n", $output);

            foreach ($lines as $line) {
                if (str_contains($line, 'error') || str_contains($line, 'warning')) {
                    $errors[] = trim($line);
                }
            }
        }

        // Emit facts
        $context->setFact('composer.validated', true);
        $context->setFact('composer.valid', $isValid);
        $context->setFact('composer.validation_errors', $errors);

        if ($isValid) {
            return Result::success('composer.json is valid', [
                'valid' => true,
                'errors' => [],
            ]);
        }

        return Result::failure(
            'composer.json validation failed: ' . implode(', ', $errors),
            count($errors)
        );
    }
}
