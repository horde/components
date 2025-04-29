<?php
declare(strict_types=1);
namespace Horde\Components;
use Stringable;

class License implements Stringable
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $uri,
    ) {
    }
    public function toArray()
    {
        return [
            'identifier' => $this->identifier,
            'uri' => $this->uri,
        ];
    }
    public static function fromIdentifier(string $identifier): self
    {
        return new self($identifier, 'https://spdx.org/licenses/' . $identifier . '.html');
    }
    public function __toString(): string
    {
        return $this->identifier;
    }
}