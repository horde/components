<?php
declare(strict_types=1);
namespace Horde\Components\ConfigProvider;

class PhpConfigFileProvider implements ConfigProvider
{

    private array $settings;

    public function __construct(string $location)
    {
        $path = dirname($location);
        $file = basename($location);
        if (!file_exists($path)) {
            mkdir($path, 0700, true);
        }
        if (!file_exists($location)) {
            file_put_contents($location, "<?php\n//Horde Components Config File\n\$conf = [];");
        }
        if (is_readable($location)) {
            $conf = [];
            require $location;
            $this->settings = $conf;
        }
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