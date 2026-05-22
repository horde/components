<?php

declare(strict_types=1);

namespace Horde\Components\Composer;

use RuntimeException;
use stdClass;

/**
 * Represents an installation's root composer.json file
 */
class RootComposerJsonFile
{
    private stdClass $content;
    private RepositoryList $repositories;

    public function __construct(stdClass|string $data)
    {
        if (is_string($data)) {
            $this->content = json_decode($data);
        } else {
            $this->content = $data;
        }
        $repositoriesStd = $this->content->repositories ?? [];
        $this->repositories = RepositoryList::fromStdClasses(...$repositoriesStd);
    }

    public function getRepositoryList(): RepositoryList
    {
        return $this->repositories;
    }

    public static function loadFile(string $path): self
    {
        $data = false;
        if (file_exists($path)) {
            $data = file_get_contents($path);
        } else {
            throw new RuntimeException('Could not load root composer.json file: ' . $data);
        }
        if ($data === false) {
            throw new RuntimeException('Could not load root composer.json file');
        }
        return new self($data);
    }

    public function render(): string
    {
        $this->content->repositories = [];
        foreach ($this->repositories as $id => $repository) {
            $this->content->repositories[$id] = $repository->dumpStdClass();
        }
        return (string) json_encode($this->content, JSON_PRETTY_PRINT);
    }

    public function setPreferStable(bool $preferStable = true): self
    {
        $this->content->{'prefer-stable'} = $preferStable;
        return $this;
    }

    public function setMinimumStability(string $stability = 'stable'): self
    {
        if (!in_array($stability, ['dev', 'alpha', 'beta', 'RC', 'stable'])) {
            throw new RuntimeException('Invalid stability level: ' . $stability);
        }
        $this->content->{'minimum-stability'} = $stability;
        return $this;
    }

    public function writeFile(string $path)
    {
        file_put_contents($path, $this->render());
    }

    /**
     * Get the raw content object
     */
    public function getContent(): stdClass
    {
        return $this->content;
    }

    /**
     * Deep-merge sections from a source composer.json into this one.
     *
     * Adds missing keys from source without overwriting existing entries.
     * Used to ensure bundle baseline deps are present while preserving
     * user-added dependencies.
     */
    public function mergeFrom(self $source): self
    {
        $sourceContent = $source->getContent();

        foreach (['require', 'require-dev', 'suggest'] as $section) {
            if (!isset($sourceContent->$section)) {
                continue;
            }
            if (!isset($this->content->$section)) {
                $this->content->$section = new stdClass();
            }
            foreach ($sourceContent->$section as $package => $constraint) {
                if (!isset($this->content->$section->$package)) {
                    $this->content->$section->$package = $constraint;
                }
            }
        }

        if (isset($sourceContent->config->{'allow-plugins'})) {
            if (!isset($this->content->config)) {
                $this->content->config = new stdClass();
            }
            if (!isset($this->content->config->{'allow-plugins'})) {
                $this->content->config->{'allow-plugins'} = new stdClass();
            }
            foreach ($sourceContent->config->{'allow-plugins'} as $plugin => $allowed) {
                if (!isset($this->content->config->{'allow-plugins'}->$plugin)) {
                    $this->content->config->{'allow-plugins'}->$plugin = $allowed;
                }
            }
        }

        if (isset($sourceContent->extra->{'installer-types'})) {
            if (!isset($this->content->extra)) {
                $this->content->extra = new stdClass();
            }
            $existing = (array) ($this->content->extra->{'installer-types'} ?? []);
            $sourceTypes = (array) $sourceContent->extra->{'installer-types'};
            $this->content->extra->{'installer-types'} = array_values(
                array_unique(array_merge($existing, $sourceTypes))
            );
        }

        return $this;
    }
}
