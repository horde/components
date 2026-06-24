<?php

/**
 * Copyright 2024-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Components
 */

declare(strict_types=1);

namespace Horde\Components\Task\Release;

use Horde\Components\Ci\Template\TemplateLocator;
use Horde\Components\Ci\Template\TemplateRenderer;
use Horde\Components\Ci\Template\TemplateVersion;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;

/**
 * Refresh CI bootstrap script and GitHub Actions workflow when outdated.
 *
 * The generated CI files (bin/ci-bootstrap.sh and .github/workflows/ci.yml)
 * are produced from versioned templates that embed a `# Template version:`
 * marker. They also embed a generation timestamp, so a plain diff is
 * never empty even when the substantive content has not changed. To
 * decide whether to refresh, this task ignores the file contents and
 * compares the embedded template version against the version baked into
 * {@see TemplateRenderer}.
 *
 * Files are only refreshed when they already exist locally — bootstrapping
 * a component for the first time is the job of `horde-components ci init`,
 * not the release pipeline. Refreshed files are appended to the `files`
 * commit list so they ride along in the release commit.
 *
 * Emitted Facts:
 * - ci.refreshed (array<string>) — relative paths of files that were refreshed
 * - ci.refreshed_count (int) — number of files refreshed
 *
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @category  Horde
 * @copyright 2024-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Components
 */
class RefreshCiBootstrapTask extends AbstractTask
{
    /**
     * Default targets. The bootstrap script's template is resolved at run
     * time per file because GitHub and local modes use different scripts
     * but share an outcome path; the workflow only exists in GitHub mode.
     */
    private const TARGETS = [
        'bin/ci-bootstrap.sh' => null,
        '.github/workflows/ci.yml' => 'workflow.yml',
    ];

    public function __construct(
        Output $output,
        private readonly ?TemplateRenderer $renderer = null,
        bool $pretend = false,
    ) {
        parent::__construct($output, $pretend);
    }

    public function getName(): string
    {
        return 'Refresh CI bootstrap';
    }

    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();
        $renderer = $this->renderer ?? new TemplateRenderer(TemplateLocator::getTemplateDir());

        $refreshed = [];
        $skippedMissing = [];
        $skippedCurrent = [];

        foreach (self::TARGETS as $relativePath => $templateName) {
            $absolutePath = $componentPath . '/' . $relativePath;

            // Only refresh files that are already present. The release
            // pipeline must not silently introduce CI configuration to
            // a component that never had any — that is `ci init`'s job.
            if (!file_exists($absolutePath)) {
                $skippedMissing[] = $relativePath;
                continue;
            }

            // The bootstrap script has two flavours; resolve the template
            // name from the existing file's mode marker so a local-mode
            // component does not get a GitHub-mode rewrite.
            if ($templateName === null) {
                $templateName = $this->detectBootstrapTemplate($absolutePath);
                if ($templateName === null) {
                    $this->output->warn(sprintf(
                        'Cannot determine CI mode for %s; skipping refresh',
                        $relativePath,
                    ));
                    $skippedMissing[] = $relativePath;
                    continue;
                }
            }

            if (!TemplateVersion::isOutdated($absolutePath)) {
                $comparison = TemplateVersion::compare($absolutePath);
                $skippedCurrent[] = sprintf(
                    '%s (v%s)',
                    $relativePath,
                    $comparison['file'] ?? 'unknown',
                );
                continue;
            }

            $comparison = TemplateVersion::compare($absolutePath);
            $fileVersion = $comparison['file'] ?? 'unknown';
            $currentVersion = $comparison['current'];

            if ($this->pretend) {
                $this->output->info(sprintf(
                    '[PRETEND] Would refresh %s (v%s -> v%s)',
                    $relativePath,
                    $fileVersion,
                    $currentVersion,
                ));
                $refreshed[] = $relativePath;
                continue;
            }

            $config = $this->buildConfig($relativePath, $componentPath, $templateName);
            $content = $renderer->render($templateName, $config);

            $dir = dirname($absolutePath);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                return Result::failure(sprintf(
                    'Failed to create directory for %s',
                    $relativePath,
                ));
            }

            if (file_put_contents($absolutePath, $content) === false) {
                return Result::failure(sprintf(
                    'Failed to write %s',
                    $relativePath,
                ));
            }

            // Bootstrap script must remain executable.
            if (str_ends_with($relativePath, '.sh')) {
                @chmod($absolutePath, 0o755);
            }

            $this->output->info(sprintf(
                'Refreshed %s (v%s -> v%s)',
                $relativePath,
                $fileVersion,
                $currentVersion,
            ));

            $refreshed[] = $relativePath;
        }

        // Make refreshed files part of the release commit. CommitTask reads
        // the 'files' option as the staging list — see Task/Git/CommitTask.
        if (!empty($refreshed) && !$this->pretend) {
            $existing = $context->getOption('files');
            $files = is_array($existing) ? $existing : [];
            foreach ($refreshed as $path) {
                if (!in_array($path, $files, true)) {
                    $files[] = $path;
                }
            }
            $context->setOption('files', $files);
        }

        $context->setFact('ci.refreshed', $refreshed);
        $context->setFact('ci.refreshed_count', count($refreshed));

        if (empty($refreshed)) {
            if (!empty($skippedCurrent)) {
                $message = 'CI bootstrap up to date: ' . implode(', ', $skippedCurrent);
            } elseif (!empty($skippedMissing)) {
                $message = 'No CI bootstrap files present; nothing to refresh';
            } else {
                $message = 'No CI bootstrap targets configured';
            }

            return Result::success($message, [
                'refreshed' => [],
                'skipped_current' => $skippedCurrent,
                'skipped_missing' => $skippedMissing,
            ]);
        }

        $action = $this->pretend ? 'Would refresh' : 'Refreshed';

        return Result::success(
            sprintf('%s CI bootstrap files: %s', $action, implode(', ', $refreshed)),
            [
                'refreshed' => $refreshed,
                'skipped_current' => $skippedCurrent,
                'skipped_missing' => $skippedMissing,
            ],
        );
    }

    /**
     * Inspect an existing bootstrap script to determine which template
     * generated it.
     *
     * The first two lines of each bootstrap template carry a distinctive
     * "(GitHub Actions)" or "(Local Development)" marker in their header
     * comment. We use that rather than guessing from the presence of the
     * workflow file because a refresh must produce a file of the same
     * flavour, even mid-flight.
     */
    private function detectBootstrapTemplate(string $absolutePath): ?string
    {
        $content = file_get_contents($absolutePath);
        if ($content === false) {
            return null;
        }

        // Read only the header — the marker is on line 2 of every template.
        $head = substr($content, 0, 512);

        if (str_contains($head, '(GitHub Actions)')) {
            return 'bootstrap-github.sh';
        }

        if (str_contains($head, '(Local Development)')) {
            return 'bootstrap-local.sh';
        }

        return null;
    }

    /**
     * Build the template variable map for a target.
     *
     * Mirrors the substitutions used by `ci init`'s InitCommand so that a
     * refresh produces a file equivalent to a fresh init (modulo the
     * timestamp). Component-specific values are derived from the component
     * directory; the PHAR URL falls back to the same env-var-or-latest
     * default that InitCommand uses.
     *
     * @return array<string, string>
     */
    private function buildConfig(string $relativePath, string $componentPath, string $templateName): array
    {
        $componentName = basename(realpath($componentPath) ?: $componentPath);

        $config = [
            '{{COMPONENT_NAME}}' => $componentName,
            '{{WORK_DIR}}' => '/tmp/horde-ci',
        ];

        // Local-mode bootstrap references LOCAL_COMPONENTS_PATH /
        // LOCAL_COMPONENT_PATH which are normally provided by the
        // developer's environment. Preserve the env-or-placeholder
        // approach InitCommand uses so a refresh stays equivalent.
        if ($templateName === 'bootstrap-local.sh') {
            $config['{{LOCAL_COMPONENTS_PATH}}'] = getenv('LOCAL_COMPONENTS_PATH') ?: '${LOCAL_COMPONENTS_PATH}';
            $config['{{LOCAL_COMPONENT_PATH}}'] = getenv('LOCAL_COMPONENT_PATH') ?: '${LOCAL_COMPONENT_PATH}';
        } else {
            $config['{{COMPONENTS_PHAR_URL}}'] = $this->getComponentsPharUrl();
        }

        return $config;
    }

    private function getComponentsPharUrl(): string
    {
        $url = getenv('COMPONENTS_PHAR_URL');
        if ($url !== false && $url !== '') {
            return $url;
        }

        return 'https://github.com/horde/components/releases/latest/download/horde-components.phar';
    }
}
