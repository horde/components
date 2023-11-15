<?php

declare(strict_types=1);

namespace Horde\Components\RuntimeContext;

class CurrentWorkingDirectory
{
    private readonly string $cwd;
    public function __construct()
    {
        $this->cwd = (string) getcwd();
    }
    /**
     * Returns true if either the CWD is known
     *
     */
    public function has(): bool
    {
        return (bool) $this->cwd;
    }

    /**
     * Return the CWD or an empty string.
     */
    public function get(): string
    {
        return $this->cwd;
    }
}
