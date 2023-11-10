<?php
declare(strict_types=1);
namespace Horde\Components\ConfigProvider;
/**
 * A top layer wins strategy for looking up config settings
 */
class EffectiveConfigProvider implements ConfigProvider
{
    private iterable $providers;

    public function __construct(ConfigProvider ...$providers)
    {
        $this->providers = $providers;
    }

    public function hasSetting(string $id): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->hasSetting($id)) {
                return true;
            }
        }
        return false;
    }
    public function getSetting(string $id): string
    {
        foreach ($this->providers as $provider) {
            if ($provider->hasSetting($id)) {
                return $provider->getSetting($id);
            }
        }
        // Throw exception if none has it
    }
}