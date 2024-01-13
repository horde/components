<?php
/**
 * Horde\Components\Component\Dependency:: wraps PEAR dependency information.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Component;

use Horde\Components\Component;

/**
 * Horde\Components\Component\Dependency:: wraps PEAR dependency information.
 *
 * Copyright 2010-2024 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Dependency
{
    /**
     * The name of the dependency.
     *
     * @var string
     */
    private $_name = '';

    /**
     * The channel of the dependency.
     *
     * @var string
     */
    private $_channel = '';

    /**
     * The type of the dependency.
     *
     * @var string
     */
    private $_type = '';

    /**
     * Indicates if this is an optional dependency.
     */
    private bool $_optional = true;

    /**
     * Indicates if this is a package dependency.
     */
    private bool $_package = false;

    /**
    * Constructor.
    *
     * @param array $_dependency The dependency
                                               information.
     * @param Horde\Components\Component\Factory $_factory Helper factory.
    */
    public function __construct(
        private $_dependency,
        private readonly Factory $_factory
    ) {
        if (isset($_dependency['name'])) {
            $this->_name = $_dependency['name'];
        }
        if (isset($_dependency['channel'])) {
            $this->_channel = $_dependency['channel'];
        }
        if (isset($_dependency['optional'])
            && $_dependency['optional'] == 'no') {
            $this->_optional = false;
        }
        if (isset($_dependency['type'])) {
            $this->_type = $_dependency['type'];
        }
        if (isset($_dependency['type'])
            && $_dependency['type'] == 'pkg') {
            $this->_package = true;
        }
    }

    /**
     * Return the dependency in its component representation.
     *
     * @param array $options The options for resolving the component.
     *
     * @return Component The component.
     */
    public function getComponent($options = []): bool|\Horde\Components\Component
    {
        return $this->_factory->createResolver()
            ->resolveDependency($this, $options);
    }

    /**
     * Return the original dependency information.
     *
     * @return array The original dependency information.
     */
    public function getDependencyInformation(): array
    {
        return $this->_dependency;
    }

    /**
     * Is the dependency required?
     *
     * @return boolen True if the dependency is required.
     */
    public function isRequired(): bool
    {
        return !$this->_optional;
    }

    /**
     * Is this a package dependency?
     *
     * @return boolen True if the dependency is a package.
     */
    public function isPackage(): bool
    {
        return $this->_package;
    }

    /**
     * Is the dependency a Horde dependency?
     *
     * @return boolen True if it is a Horde dependency.
     */
    public function isHorde(): bool
    {
        if (empty($this->_channel)) {
            return false;
        }
        if ($this->_channel != 'pear.horde.org') {
            return false;
        }
        return true;
    }

    /**
     * Is this the PHP dependency?
     *
     * @return boolen True if it is the PHP dependency.
     */
    public function isPhp(): bool
    {
        if ($this->_type != 'php') {
            return false;
        }
        return true;
    }

    /**
     * Is this a PHP extension dependency?
     *
     * @return boolen True if it is a PHP extension dependency.
     */
    public function isExtension(): bool
    {
        if ($this->_type != 'ext') {
            return false;
        }
        return true;
    }

    /**
     * Is the dependency the PEAR base package?
     *
     * @return boolen True if it is the PEAR base package.
     */
    public function isPearBase(): bool
    {
        if ($this->_name == \PEAR::class && $this->_channel == 'pear.php.net') {
            return true;
        }
        return false;
    }

    /**
     * Return the package name for the dependency
     *
     * @return string The package name.
     */
    public function getName(): string
    {
        return $this->_name;
    }

    /**
     * Return the package channel for the dependency
     *
     * @return string The package channel.
     */
    public function getChannel(): string
    {
        return $this->_channel;
    }

    /**
     * Return the package channel or the type description for the dependency.
     *
     * @return string The package channel.
     */
    public function channelOrType()
    {
        if ($this->isExtension()) {
            return 'PHP Extension';
        } else {
            return $this->_channel;
        }
    }

    /**
     * Return the key for the dependency
     *
     * @return string The uniqe key for this dependency.
     */
    public function key(): string
    {
        return $this->_channel . '/' . $this->_name;
    }
}
