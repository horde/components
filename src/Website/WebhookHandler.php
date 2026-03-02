<?php

/**
 * GitHub Webhook Handler for Horde Development
 *
 * Receives GitHub webhook events and stores them as JSON files for processing.
 * Uses value objects from Horde\GithubApiClient for type-safe payload handling.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Website;

use Horde\GithubApiClient\GithubRepository;
use Horde\GithubApiClient\GithubUser;
use RuntimeException;
use JsonException;
use Exception;

/**
 * GitHub Webhook Handler
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
class WebhookHandler
{
    // GitHub webhook event types we support
    private const SUPPORTED_EVENTS = [
        'push',
        'pull_request',
        'issues',
        'issue_comment',
        'release',
        'create',  // Branch/tag creation
        'delete',  // Branch/tag deletion
        'fork',
        'star',
        'watch',
        'pull_request_review',
        'pull_request_review_comment',
        'commit_comment',
        'check_run',
        'check_suite',
        'status',
        'deployment',
        'deployment_status',
        'gollum',  // Wiki
        'repository',  // Repository created, deleted, archived, etc.
    ];

    public function __construct(
        private string $dataRootDir,
        private ?string $webhookSecret = null
    ) {
        if (!is_dir($dataRootDir)) {
            throw new RuntimeException("Data directory does not exist: {$dataRootDir}");
        }
    }

    /**
     * Handle incoming webhook request
     *
     * @param string $json Raw JSON payload
     * @param array<string,string> $headers HTTP headers
     */
    public function handle(string $json, array $headers): void
    {
        try {
            $eventType = $headers['X-GitHub-Event'] ?? '';
            $signature = $headers['X-Hub-Signature-256'] ?? '';
            $delivery = $headers['X-GitHub-Delivery'] ?? '';

            // Verify webhook signature if secret is configured
            if ($this->webhookSecret !== null && !$this->verifySignature($json, $signature)) {
                $this->logError('Invalid webhook signature', $delivery);
                return;
            }

            if (empty($eventType)) {
                $this->logUnknown($json, 'No X-GitHub-Event header');
                return;
            }

            // Decode and validate payload
            $payload = json_decode($json, false, JSON_THROW_ON_ERROR);

            if (!isset($payload->repository)) {
                $this->logError("No repository in payload for event: {$eventType}", $delivery);
                return;
            }

            // Use value objects for type safety
            $repository = GithubRepository::fromApiArray((array) $payload->repository);
            $sender = isset($payload->sender)
                ? GithubUser::fromApiResponse($payload->sender)
                : null;

            // Store the event
            $this->storeEvent($eventType, $payload, $repository, $delivery);

            // Log success
            error_log(sprintf(
                "GitHub webhook: %s event for %s (delivery: %s)",
                $eventType,
                $repository->getFullName(),
                $delivery
            ));

        } catch (JsonException $e) {
            $this->logError("Invalid JSON: " . $e->getMessage(), '');
            $this->logRaw($json);
        } catch (Exception $e) {
            $this->logError("Handler error: " . $e->getMessage(), '');
            $this->logRaw($json);
        }
    }

    /**
     * Store webhook event to filesystem
     */
    private function storeEvent(
        string $eventType,
        object $payload,
        GithubRepository $repository,
        string $delivery
    ): void {
        // Build directory path: {dataRoot}/{eventType}/{action}/{repo}
        $action = $payload->action ?? 'other';
        $repoName = $repository->getFullName();

        $targetDir = sprintf(
            '%s/%s/%s/%s',
            $this->dataRootDir,
            $eventType,
            $action,
            $repoName
        );

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }

        // Use delivery ID + timestamp for idempotent storage
        // GitHub sends same delivery ID on redelivery
        $timestamp = $payload->created_at
            ?? $payload->updated_at
            ?? date('Y-m-d\TH:i:s\Z');

        $filename = sprintf(
            '%s/%s_%s.json',
            $targetDir,
            $delivery ?: date('YmdHis'),
            preg_replace('/[^0-9]/', '', $timestamp)
        );

        // Don't overwrite if delivery ID already exists (idempotent)
        if (!file_exists($filename)) {
            file_put_contents($filename, json_encode($payload, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Verify GitHub webhook signature
     */
    private function verifySignature(string $payload, string $signature): bool
    {
        if ($this->webhookSecret === null || empty($signature)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Log unknown event
     */
    private function logUnknown(string $json, string $reason): void
    {
        $logFile = $this->dataRootDir . '/received.unknown.log';
        $entry = sprintf(
            "[%s] %s\n%s\n---\n",
            date('Y-m-d H:i:s'),
            $reason,
            $json
        );
        file_put_contents($logFile, $entry, FILE_APPEND);
    }

    /**
     * Log error
     */
    private function logError(string $message, string $delivery): void
    {
        $logFile = $this->dataRootDir . '/received.error.log';
        $entry = sprintf(
            "[%s] Delivery: %s, Error: %s\n",
            date('Y-m-d H:i:s'),
            $delivery,
            $message
        );
        file_put_contents($logFile, $entry, FILE_APPEND);
        error_log("GitHub webhook error: {$message}");
    }

    /**
     * Log raw payload for debugging
     */
    private function logRaw(string $json): void
    {
        $logFile = $this->dataRootDir . '/received.raw.log';
        $entry = sprintf(
            "[%s]\n%s\n---\n",
            date('Y-m-d H:i:s'),
            $json
        );
        file_put_contents($logFile, $entry, FILE_APPEND);
    }

    /**
     * Send HTTP response
     */
    public function sendResponse(int $statusCode = 200, string $message = 'ok'): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
            echo json_encode(['status' => $message]);
        }
    }
}
