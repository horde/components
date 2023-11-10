<?php
declare(strict_types=1);
namespace Horde\Components\ConfigProvider;
/**
 * A config provider based on environment
 */
class EnvironmentConfigProvider implements ConfigProvider
{

    public function __construct(private array $settings)
    {

    }


    public function hasSetting(string $id): bool
    {
        return array_key_exists($id, $this->settings);
    }

    public function getSetting(string $id): string
    {
        if (!$this->hasSetting($id)) {
            // Throw exception
        }
        return $this->settings[$id];
    }
}