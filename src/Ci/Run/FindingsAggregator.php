<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
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

namespace Horde\Components\Ci\Run;

/**
 * Aggregate per-tool findings across lanes, deduped by tool-specific keys.
 *
 * Each method takes a map `[laneName => /lane/build/dir]` and returns a flat
 * list of findings. Identical findings hit by multiple lanes collapse into
 * one entry, with a `lanes` array listing every lane that hit it.
 *
 * Dedup keys per the round-3 plan:
 *
 * - **PHPStan**: `(file, line, identifier)`. Identifier comes from PHPStan
 *   2.x's per-message `identifier` field (e.g. `isset.property`). When
 *   identifier is missing the message text is used as the third component.
 * - **PHP-CS-Fixer**: `(file)`. Dry-run output does not carry line numbers
 *   or per-fixer detail in JSON.
 * - **PHPUnit**: not yet implemented; PHPUnit text/JSON output does not
 *   carry per-failure file+line in a uniform shape. Future: switch the
 *   lane wrapper to `--log-junit` and key on `(test_class, test_method)`.
 *
 * The aggregator reads files from disk; tasks must persist their native
 * shape via `qc phpstan --dump-native` (writes phpstan-native.json) or
 * `qc phpcsfixer` (already writes php-cs-fixer-native.json on every run).
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class FindingsAggregator
{
    /**
     * Collect deduplicated PHPStan findings across lanes.
     *
     * @param array<string,string> $laneBuildDirs Map of laneName => build dir
     *                                            (the dir containing
     *                                            `phpstan-native.json`).
     * @return array<int,array{file: string, line: int|null, identifier: string|null, message: string, lanes: array<int,string>}>
     */
    public function aggregatePhpStan(array $laneBuildDirs): array
    {
        $byKey = [];

        foreach ($laneBuildDirs as $laneName => $buildDir) {
            $native = $this->loadJson($buildDir . '/phpstan-native.json');
            if (!is_array($native) || !isset($native['files']) || !is_array($native['files'])) {
                continue;
            }

            foreach ($native['files'] as $absolutePath => $fileEntry) {
                if (!is_string($absolutePath) || !is_array($fileEntry)) {
                    continue;
                }
                $messages = $fileEntry['messages'] ?? [];
                if (!is_array($messages)) {
                    continue;
                }
                $relPath = $this->stripLanePrefix((string) $absolutePath, (string) $laneName);

                foreach ($messages as $msg) {
                    if (!is_array($msg)) {
                        continue;
                    }
                    $message = (string) ($msg['message'] ?? '');
                    $line = isset($msg['line']) && is_int($msg['line']) ? $msg['line'] : null;
                    $identifier = isset($msg['identifier']) && is_string($msg['identifier'])
                        ? $msg['identifier']
                        : null;

                    $key = $relPath . '|' . ($line ?? '') . '|' . ($identifier ?? $message);

                    if (!isset($byKey[$key])) {
                        $byKey[$key] = [
                            'file' => $relPath,
                            'line' => $line,
                            'identifier' => $identifier,
                            'message' => $message,
                            'lanes' => [],
                        ];
                    }
                    if (!in_array($laneName, $byKey[$key]['lanes'], true)) {
                        $byKey[$key]['lanes'][] = $laneName;
                    }
                }
            }
        }

        $findings = array_values($byKey);
        usort($findings, fn (array $a, array $b): int =>
            ($a['file'] <=> $b['file'])
            ?: (($a['line'] ?? 0) <=> ($b['line'] ?? 0))
            ?: (($a['identifier'] ?? '') <=> ($b['identifier'] ?? ''))
        );
        return $findings;
    }

    /**
     * Collect deduplicated PHP-CS-Fixer findings across lanes.
     *
     * The native PCF JSON in dry-run mode lists files in `files[].name`
     * (no line numbers, no per-fixer detail).
     *
     * @param array<string,string> $laneBuildDirs Map of laneName => build dir
     * @return array<int,array{file: string, lanes: array<int,string>}>
     */
    public function aggregatePhpCsFixer(array $laneBuildDirs): array
    {
        $byKey = [];

        foreach ($laneBuildDirs as $laneName => $buildDir) {
            $native = $this->loadJson($buildDir . '/php-cs-fixer-native.json');
            if (!is_array($native) || !isset($native['files']) || !is_array($native['files'])) {
                continue;
            }

            foreach ($native['files'] as $file) {
                if (!is_array($file) || !isset($file['name']) || !is_string($file['name'])) {
                    continue;
                }
                $name = $file['name'];
                $key = $name;
                if (!isset($byKey[$key])) {
                    $byKey[$key] = [
                        'file' => $name,
                        'lanes' => [],
                    ];
                }
                if (!in_array($laneName, $byKey[$key]['lanes'], true)) {
                    $byKey[$key]['lanes'][] = $laneName;
                }
            }
        }

        $findings = array_values($byKey);
        usort($findings, fn (array $a, array $b): int => $a['file'] <=> $b['file']);
        return $findings;
    }

    /**
     * Walk each lane's `phpunit-results-summary.json` and produce a
     * deduplicated list of failed/errored tests.
     *
     * Dedup key is `(test_class, test_method, message)`. Same test
     * failing the same way in 4 lanes collapses to one entry whose
     * `lanes` list names the four lanes. Same test failing with
     * different messages on different PHP versions stays as separate
     * entries.
     *
     * Returns an empty list when no lane has any failures or errors.
     * The renderer is expected to skip the section in that case.
     *
     * @param array<string,string> $laneBuildDirs Map of laneName => build dir
     * @return list<array{type:string,test_class:string,test_method:string,file:string,line:int,exception_class:string,message:string,trace:string,lanes:list<string>}>
     */
    public function aggregatePhpUnit(array $laneBuildDirs): array
    {
        $byKey = [];

        foreach ($laneBuildDirs as $laneName => $buildDir) {
            $summary = $this->loadJson($buildDir . '/phpunit-results-summary.json');
            if (!is_array($summary)) {
                continue;
            }
            // The summary carries two arrays - one for failed
            // assertions, one for thrown exceptions. Merge them for
            // the dedup pass; the `type` field on each entry keeps
            // the distinction so the renderer can colour-code rows.
            $records = [];
            foreach (['failures', 'errors'] as $bucket) {
                if (isset($summary[$bucket]) && is_array($summary[$bucket])) {
                    foreach ($summary[$bucket] as $record) {
                        if (is_array($record)) {
                            $records[] = $record;
                        }
                    }
                }
            }
            foreach ($records as $record) {
                $class = (string) ($record['test_class'] ?? '');
                $method = (string) ($record['test_method'] ?? '');
                $message = (string) ($record['message'] ?? '');
                // Skip records too damaged to dedup meaningfully.
                if ($class === '' && $method === '') {
                    continue;
                }
                $key = $class . '::' . $method . '|' . $message;
                if (!isset($byKey[$key])) {
                    $byKey[$key] = [
                        'type' => (string) ($record['type'] ?? 'error'),
                        'test_class' => $class,
                        'test_method' => $method,
                        // Strip the lane prefix so the rendered table
                        // shows component-relative paths.
                        'file' => $this->stripLanePrefix((string) ($record['file'] ?? ''), $laneName),
                        'line' => (int) ($record['line'] ?? 0),
                        'exception_class' => (string) ($record['exception_class'] ?? ''),
                        'message' => $message,
                        'trace' => (string) ($record['trace'] ?? ''),
                        'lanes' => [],
                    ];
                }
                if (!in_array($laneName, $byKey[$key]['lanes'], true)) {
                    $byKey[$key]['lanes'][] = $laneName;
                }
            }
        }

        $findings = array_values($byKey);
        usort(
            $findings,
            fn (array $a, array $b): int =>
                ($a['test_class'] . '::' . $a['test_method'])
                    <=> ($b['test_class'] . '::' . $b['test_method'])
        );
        return $findings;
    }

    /**
     * Best-effort: strip a `/.../<lane>/<Component>/` prefix from an
     * absolute path so the rendered table shows repo-relative paths.
     */
    private function stripLanePrefix(string $absolute, string $laneName): string
    {
        // Expect paths like /tmp/horde-ci/lanes/<lane>/<Component>/src/Foo.php.
        // Find the lane name segment and return the part after the next
        // segment (the component dir).
        $needle = '/' . $laneName . '/';
        $pos = strpos($absolute, $needle);
        if ($pos === false) {
            return $absolute;
        }
        $tail = substr($absolute, $pos + strlen($needle));
        $slash = strpos($tail, '/');
        if ($slash === false) {
            return $tail;
        }
        return substr($tail, $slash + 1);
    }

    /**
     * @return array<string,mixed>|null Decoded JSON, or null on any error.
     */
    private function loadJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
