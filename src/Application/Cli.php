<?php

namespace Horde\Components\Application;

class Cli
{
    public function __construct(
        private HordeCli $cli = new HordeCli(),
    ) {
    }
}
