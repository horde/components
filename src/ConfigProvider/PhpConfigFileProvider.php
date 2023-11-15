<?php

declare(strict_types=1);

namespace Horde\Components\ConfigProvider;

class PhpConfigFileProvider implements ConfigProvider
{
    private array $settings = [];

    public function __construct(private string $location)
    {
        $path = dirname($location);
        $file = basename($location);
        if (!file_exists($path)) {
            mkdir($path, 0700, true);
        }
        if (!file_exists($location)) {
            $this->writeToDisk($location);
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

    /**
     * Currently only supports strings
     */
    public function setSetting(string $key, string $value)
    {
        $this->settings[$key] = $value;
    }

    public function writeToDisk()
    {
        $fileContent = '<?php'  . PHP_EOL . '//Horde Components Config File' .  PHP_EOL. '$conf = [];' .  PHP_EOL;
        foreach ($this->settings as $id => $value) {
            $fileContent .= '$conf["' . $id . '"] = "' . $value . '";' . PHP_EOL;
        }
        file_put_contents($this->location, $fileContent);
    }
}
