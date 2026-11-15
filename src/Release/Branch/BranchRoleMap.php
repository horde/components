<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Release\Branch;

use Horde\Components\Exception;
use Horde\Components\Release\BranchRoles;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStringLoader;
use Horde\Yaml\Document\Node\MapNode;
use Throwable;

/**
 * Structured, policy-aware view of data/branches.yml.
 *
 * Where {@see BranchRoles} models only branch
 * *names*, this reads the enhanced format into {@see BranchDefinition}
 * objects that also carry each branch's {@see BranchPhpPolicy}.
 *
 * Roles:
 * - release:   the single release branch (always present as a definition;
 *              an empty file yields a definition with an empty name).
 * - supported: zero or more maintenance branches.
 * - unstable:  an optional BC-breaking branch, or null.
 *
 * The file is read with the modern document parser
 * ({@see YamlStringLoader}) rather than the legacy dump/read loader, so a
 * branch slot may be a bare string or a single-key map; both normalize
 * through {@see BranchDefinition::fromYamlValue()}.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
final class BranchRoleMap
{
    /**
     * @param BranchDefinition $release The release branch.
     * @param list<BranchDefinition> $supported Supported maintenance branches.
     * @param BranchDefinition|null $unstable The unstable branch, or null.
     */
    public function __construct(
        private readonly BranchDefinition $release,
        private readonly array $supported = [],
        private readonly ?BranchDefinition $unstable = null,
    ) {}

    /**
     * Load and structure the branch-role map from a YAML file.
     *
     * @param string|null $file Path to the YAML file. Defaults to the
     *                          bundled data/branches.yml.
     * @throws Exception If the file cannot be read or parsed, or a branch
     *                   entry is malformed.
     */
    public static function load(?string $file = null): self
    {
        $file ??= self::bundledFile();

        if (!is_file($file) || !is_readable($file)) {
            // Match BranchRoles: a missing file degrades to an all-unknown
            // (empty) map rather than fataling.
            return self::empty();
        }

        $yaml = file_get_contents($file);
        if ($yaml === false) {
            throw new Exception(sprintf('Unable to read branch map file "%s".', $file));
        }

        return self::fromString($yaml);
    }

    /**
     * Structure the branch-role map from a YAML string, using the modern
     * document parser.
     *
     * @throws Exception If the YAML cannot be parsed or a branch entry is
     *                   malformed.
     */
    public static function fromString(string $yaml): self
    {
        try {
            $stream = (new YamlStringLoader())->load($yaml);
        } catch (Throwable $e) {
            throw new Exception('Failed to parse branch map YAML: ' . $e->getMessage(), 0, $e);
        }

        if ($stream->documentCount() === 0) {
            return self::empty();
        }

        return self::fromDocument($stream->getDocument(0));
    }

    /**
     * Structure the branch-role map from a parsed document.
     *
     * @throws Exception If a branch entry is malformed.
     */
    public static function fromDocument(YamlDocument $document): self
    {
        $root = $document->root();
        if (!$root instanceof MapNode) {
            // A top-level scalar/sequence (or empty) is not a role map;
            // degrade to empty rather than guess.
            return self::empty();
        }

        /** @var array<string,mixed> $data */
        $data = $root->resolved();

        return self::fromArray($data);
    }

    /**
     * Structure the branch-role map from a resolved native array.
     *
     * @param array<string,mixed> $data The resolved document root.
     * @throws Exception If a branch entry is malformed.
     */
    public static function fromArray(array $data): self
    {
        $release = array_key_exists('release', $data)
            ? BranchDefinition::fromYamlValue($data['release'])
            : BranchDefinition::bare('');

        $supported = [];
        $rawSupported = $data['supported'] ?? [];
        if (is_array($rawSupported)) {
            foreach ($rawSupported as $entry) {
                $supported[] = BranchDefinition::fromYamlValue($entry);
            }
        }

        $unstable = null;
        if (isset($data['unstable']) && $data['unstable'] !== '') {
            $unstable = BranchDefinition::fromYamlValue($data['unstable']);
        }

        return new self($release, $supported, $unstable);
    }

    /**
     * The empty map: an unnamed release, no supported, no unstable.
     */
    public static function empty(): self
    {
        return new self(BranchDefinition::bare(''), [], null);
    }

    /**
     * Absolute path to the bundled default branch role map.
     */
    public static function bundledFile(): string
    {
        return dirname(__DIR__, 3) . '/data/branches.yml';
    }

    /**
     * The release branch definition.
     */
    public function release(): BranchDefinition
    {
        return $this->release;
    }

    /**
     * The supported (maintenance) branch definitions.
     *
     * @return list<BranchDefinition>
     */
    public function supported(): array
    {
        return $this->supported;
    }

    /**
     * The unstable branch definition, or null when none is declared.
     */
    public function unstable(): ?BranchDefinition
    {
        return $this->unstable;
    }

    /**
     * A name-only {@see BranchRoles} view of this map.
     *
     * Lets the existing role-classification machinery
     * ({@see BranchRoles::roleOf()}, {@see BranchReleasePolicy}) work off
     * the structured map, so both the simple and the enhanced YAML formats
     * are handled through a single reader.
     */
    public function toBranchRoles(): BranchRoles
    {
        return new BranchRoles(
            $this->release->name(),
            array_map(static fn(BranchDefinition $d): string => $d->name(), $this->supported),
            $this->unstable?->name(),
        );
    }

    /**
     * Every branch definition across all roles, release first.
     *
     * @return list<BranchDefinition>
     */
    public function all(): array
    {
        $all = [$this->release, ...$this->supported];
        if ($this->unstable !== null) {
            $all[] = $this->unstable;
        }

        return $all;
    }

    /**
     * The definition for a branch name, or null when that name appears in
     * no role.
     */
    public function definitionFor(string $branch): ?BranchDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->name() !== '' && $definition->name() === $branch) {
                return $definition;
            }
        }

        return null;
    }
}
