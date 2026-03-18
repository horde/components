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

namespace Horde\Components\Task\GitHub;

use Horde\Components\Helper\GitHubReleaseCreator;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Exception;

/**
 * Upload file as asset to GitHub release.
 *
 * Generic task for uploading any file to a GitHub release. Performs
 * identity check (file size comparison) for retry safety.
 *
 * Required Facts:
 * - version.tag_name (string) - Git tag name (e.g., 'v2.0.0')
 *
 * Required Options (one of):
 * - file_path (string) - Path to file to upload
 * OR
 * - phar.file_path (string) - From BuildPharTask
 *
 * Optional Options:
 * - asset_name (string) - Override asset filename
 * - content_type (string) - MIME type (default: 'application/octet-stream')
 *
 * Emitted Facts:
 * - github.asset_uploaded (bool) - True if asset uploaded
 * - github.asset_id (int) - GitHub asset ID
 * - github.asset_url (string) - Browser download URL
 * - github.asset_name (string) - Asset filename
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class UploadGitHubAssetTask extends AbstractTask
{
    public function __construct(
        Output $output,
        private readonly GitHubReleaseCreator $githubReleaseCreator,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }

    public function shouldSkip(Context $context): bool
    {
        // Skip if no file to upload
        $filePath = $context->getOption('file_path')
            ?? $context->getFact('phar.file_path');

        return $filePath === null || !file_exists($filePath);
    }


    public function getName(): string
    {
        return "Upload GitHub Asset";
    }
    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();

        // Resolve file path
        $filePath = $context->getOption('file_path')
            ?? $context->getFact('phar.file_path');

        if ($filePath === null || !file_exists($filePath)) {
            throw new Exception('File to upload not found: ' . ($filePath ?? 'null'));
        }

        if (!is_readable($filePath)) {
            throw new Exception('File not readable: ' . $filePath);
        }

        // Resolve asset name
        $assetName = $context->getOption('asset_name')
            ?? $context->getFact('phar.file_name')
            ?? basename($filePath);

        // Get release info
        $tagName = $context->getFact('version.tag_name');

        if ($tagName === null) {
            throw new Exception(
                'version.tag_name fact not found. Run CalculateNextVersionTask first.'
            );
        }

        $releaseId = $context->getFact('github.release_id');

        if ($releaseId === null) {
            throw new Exception(
                'github.release_id fact not found. Run CreateGitHubReleaseTask first.'
            );
        }

        // Check if asset already exists
        $existingAsset = $this->githubReleaseCreator->getAssetByName(
            $componentPath,
            $tagName,
            $assetName
        );

        if ($existingAsset !== null) {
            // Asset exists - verify identity (size match)
            $localSize = filesize($filePath);

            if ($localSize === $existingAsset->size) {
                // Same size = probably same file (retry scenario)
                $context->setFact('github.asset_uploaded', false);
                $context->setFact('github.asset_id', $existingAsset->id);
                $context->setFact('github.asset_url', $existingAsset->browser_download_url);
                $context->setFact('github.asset_name', $assetName);

                return Result::skipped(
                    "GitHub asset already exists: {$assetName}",
                    [
                        'asset_id' => $existingAsset->id,
                        'asset_url' => $existingAsset->browser_download_url,
                        'asset_name' => $assetName,
                        'already_existed' => true,
                    ]
                );
            }

            // Different size = different file (error)
            throw new Exception(
                "GitHub asset '{$assetName}' exists with different size.\n"
                . "Local: {$localSize} bytes\n"
                . "Remote: {$existingAsset->size} bytes\n"
                . "Delete the existing release or asset before re-uploading."
            );
        }

        // Upload asset
        if ($this->pretend) {
            $fileSize = filesize($filePath);

            return Result::success(
                "Would upload {$assetName} (" . $this->formatBytes($fileSize) . ")",
                [
                    'asset_name' => $assetName,
                    'file_size' => $fileSize,
                    'pretend' => true,
                ]
            );
        }

        $contentType = $context->getOption('content_type') ?? 'application/octet-stream';

        $asset = $this->githubReleaseCreator->uploadAsset(
            localDir: $componentPath,
            releaseId: $releaseId,
            filePath: $filePath,
            assetName: $assetName,
            contentType: $contentType
        );

        // Emit facts
        $context->setFact('github.asset_uploaded', true);
        $context->setFact('github.asset_id', $asset->id);
        $context->setFact('github.asset_url', $asset->browser_download_url);
        $context->setFact('github.asset_name', $assetName);

        return Result::success(
            "Uploaded GitHub asset: {$asset->browser_download_url}",
            [
                'asset_id' => $asset->id,
                'asset_url' => $asset->browser_download_url,
                'asset_name' => $assetName,
                'asset_size' => $asset->size,
                'uploaded' => true,
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
