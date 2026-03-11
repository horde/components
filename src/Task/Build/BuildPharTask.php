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

namespace Horde\Components\Task\Build;

use Horde\Components\Helper\Shell as ShellHelper;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Exception;

/**
 * Build a PHAR archive using Box.
 *
 * Builds PHAR with static filename (e.g., 'horde-components.phar') that
 * overwrites existing local file. Skips if box.json.dist doesn't exist.
 *
 * Optional Options:
 * - phar_name (string) - Override PHAR filename
 *
 * Emitted Facts:
 * - phar.built (bool) - True if PHAR built
 * - phar.file_path (string) - Absolute path to PHAR
 * - phar.file_name (string) - PHAR filename
 * - phar.file_size (int) - PHAR size in bytes
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class BuildPharTask extends AbstractTask
{
    public function __construct(
        Output $output,
        private readonly ShellHelper $shellHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }

    public function shouldSkip(Context $context): bool
    {
        // Skip if no box.json.dist (not a PHAR-enabled component)
        $componentPath = $context->getComponentPath();
        return !file_exists($componentPath . '/box.json.dist');
    }


    public function getName(): string
    {
        return "Buil\1 \2har";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();
        $boxConfig = $componentPath . '/box.json.dist';

        // Validate box.json.dist exists
        if (!file_exists($boxConfig)) {
            throw new Exception('box.json.dist not found');
        }

        // Check box utility available
        $result = $this->shellHelper->run('which box', $componentPath);
        if ($result->getReturnValue() !== 0) {
            throw new Exception(
                'Box utility not found in PATH. Install with: composer global require humbug/box'
            );
        }

        // Determine PHAR filename
        $pharName = $context->getOption('phar_name');

        if ($pharName === null) {
            // Read from box.json.dist
            $boxJson = json_decode(file_get_contents($boxConfig), true);
            $pharName = basename($boxJson['output'] ?? 'dist.phar');
        }

        $pharPath = $componentPath . '/' . $pharName;

        // Build PHAR
        if (!$this->pretend) {
            $result = $this->shellHelper->run('box compile', $componentPath);

            if ($result->getReturnValue() !== 0) {
                throw new Exception('Failed to build PHAR: ' . $result->getOutputString());
            }

            // Verify PHAR was created
            if (!file_exists($pharPath)) {
                throw new Exception("PHAR not found after build: {$pharPath}");
            }

            // Quick sanity check: PHAR is valid
            $result = $this->shellHelper->run("php {$pharName} --version", $componentPath);
            if ($result->getReturnValue() !== 0) {
                throw new Exception('Built PHAR is not valid/executable');
            }
        }

        $pharSize = $this->pretend ? 0 : filesize($pharPath);

        // Emit facts
        $context->setFact('phar.built', true);
        $context->setFact('phar.file_path', $pharPath);
        $context->setFact('phar.file_name', $pharName);
        $context->setFact('phar.file_size', $pharSize);

        $action = $this->pretend ? 'Would build' : 'Built';
        $sizeInfo = $this->pretend ? '' : ' (' . $this->formatBytes($pharSize) . ')';

        return Result::success(
            "{$action} PHAR: {$pharName}{$sizeInfo}",
            [
                'phar_path' => $pharPath,
                'phar_name' => $pharName,
                'phar_size' => $pharSize,
            ]
        );
    }

    /**
     * Format bytes to human-readable size.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
