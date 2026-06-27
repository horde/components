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
use Horde\Components\Helper\PlatformResolver;
use Horde\Components\Helper\Shell;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\HordeYmlFile\HordeYmlFile;

/**
 * Refresh CI infrastructure on release if a component carries CI.
 *
 * The presence of `.github/workflows/ci.yml` is the cohort marker: a
 * component has CI iff this file exists. When it exists, this task:
 *
 * 1. Resolves transitive platform requirements per supported PHP minor
 *    (the same work that `horde-components deps --platform` performs)
 *    and writes the result into `.horde.yml` under `ci-platform:`. The
 *    block is only re-staged if its data structure changed; cosmetic
 *    re-serialisation does not pollute the release commit.
 *
 * 2. Migrates the pre-1.5.1 bootstrap path: `bin/ci-bootstrap.sh` is
 *    moved to `.github/bin/ci-bootstrap.sh`. The legacy path is staged
 *    as a deletion so the release commit captures the rename.
 *
 * 3. Deletes obsolete sibling workflows that horde-components now
 *    replaces in full: `.github/workflows/release.yml` and
 *    `.github/workflows/update-satis.yml`. `phpdoc.yml`, `on-pr.yml`,
 *    and `on-pr-merged.yml` are orthogonal and left alone.
 *
 * 4. Re-renders the bootstrap script and `ci.yml` from the current
 *    template. The freshly-rendered content is substance-compared
 *    against the file on disk: the generation timestamp and template
 *    version lines are stripped from both sides before byte-comparison
 *    so that a release that bumps the template version without
 *    changing actual content produces no diff. Only substantive
 *    differences land in the release commit.
 *
 * When `.github/workflows/ci.yml` is absent (Group A: a component that
 * has never opted into CI) the task is a complete no-op. Bootstrapping
 * is `horde-components ci init`'s job.
 *
 * The release CLI option `--skip-ci-refresh` (Context option
 * `skip_ci_refresh = true`) short-circuits the whole task. Use for
 * offline hotfix releases where the Packagist round-trips for the
 * platform refresh are unwelcome.
 *
 * Emitted Facts:
 * - ci.refreshed (array<string>) — relative paths refreshed
 * - ci.refreshed_count (int) — count of refreshed files
 * - ci.legacy_path_migrated (bool) — bin/ -> .github/bin/ ran
 * - ci.obsolete_siblings_deleted (array<string>) — deleted workflows
 * - ci.platform_refreshed (bool) — `.horde.yml` ci-platform block changed
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
     * Files this task may rewrite from templates. The bootstrap script's
     * template name is resolved at run time (github vs local flavour);
     * the workflow has only one flavour.
     */
    private const TARGETS = [
        '.github/bin/ci-bootstrap.sh' => null,
        '.github/workflows/ci.yml' => 'workflow.yml',
    ];

    /**
     * The cohort gate: presence of this file means "this component has
     * CI." Absent => the task is a complete no-op.
     */
    private const COHORT_MARKER = '.github/workflows/ci.yml';

    /**
     * Obsolete sibling workflows that horde-components now replaces in
     * full. Deleted whenever the cohort gate is met and any of these
     * exist on disk.
     */
    private const OBSOLETE_SIBLINGS = [
        '.github/workflows/release.yml',
        '.github/workflows/update-satis.yml',
    ];

    public function __construct(
        Output $output,
        private readonly ?TemplateRenderer $renderer = null,
        bool $pretend = false,
        private readonly ?PlatformResolver $resolver = null,
    ) {
        parent::__construct($output, $pretend);
    }

    public function getName(): string
    {
        return 'Refresh CI bootstrap';
    }

    public function run(Context $context): Result
    {
        // Maintainer escape hatch: --skip-ci-refresh on release short-
        // circuits this task entirely. Use for offline hotfix releases.
        if ($context->getOption('skip_ci_refresh') === true) {
            return Result::success(
                'CI refresh skipped (skip_ci_refresh=true)',
                [
                    'skipped' => true,
                    'reason' => 'option',
                ],
            );
        }

        $componentPath = $context->getComponentPath();

        // Cohort gate: only components that already carry CI get the
        // release-time refresh treatment. A component with no
        // .github/workflows/ci.yml has either deliberately opted out of
        // CI or has never run `horde-components ci init`; either way the
        // release pipeline is the wrong place to silently introduce CI.
        if (!file_exists($componentPath . '/' . self::COHORT_MARKER)) {
            return Result::success(
                'CI refresh skipped (component has no .github/workflows/ci.yml)',
                [
                    'skipped' => true,
                    'reason' => 'no_ci_yml',
                ],
            );
        }

        $renderer = $this->renderer ?? new TemplateRenderer(TemplateLocator::getTemplateDir());

        $refreshed = [];
        $skippedCurrent = [];

        // Step 1: refresh the ci-platform: block in .horde.yml. Same
        // work as `horde-components deps --platform`, performed
        // implicitly on every release so the block tracks Packagist
        // truth without the maintainer remembering to invoke it.
        $platformRefreshed = $this->refreshCiPlatformBlock($componentPath);

        // Step 2: pre-1.5.1 path migration. Rename
        // bin/ci-bootstrap.sh to .github/bin/ci-bootstrap.sh so the
        // refresh loop downstream can do its substance-compare against
        // the canonical location.
        $legacyMigrated = $this->migrateLegacyBootstrapPath($componentPath);
        if ($legacyMigrated === false) {
            return Result::failure(
                'Failed to migrate bin/ci-bootstrap.sh to .github/bin/ci-bootstrap.sh',
            );
        }

        // Step 3: delete obsolete sibling workflows that horde-components
        // now replaces in full.
        $deletedSiblings = $this->deleteObsoleteSiblings($componentPath);
        if ($deletedSiblings === false) {
            return Result::failure('Failed to delete an obsolete sibling workflow');
        }

        // Step 4: refresh the bootstrap script and workflow file. The
        // freshly-rendered output is substance-compared against the
        // existing file (metadata lines stripped) so that a release
        // that bumps the template version without changing actual
        // content does not pollute the commit.
        foreach (self::TARGETS as $relativePath => $templateName) {
            $absolutePath = $componentPath . '/' . $relativePath;

            if (!file_exists($absolutePath)) {
                // The bootstrap script may legitimately not yet exist
                // on a Group C component (legacy GitHub-managed-matrix
                // setup carrying ci.yml but no bootstrap script). The
                // cohort gate above already confirmed ci.yml exists, so
                // a missing bootstrap means "this is the first refresh
                // since adopting the modern pipeline"; we still need to
                // render it.
                if ($templateName === null) {
                    // Default to the GitHub flavour for first-time
                    // rendering. A local-mode component would never end
                    // up here because local mode has no workflow file
                    // and would have failed the cohort gate.
                    $templateName = 'bootstrap-github.sh';
                }
            } else {
                // Resolve the template name for an existing bootstrap
                // by reading its mode marker so a local-mode component
                // never gets a GitHub-mode rewrite.
                if ($templateName === null) {
                    $templateName = $this->detectBootstrapTemplate($absolutePath);
                    if ($templateName === null) {
                        $this->output->warn(sprintf(
                            'Cannot determine CI mode for %s; skipping refresh',
                            $relativePath,
                        ));
                        continue;
                    }
                }
            }

            $config = $this->buildConfig($relativePath, $componentPath, $templateName);
            $newContent = $renderer->render($templateName, $config);

            // Substance comparison: identical-ignoring-metadata means
            // no real change happened. Skip writing so the release
            // commit stays focused on real content.
            if (file_exists($absolutePath) && $this->isSubstantivelyEqual($absolutePath, $newContent)) {
                $skippedCurrent[] = $relativePath;
                continue;
            }

            if ($this->pretend) {
                $this->output->info(sprintf('[PRETEND] Would refresh %s', $relativePath));
                $refreshed[] = $relativePath;
                continue;
            }

            $dir = dirname($absolutePath);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                return Result::failure(sprintf(
                    'Failed to create directory for %s',
                    $relativePath,
                ));
            }

            if (file_put_contents($absolutePath, $newContent) === false) {
                return Result::failure(sprintf('Failed to write %s', $relativePath));
            }

            // Bootstrap script must remain executable.
            if (str_ends_with($relativePath, '.sh')) {
                @chmod($absolutePath, 0o755);
            }

            $this->output->info(sprintf('Refreshed %s', $relativePath));
            $refreshed[] = $relativePath;
        }

        // Stage every path that materially changed into the release
        // commit so CommitTask captures the deltas alongside the
        // version bump.
        $this->stageReleaseCommitFiles(
            $context,
            $refreshed,
            $legacyMigrated,
            $deletedSiblings,
            $platformRefreshed,
        );

        $context->setFact('ci.refreshed', $refreshed);
        $context->setFact('ci.refreshed_count', count($refreshed));
        $context->setFact('ci.legacy_path_migrated', $legacyMigrated === true);
        $context->setFact('ci.obsolete_siblings_deleted', $deletedSiblings);
        $context->setFact('ci.platform_refreshed', $platformRefreshed);

        $changed = !empty($refreshed)
            || $legacyMigrated === true
            || !empty($deletedSiblings)
            || $platformRefreshed;

        if (!$changed) {
            $message = empty($skippedCurrent)
                ? 'CI infrastructure up to date'
                : 'CI infrastructure up to date: ' . implode(', ', $skippedCurrent);

            return Result::success($message, [
                'refreshed' => [],
                'skipped_current' => $skippedCurrent,
                'legacy_path_migrated' => false,
                'obsolete_siblings_deleted' => [],
                'platform_refreshed' => false,
            ]);
        }

        $parts = [];
        if (!empty($refreshed)) {
            $parts[] = ($this->pretend ? 'would refresh ' : 'refreshed ') . implode(', ', $refreshed);
        }
        if ($legacyMigrated === true) {
            $parts[] = 'migrated bin/ci-bootstrap.sh -> .github/bin/ci-bootstrap.sh';
        }
        if (!empty($deletedSiblings)) {
            $parts[] = 'deleted ' . implode(', ', $deletedSiblings);
        }
        if ($platformRefreshed) {
            $parts[] = 'updated ci-platform block in .horde.yml';
        }

        return Result::success(
            'CI refresh: ' . implode('; ', $parts),
            [
                'refreshed' => $refreshed,
                'skipped_current' => $skippedCurrent,
                'legacy_path_migrated' => $legacyMigrated === true,
                'obsolete_siblings_deleted' => $deletedSiblings,
                'platform_refreshed' => $platformRefreshed,
            ],
        );
    }

    /**
     * Resolve transitive platform requirements per supported PHP minor
     * and write the result into .horde.yml under ci-platform. The block
     * is only re-saved when the resolved data structure differs from
     * what's already on disk; cosmetic re-serialisation produces no diff.
     *
     * Returns true when the block was substantively updated, false when
     * the existing block matches what we'd write or when the network
     * resolution failed.
     */
    private function refreshCiPlatformBlock(string $componentPath): bool
    {
        $hordeYmlPath = $componentPath . '/.horde.yml';
        if (!file_exists($hordeYmlPath)) {
            $this->output->warn(
                'No .horde.yml at ' . $hordeYmlPath . '; skipping ci-platform refresh',
            );
            return false;
        }

        try {
            $hordeYml = new HordeYmlFile($hordeYmlPath);
        } catch (\Throwable $e) {
            $this->output->warn(
                'Failed to read .horde.yml; skipping ci-platform refresh: ' . $e->getMessage(),
            );
            return false;
        }

        $resolver = $this->resolver ?? new PlatformResolver(new Shell($this->output), $this->output);

        try {
            $shaped = $resolver->resolveAndShapeForCiPlatform($hordeYml, $componentPath);
        } catch (\Throwable $e) {
            // Network failures or composer-resolver bugs must not
            // abort an entire release. A stale ci-platform block is
            // still preferable to a failed tag.
            $this->output->warn(
                'ci-platform refresh failed (keeping existing block): ' . $e->getMessage(),
            );
            return false;
        }

        $newPlatform = $shaped['ci-platform'];
        $newFlags = $shaped['ci-platform-flags'];

        // Compare both halves separately. Either changing counts as a
        // substantive update; both unchanged is the no-op case.
        $existingPlatform = $hordeYml->get('ci-platform');
        $existingPlatformNorm = $this->normaliseForComparison($existingPlatform);
        $newPlatformNorm = $this->normaliseForComparison($newPlatform);
        $platformChanged = $existingPlatformNorm === null
            || !$this->ciPlatformEquivalent($existingPlatformNorm, $newPlatformNorm);

        $existingFlags = $hordeYml->get('ci-platform-flags');
        $existingFlagsNorm = $this->normaliseForComparison($existingFlags);
        $newFlagsNorm = $this->normaliseForComparison($newFlags);
        $flagsChanged = $existingFlagsNorm === null
            || !$this->ciPlatformEquivalent($existingFlagsNorm, $newFlagsNorm);

        if (!$platformChanged && !$flagsChanged) {
            // Nothing meaningful changed; do not touch the file.
            return false;
        }

        if ($this->pretend) {
            $this->output->info(
                '[PRETEND] Would update ci-platform '
                . ($flagsChanged ? 'and ci-platform-flags ' : '')
                . 'block in .horde.yml',
            );
            return true;
        }

        if ($platformChanged) {
            $hordeYml->set('ci-platform', $newPlatform);
        }
        if ($flagsChanged) {
            $hordeYml->set('ci-platform-flags', $newFlags);
        }
        $hordeYml->save();
        $this->output->info(sprintf(
            'Updated %s in .horde.yml',
            $platformChanged && $flagsChanged
                ? 'ci-platform and ci-platform-flags blocks'
                : ($platformChanged ? 'ci-platform block' : 'ci-platform-flags block'),
        ));
        return true;
    }

    /**
     * Deep-compare two ci-platform structures for equivalence.
     *
     * The shape per minor is either a string sentinel ("not resolvable")
     * or a list of strings (ext / lib entries) and single-key arrays
     * (php / composer entries). Order within each minor's list is
     * significant for the YAML emitter, so this comparator is
     * order-preserving.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function ciPlatformEquivalent(array $a, array $b): bool
    {
        // PHP arrays compare element-wise; this is enough for the data
        // shapes the resolver produces (nested string-keyed and integer-
        // keyed arrays with scalar leaves).
        return $a == $b;
    }

    /**
     * Normalise a value read from `.horde.yml` (which may contain
     * stdClass objects where the YAML had maps) into a pure-array
     * representation that can be directly compared against the
     * resolver's array-shaped output. Returns null when the input is
     * neither an array nor a stdClass (e.g. the key was absent).
     *
     * @return array<mixed>|null
     */
    private function normaliseForComparison(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $k => $v) {
            if (is_object($v) || is_array($v)) {
                $normalised = $this->normaliseForComparison($v);
                $value[$k] = $normalised ?? $v;
            }
        }
        return $value;
    }

    /**
     * Migrate bin/ci-bootstrap.sh to .github/bin/ci-bootstrap.sh. Returns
     * null when both paths exist (ambiguous, refuse to clobber), true
     * when the migration happened (or would happen in pretend mode),
     * false when the rename itself failed. The dual exists case is
     * a no-op success: legacy path stays put, new path is the source
     * of truth.
     *
     * @return bool|null true=migrated, null=no-op, false=failed
     */
    private function migrateLegacyBootstrapPath(string $componentPath): ?bool
    {
        $legacy = $componentPath . '/bin/ci-bootstrap.sh';
        $new = $componentPath . '/.github/bin/ci-bootstrap.sh';

        if (!file_exists($legacy)) {
            return null;
        }
        if (file_exists($new)) {
            // Both exist; refuse to clobber.
            return null;
        }

        if ($this->pretend) {
            $this->output->info(
                '[PRETEND] Would migrate bin/ci-bootstrap.sh -> .github/bin/ci-bootstrap.sh',
            );
            return true;
        }

        $newDir = dirname($new);
        if (!is_dir($newDir) && !mkdir($newDir, 0o755, true) && !is_dir($newDir)) {
            return false;
        }
        if (!rename($legacy, $new)) {
            return false;
        }
        $this->output->info(
            'Migrated bin/ci-bootstrap.sh -> .github/bin/ci-bootstrap.sh (template 1.5.1 path)',
        );
        return true;
    }

    /**
     * Delete obsolete sibling workflow files. Returns the list of
     * deleted paths, or false if any deletion failed.
     *
     * @return list<string>|false
     */
    private function deleteObsoleteSiblings(string $componentPath): array|false
    {
        $deleted = [];
        foreach (self::OBSOLETE_SIBLINGS as $sibling) {
            $absolute = $componentPath . '/' . $sibling;
            if (!file_exists($absolute)) {
                continue;
            }
            if ($this->pretend) {
                $this->output->info(sprintf(
                    '[PRETEND] Would delete obsolete %s (replaced by horde-components release h6)',
                    $sibling,
                ));
                $deleted[] = $sibling;
                continue;
            }
            if (!unlink($absolute)) {
                return false;
            }
            $this->output->info(sprintf(
                'Deleted obsolete %s (replaced by horde-components release h6)',
                $sibling,
            ));
            $deleted[] = $sibling;
        }
        return $deleted;
    }

    /**
     * Substance-equality: byte-compare two contents (one on disk, one
     * freshly rendered) after stripping the template metadata lines
     * that change on every render but carry no real signal:
     *   # Generated: 2026-06-27 10:23:45 UTC
     *   # Template version: 1.5.1
     *
     * A timestamp-only diff (which is what every release would produce
     * if we naïvely byte-compared) returns true here and the file is
     * left alone. A real content change (different command, different
     * matrix, different artifact path) returns false and the file gets
     * rewritten.
     */
    private function isSubstantivelyEqual(string $existingPath, string $newContent): bool
    {
        $existing = file_get_contents($existingPath);
        if ($existing === false) {
            return false;
        }
        return $this->stripMetadataLines($existing) === $this->stripMetadataLines($newContent);
    }

    /**
     * Drop the per-render metadata lines from a generated file so that
     * substance can be compared without timestamp noise.
     */
    private function stripMetadataLines(string $content): string
    {
        // Match "# Generated: ..." and "# Template version: ..." on
        // their own lines, with optional leading whitespace (some
        // templates indent comments inside YAML blocks).
        return (string) preg_replace(
            '/^[ \t]*#\s*(Generated:|Template version:).*\R?/m',
            '',
            $content,
        );
    }

    /**
     * Append every path that materially changed in this run to the
     * release commit's `files` option. CommitTask reads that option and
     * runs `git add` on each entry. Missing-on-disk entries (the legacy
     * bootstrap path after migration, the deleted siblings) become
     * deletions in the commit; present-on-disk entries become adds.
     *
     * @param list<string> $refreshed
     * @param list<string>|false $deletedSiblings
     */
    private function stageReleaseCommitFiles(
        Context $context,
        array $refreshed,
        ?bool $legacyMigrated,
        array|false $deletedSiblings,
        bool $platformRefreshed,
    ): void {
        if ($this->pretend) {
            return;
        }

        $existing = $context->getOption('files');
        $files = is_array($existing) ? $existing : [];

        foreach ($refreshed as $path) {
            if (!in_array($path, $files, true)) {
                $files[] = $path;
            }
        }
        if ($legacyMigrated === true && !in_array('bin/ci-bootstrap.sh', $files, true)) {
            $files[] = 'bin/ci-bootstrap.sh';
        }
        if (is_array($deletedSiblings)) {
            foreach ($deletedSiblings as $sibling) {
                if (!in_array($sibling, $files, true)) {
                    $files[] = $sibling;
                }
            }
        }
        if ($platformRefreshed && !in_array('.horde.yml', $files, true)) {
            $files[] = '.horde.yml';
        }

        if (!empty($files)) {
            $context->setOption('files', $files);
        }
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
