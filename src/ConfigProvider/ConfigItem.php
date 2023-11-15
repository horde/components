<?php

namespace Horde\Components\ConfigProvider;

use Stringable;

interface ConfigItem extends Stringable
{
    public function caption(): string;

    public function validate(): bool;
}
