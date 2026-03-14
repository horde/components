<?php

/**
 * Copyright 2026-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Task\Composer;

use Horde\Components\Helper\Composer;
use Horde\Components\Helper\Git;
use Horde\Components\Output;
use Horde\Components\Task\AbstractTask;
use Horde\Components\Task\Context;
use Horde\Components\Task\Result;
use Horde\Components\Wrapper\HordeYml as WrapperHordeYml;
use Horde\HordeYmlFile\HordeYmlFile;
use Horde\Version\ConstraintParser;
use Horde\Version\Constraint\CompositeConstraint;
use Horde\Version\Constraint\CaretConstraint;
use Horde\Version\RelaxedSemanticVersion;

/**
 * Update Horde dependency versions from FRAMEWORK_6_0 branches.
 *
 * Scans all Horde dependencies in .horde.yml, checks their current version
 * in FRAMEWORK_6_0 branches, and updates version constraints accordingly.
 *
 * Emitted Facts:
 * - dependencies.updated (bool) - True if updates were applied
 * - dependencies.changes (array) - List of dependency changes made
 * - dependencies.checkout_dir (string) - Git checkout directory used
 *
 * Required Options:
 * - checkout_dir (optional) - Override default git checkout location
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class UpdateDependenciesTask extends AbstractTask
{
    /**
     * Constructor.
     *
     * @param Output $output Output handler
     * @param Git $gitHelper Git operations helper
     * @param Composer $composerHelper Composer operations helper
     * @param bool $pretend Pretend mode (dry-run)
     */
    public function __construct(
        Output $output,
        private readonly Git $gitHelper,
        private readonly Composer $composerHelper,
        bool $pretend = false
    ) {
        parent::__construct($output, $pretend);
    }

    public function getName(): string
    {
        return "Update Horde Dependencies";
    }

    public function run(Context $context): Result
    {
        $componentPath = $context->getComponentPath();

        // Get checkout directory from context option or fact
        $checkoutDir = $context->getOption('checkout_dir')
            ?? $context->getFact('dependencies.checkout_dir')
            ?? $this->getCheckoutDir();

        // Emit fact for other tasks
        $context->setFact('dependencies.checkout_dir', $checkoutDir);

        // Load wrapper for array access to dependencies
        $wrapper = new WrapperHordeYml($componentPath);

        $this->output->plain('Checking dependency versions in FRAMEWORK_6_0 branches...');
        $this->output->plain('');

        // Collect changes across all dependency categories
        $changes = [];
        $suggestions = [];
        $todos = [];

        foreach (['required', 'optional', 'dev'] as $category) {
            if (!isset($wrapper['dependencies'][$category]['composer'])) {
                continue;
            }

            $composerDeps = $wrapper['dependencies'][$category]['composer'];

            foreach ($composerDeps as $packageName => $currentVersion) {
                // Only process horde/* packages
                if (!str_starts_with(strtolower($packageName), 'horde/')) {
                    continue;
                }

                $update = $this->checkDependencyUpdate($packageName, $currentVersion, $checkoutDir);

                if ($update !== null) {
                    $item = [
                        'category' => $category,
                        'package' => $packageName,
                        'current' => $currentVersion,
                        'new' => $update['constraint'],
                        'version' => $update['version'],
                    ];

                    // Categorize based on flags
                    if (!empty($update['todo'])) {
                        $item['reason'] = $update['reason'] ?? 'Manual review required';
                        $todos[] = $item;
                    } elseif (!empty($update['suggestion'])) {
                        $item['reason'] = $update['reason'] ?? 'Suggested update';
                        $suggestions[] = $item;
                    } else {
                        $changes[] = $item;
                    }
                }
            }
        }

        // If no changes, suggestions, or TODOs, return success early
        if (empty($changes) && empty($suggestions) && empty($todos)) {
            $context->setFact('dependencies.updated', false);
            return Result::success('All dependencies up to date', [
                'changes' => [],
                'suggestions' => [],
                'todos' => [],
                'checkout_dir' => $checkoutDir,
                'count' => 0,
            ]);
        }

        // In pretend mode, just return the changes without applying
        if ($this->pretend) {
            $context->setFact('dependencies.updated', false);
            return Result::success('Would update dependencies', [
                'changes' => $changes,
                'suggestions' => $suggestions,
                'todos' => $todos,
                'checkout_dir' => $checkoutDir,
                'count' => count($changes),
            ]);
        }

        // Only apply actual changes, not suggestions or TODOs
        if (!empty($changes)) {
            $this->applyChanges($componentPath, $changes);
            $this->regenerateComposerJson($componentPath);
        }

        // Emit facts
        $context->setFact('dependencies.updated', !empty($changes));
        $context->setFact('dependencies.changes', $changes);
        $context->setFact('dependencies.suggestions', $suggestions);
        $context->setFact('dependencies.todos', $todos);

        return Result::success('Dependencies updated successfully', [
            'changes' => $changes,
            'suggestions' => $suggestions,
            'todos' => $todos,
            'checkout_dir' => $checkoutDir,
            'count' => count($changes),
        ]);
    }

    /**
     * Check if a dependency needs updating.
     *
     * @param string $packageName Package name (e.g., horde/alarm)
     * @param string $currentVersion Current version constraint
     * @param string $checkoutDir Git checkout base directory
     * @return array|null Update info or null if no update needed
     */
    private function checkDependencyUpdate(string $packageName, string $currentVersion, string $checkoutDir): ?array
    {
        // Extract component name: horde/alarm → alarm, horde/horde → base
        $componentName = substr($packageName, 6); // Remove 'horde/'

        // Special case: horde/horde is in 'base' directory
        if ($componentName === 'horde') {
            $componentName = 'base';
        }

        // Convert package name to directory name
        // Package names use lowercase with hyphens, directories use PascalCase with underscores
        // We need to find the actual directory since simple case conversion won't work
        // for names like "hordeymlfile" → "HordeYmlFile" or "githubapiclient" → "GithubApiClient"

        // First, try a case-insensitive search in the horde directory
        $hordeDir = $checkoutDir . '/horde';
        if (is_dir($hordeDir)) {
            // Convert package name format for comparison: cli-modular → Cli_Modular pattern
            $searchPattern = str_replace('-', '_', $componentName);

            // Scan for matching directory (case-insensitive)
            $dirs = scandir($hordeDir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') {
                    continue;
                }
                if (strcasecmp($dir, $searchPattern) === 0) {
                    $componentName = $dir;
                    break;
                }
            }
        }

        // Resolve component path
        $componentPath = $checkoutDir . '/horde/' . $componentName;

        if (!is_dir($componentPath)) {
            // Silently skip missing components (they may be optional dependencies not checked out)
            return null;
        }

        // Check if on FRAMEWORK_6_0 branch
        try {
            $branch = $this->gitHelper->getCurrentBranch($componentPath);
            if ($branch !== 'FRAMEWORK_6_0') {
                $this->output->warn("  {$packageName}: Not on FRAMEWORK_6_0 (on: {$branch})");
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }

        // Read dependency's .horde.yml
        try {
            $depHordeYml = new HordeYmlFile($componentPath . '/.horde.yml');
            $depVersion = $depHordeYml->getReleaseVersion();

            // Calculate the appropriate constraint based on version and current constraint
            $result = $this->calculateConstraint($packageName, $currentVersion, $depVersion);

            if ($result !== null) {
                return $result;
            }
        } catch (\Exception $e) {
            $this->output->warn("  {$packageName}: Could not read .horde.yml: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Calculate appropriate version constraint for a dependency.
     *
     * Rules:
     * - Alpha versions: Use full version with pre-release (e.g., ^3.0.0-alpha8)
     * - Stable versions: Use feature release (e.g., ^3.1)
     * - Preserve bugfix versions: Don't downgrade ^3.1.2 to ^3.1
     * - Major upgrades: Only suggest, don't apply (mark with 'suggestion' flag)
     * - Compound OR constraints (^2 || ^3): Update matching branch
     * - Compound AND constraints (>=X <Y): Handled by existing logic
     * - Don't downgrade pre-release tags (beta > alpha, rc > beta, stable > rc)
     *
     * @param string $packageName Package name for error messages
     * @param string $currentConstraint Current version constraint
     * @param string $newVersion New version from .horde.yml
     * @return array|null ['version' => string, 'constraint' => string, 'suggestion' => bool?, 'todo' => bool?]
     */
    private function calculateConstraint(string $packageName, string $currentConstraint, string $newVersion): ?array
    {
        // Detect compound OR constraints (||)
        if (str_contains($currentConstraint, '||')) {
            return $this->calculateCompoundConstraint($packageName, $currentConstraint, $newVersion);
        }

        // Parse current constraint to extract major.minor.patch
        $currentParsed = $this->parseConstraint($currentConstraint);

        // Parse new version
        $newParsed = $this->parseVersion($newVersion);

        // Wildcard (*) - update to appropriate constraint
        if ($currentConstraint === '*') {
            return [
                'version' => $newVersion,
                'constraint' => $this->buildConstraint($newParsed),
            ];
        }

        // Check for pre-release stability downgrade (e.g., beta → alpha)
        if ($currentParsed && $this->isStabilityDowngrade($currentParsed, $newParsed)) {
            // Skip this update - don't downgrade stability
            return null;
        }

        // Check for major version upgrade (suggestion only)
        if ($currentParsed && $newParsed['major'] > $currentParsed['major']) {
            return [
                'version' => $newVersion,
                'constraint' => $this->buildConstraint($newParsed),
                'suggestion' => true,
                'reason' => "Major version upgrade from v{$currentParsed['major']} to v{$newParsed['major']}",
            ];
        }

        // Same major version - update if needed
        if ($currentParsed && $newParsed['major'] === $currentParsed['major']) {
            $newConstraint = $this->buildConstraint($newParsed, $currentParsed);

            if ($newConstraint !== $currentConstraint) {
                return [
                    'version' => $newVersion,
                    'constraint' => $newConstraint,
                ];
            }
        }

        // No update needed
        return null;
    }

    /**
     * Check if new version is a stability downgrade from current.
     *
     * Stability order: stable > rc > beta > alpha > dev
     *
     * @param array $currentParsed Current constraint parsed
     * @param array $newParsed New version parsed
     * @return bool True if new version is less stable
     */
    private function isStabilityDowngrade(array $currentParsed, array $newParsed): bool
    {
        // Extract pre-release from current constraint if it exists
        // Current might be like ^1.0.0-beta1, need to check the prerelease part
        $currentPrerelease = $currentParsed['prerelease'] ?? null;
        $newPrerelease = $newParsed['prerelease'];

        // If current has no prerelease, it's stable - any prerelease is a downgrade
        if ($currentPrerelease === null && $newPrerelease !== null) {
            return true;
        }

        // If new has no prerelease, it's stable - not a downgrade
        if ($newPrerelease === null) {
            return false;
        }

        // Both have prereleases - compare stability levels
        $currentStability = $this->getStabilityLevel($currentPrerelease);
        $newStability = $this->getStabilityLevel($newPrerelease);

        return $newStability < $currentStability;
    }

    /**
     * Get numeric stability level for comparison.
     *
     * @param string $prerelease Prerelease tag (e.g., "alpha5", "beta1", "rc2")
     * @return int Stability level (higher = more stable)
     */
    private function getStabilityLevel(string $prerelease): int
    {
        if (str_starts_with($prerelease, 'dev')) {
            return 1;
        }
        if (str_starts_with($prerelease, 'alpha')) {
            return 2;
        }
        if (str_starts_with($prerelease, 'beta')) {
            return 3;
        }
        if (str_starts_with($prerelease, 'rc')) {
            return 4;
        }
        return 5; // stable
    }

    /**
     * Build version constraint from parsed version.
     *
     * @param array $parsed Parsed version array
     * @param array|null $currentParsed Current constraint for preserving bugfix versions
     * @return string Version constraint
     */
    private function buildConstraint(array $parsed, ?array $currentParsed = null): string
    {
        $major = $parsed['major'];
        $minor = $parsed['minor'];
        $patch = $parsed['patch'];
        $prerelease = $parsed['prerelease'];

        // Alpha/beta/RC versions: use full version with pre-release
        if ($prerelease) {
            return "^{$major}.{$minor}.{$patch}-{$prerelease}";
        }

        // Stable versions: use major.minor (feature release)
        // But preserve existing bugfix version if it exists and is higher
        if ($currentParsed !== null &&
            $currentParsed['major'] === $major &&
            $currentParsed['minor'] === $minor &&
            $currentParsed['patch'] > 0) {
            // Preserve existing bugfix version
            return "^{$major}.{$minor}.{$currentParsed['patch']}";
        }

        return "^{$major}.{$minor}";
    }

    /**
     * Parse a version string into components.
     *
     * Handles both hyphenated and non-hyphenated pre-release tags:
     * - 3.1.2-alpha5 (standard)
     * - 3.1.2alpha5 (Horde legacy format)
     *
     * @param string $version Version string (e.g., "3.1.2-alpha5" or "3.1.2alpha5")
     * @return array ['major' => int, 'minor' => int, 'patch' => int, 'prerelease' => string|null]
     */
    private function parseVersion(string $version): array
    {
        // Try standard format first: 3.1.2-alpha5
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)-(.+)$/', $version, $matches)) {
            return [
                'major' => (int) $matches[1],
                'minor' => (int) $matches[2],
                'patch' => (int) $matches[3],
                'prerelease' => $matches[4],
            ];
        }

        // Try legacy Horde format: 3.1.2alpha5 (no hyphen)
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(alpha|beta|rc|dev)(\d+)?$/', $version, $matches)) {
            $prerelease = $matches[4];
            if (!empty($matches[5])) {
                $prerelease .= $matches[5];
            }
            return [
                'major' => (int) $matches[1],
                'minor' => (int) $matches[2],
                'patch' => (int) $matches[3],
                'prerelease' => $prerelease,
            ];
        }

        // No pre-release tag: 3.1.2
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $matches)) {
            return [
                'major' => (int) $matches[1],
                'minor' => (int) $matches[2],
                'patch' => (int) $matches[3],
                'prerelease' => null,
            ];
        }

        // Fallback for malformed versions
        $parts = explode('.', $version);
        return [
            'major' => (int) ($parts[0] ?? 0),
            'minor' => (int) ($parts[1] ?? 0),
            'patch' => (int) ($parts[2] ?? 0),
            'prerelease' => null,
        ];
    }

    /**
     * Parse a version constraint into components.
     *
     * @param string $constraint Version constraint (e.g., "^3.1.2", "^3.0.0-beta1", "^3", "*")
     * @return array|null ['major' => int, 'minor' => int, 'patch' => int, 'prerelease' => string|null] or null if unparseable
     */
    private function parseConstraint(string $constraint): ?array
    {
        // Remove caret operator
        $constraint = ltrim($constraint, '^~');

        // Match: 3.1.2-alpha5 or 3.1.2 or 3.1 or 3
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-(.+))?/', $constraint, $matches)) {
            return [
                'major' => (int) $matches[1],
                'minor' => (int) ($matches[2] ?? 0),
                'patch' => (int) ($matches[3] ?? 0),
                'prerelease' => $matches[4] ?? null,
            ];
        }

        return null;
    }

    /**
     * Get git checkout directory from environment or default.
     *
     * @return string Checkout directory path
     */
    private function getCheckoutDir(): string
    {
        // Check environment variable first
        $envDir = getenv('HORDE_CHECKOUT_DIR');
        if ($envDir && is_dir($envDir)) {
            return $envDir;
        }

        // Try common defaults
        $homeGit = getenv('HOME') . '/git';
        if (is_dir($homeGit)) {
            return $homeGit;
        }

        // Fallback to /srv/git
        if (is_dir('/srv/git')) {
            return '/srv/git';
        }

        // Default assumption
        return $homeGit;
    }

    /**
     * Apply dependency version changes to .horde.yml.
     *
     * @param string $componentPath Component directory path
     * @param array $changes Array of changes to apply
     */
    private function applyChanges(string $componentPath, array $changes): void
    {
        $hordeYml = new HordeYmlFile($componentPath . '/.horde.yml');

        // Get current dependencies as array (easier to work with)
        $deps = $hordeYml->getDependencies();
        if ($deps === null) {
            $this->output->warn('No dependencies section found in .horde.yml');
            return;
        }

        // Convert to array for modification
        $depsArray = $deps->toArray();

        // Apply each change
        foreach ($changes as $change) {
            $category = $change['category'];
            $package = $change['package'];
            $newConstraint = $change['new'];

            // Ensure the structure exists
            if (!isset($depsArray[$category])) {
                $depsArray[$category] = [];
            }
            if (!isset($depsArray[$category]['composer'])) {
                $depsArray[$category]['composer'] = [];
            }

            // Update the composer dependency
            $depsArray[$category]['composer'][$package] = $newConstraint;
        }

        // Convert back to stdClass and create new Dependencies object
        $depsStdClass = json_decode(json_encode($depsArray));
        $newDeps = \Horde\HordeYmlFile\Dependencies::fromStdClass($depsStdClass);

        // Save the updated dependencies back to the file
        $hordeYml->setDependencies($newDeps);
        $hordeYml->save();
    }

    /**
     * Regenerate composer.json after .horde.yml changes.
     *
     * @param string $componentPath Component directory path
     */
    private function regenerateComposerJson(string $componentPath): void
    {
        $wrapper = new WrapperHordeYml($componentPath);
        $this->composerHelper->generateComposerJson($wrapper, []);
    }

    /**
     * Calculate appropriate update for compound OR constraint.
     *
     * Rules:
     * - Parse compound constraint into branches
     * - Find which branch the new version satisfies
     * - Update that branch using same logic as simple constraints
     * - If new version is major upgrade, suggest adding new branch
     * - Preserve other branches unchanged
     *
     * @param string $packageName Package name
     * @param string $currentConstraint Current compound constraint (e.g., "^2.6 || ^3.1")
     * @param string $newVersion New version from .horde.yml
     * @return array|null Update info or null if no change
     */
    private function calculateCompoundConstraint(
        string $packageName,
        string $currentConstraint,
        string $newVersion
    ): ?array {
        $parser = new ConstraintParser();

        try {
            $newVersionObj = new RelaxedSemanticVersion($newVersion);
        } catch (\Exception $e) {
            return [
                'version' => $newVersion,
                'constraint' => $currentConstraint,
                'todo' => true,
                'reason' => 'Could not parse new version: ' . $e->getMessage(),
            ];
        }

        try {
            $constraint = $parser->parse($currentConstraint);

            // Must be CompositeConstraint with OR operator
            if (!$constraint instanceof CompositeConstraint) {
                // Shouldn't happen, but fallback to TODO
                return ['version' => $newVersion, 'constraint' => $currentConstraint, 'todo' => true, 'reason' => 'Unexpected constraint structure'];
            }

            if ($constraint->getOperator() !== 'OR') {
                // AND constraints (>=X <Y) represent ranges, not alternative branches
                // They should be handled by existing constraint update logic, not this method
                return ['version' => $newVersion, 'constraint' => $currentConstraint, 'todo' => true, 'reason' => 'AND constraint in unexpected context'];
            }

            // Get branches
            $branches = $constraint->getConstraints();
            $updatedBranches = [];
            $foundMatchingBranch = false;

            foreach ($branches as $branch) {
                $branchMajor = $this->extractMajorVersion($branch);
                $newMajor = $newVersionObj->major;

                // Check if this branch matches the new version's major
                if ($branchMajor === $newMajor) {
                    // Update this branch
                    $updatedBranch = $this->updateBranch($branch, $newVersionObj);
                    $updatedBranches[] = $updatedBranch;
                    $foundMatchingBranch = true;
                } else {
                    // Keep branch unchanged
                    $updatedBranches[] = (string) $branch;
                }
            }

            // Check if new version is a major upgrade beyond all branches
            $maxMajor = max(array_map(fn($b) => $this->extractMajorVersion($b), $branches));
            if ($newVersionObj->major > $maxMajor) {
                // Suggest adding new branch
                $parsed = $this->parseVersion($newVersion);
                $newBranch = $this->buildConstraint($parsed);
                $suggestedConstraint = implode(' || ', array_merge($updatedBranches, [$newBranch]));

                return [
                    'version' => $newVersion,
                    'constraint' => $suggestedConstraint,
                    'suggestion' => true,
                    'reason' => "Major version upgrade - adds new branch v{$newVersionObj->major}",
                ];
            }

            // If we updated a branch, return the change
            if ($foundMatchingBranch) {
                $newConstraint = implode(' || ', $updatedBranches);
                if ($newConstraint !== $currentConstraint) {
                    return [
                        'version' => $newVersion,
                        'constraint' => $newConstraint,
                    ];
                }
            }

            // No update needed
            return null;

        } catch (\Exception $e) {
            // Parse error or other issue - mark as TODO
            return [
                'version' => $newVersion,
                'constraint' => $currentConstraint,
                'todo' => true,
                'reason' => 'Error analyzing compound constraint: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Extract major version from a constraint.
     *
     * @param \Horde\Version\VersionConstraint $constraint Constraint object
     * @return int Major version number
     */
    private function extractMajorVersion($constraint): int
    {
        // For CaretConstraint, use the getter
        if ($constraint instanceof CaretConstraint) {
            $baseVersion = $constraint->getBaseVersion();
            if ($baseVersion instanceof RelaxedSemanticVersion) {
                return $baseVersion->major;
            }
        }

        // Fallback: parse string representation
        $str = (string) $constraint;
        $versionStr = preg_replace('/^[\\^~><!=]+/', '', $str);
        $parts = explode('.', $versionStr);
        return (int) ($parts[0] ?? 0);
    }

    /**
     * Update a single branch constraint with new version.
     *
     * @param \Horde\Version\VersionConstraint $branch Branch constraint
     * @param RelaxedSemanticVersion $newVersion New version
     * @return string Updated constraint string
     */
    private function updateBranch($branch, RelaxedSemanticVersion $newVersion): string
    {
        // Parse the branch to understand its format
        $branchStr = (string) $branch;

        // Most common case: caret constraint
        if (str_starts_with($branchStr, '^')) {
            $parsed = [
                'major' => $newVersion->major,
                'minor' => $newVersion->minor,
                'patch' => $newVersion->patch,
                'prerelease' => $newVersion->preRelease,
            ];
            return $this->buildConstraint($parsed);
        }

        // For other types, keep unchanged for now
        return $branchStr;
    }
}
