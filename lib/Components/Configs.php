<?php
/**
 * PSR-0 Non-Namespaced compatibility wrapper for Horde\Components\Configs
 * 
 * Horde\GitTools currently hooks into this class to leverage components.
 * 
 * TODO: Remove For Horde 7.
 * @deprecated Deprecated since Horde 6. Use the namespaced variant
 */
use Horde\Components\Configs;
class Components_Configs extends Configs {};
