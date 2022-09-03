<?php

namespace Horde\Components\Release\Task;

class Transpile extends Base
{
    public function run(&$options): void
    {
        if ($this->getTasks()->pretend()) {
            $this->getOutput()->info(
                'Would try to transpile down to ...'
            );
        }
        $this->getOutput()->info('Transpiler stage');
    }
}
