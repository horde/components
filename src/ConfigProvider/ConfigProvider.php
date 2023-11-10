<?php
declare(strict_types=1);
namespace Horde\Components\ConfigProvider;

interface ConfigProvider
{
    public function hasSetting(string $id): bool;
    // All settings are ultimately strings for now
    public function getSetting(string $id): string;
}