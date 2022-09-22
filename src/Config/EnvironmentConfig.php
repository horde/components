<?php
/**
 * Config built from environment variables
 *
 * PHP Version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Config;

use Horde\Platform\Environment;

class EnvironmentConfig implements ConfigInterface
{
    use ConfigTrait;

    public function __construct(Environment $env, array $mapping = [])
    {
        foreach ($mapping as $envKey => $setting) {
            if ($env->exists($envKey)) {
                $this->settings[$setting] = (string) $env->get($envKey);
            }
        }
    }
}
