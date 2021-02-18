<?php
/**
 * PSR-0 Non-Namespaced compatibility wrapper for Horde\Components\Exception
 * 
 * Horde\GitTools currently hooks into this class to leverage components.
 * 
 * TODO: Remove For Horde 7.
 * @deprecated Deprecated since Horde 6. Use the namespaced variant
 */
use Horde\Components\Exception as ComponentsException;
class Components_Exception extends ComponentsException {};
