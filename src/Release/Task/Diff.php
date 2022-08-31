<?php

namespace Horde\Components\Release\Task;

class Diff extends Base
{
    public function run(&$options): void
    {
        if ($this->getTasks()->pretend()) {
            $this->getOutput()->info(
                'The diff of the package files would look like:'
            );
            $this->getOutput()->plain($this->_component->getWrappersDiff($options['old_wrappers'] ?? null));
        }
    }
}
