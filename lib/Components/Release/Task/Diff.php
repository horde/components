<?php

class Components_Release_Task_Diff extends Components_Release_Task_Base
{
    public function run(&$options)
    {
        if ($this->getTasks()->pretend()) {
            $this->getOutput()->info(
                'The diff of the package files would look like:'
            );
            $this->getOutput()->plain($this->_component->getWrappersDiff(isset($options['old_wrappers']) ? $options['old_wrappers'] : null));
        }
    }
}