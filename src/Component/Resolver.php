<?php
/**
 * Horde\Components\Component\Resolver:: resolves component names and dependencies
 * into component representations.
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
use Horde\Components\Exception;
use Horde\Components\Helper\Root as HelperRoot;

/**
 * Horde\Components\Component\Resolver:: resolves component names and dependencies
 * into component representations.
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
class Resolver
{
    /**
     * The list of remotes already generated.
     */
    private ?array $_remotes = null;

    /**
     * Constructor.
     *
     * @param HelperRoot $_root The repository root.
     * @param Factory $_factory Helper factory.
     */
    public function __construct(private readonly HelperRoot $_root, private readonly Factory $_factory)
    {
    }

    /**
     * Try to resolve a dependency into a component.
     *
     * @param Dependency $dependency The dependency.
     * @param array      $options    Additional options.
     * <pre>
     *  - allow_remote: May the resolver try to resolve to a remote channel?
     *  - order:        Order of stability preference.
     * </pre>
     *
     * @return Component|boolean The component if the name could be
     *                                      resolved.
     */
    public function resolveDependency(Dependency $dependency, $options): \Horde\Components\Component|bool
    {
        return $this->resolveName(
            $dependency->getName(),
            $dependency->getChannel(),
            $options
        );
    }

    /**
     * Try to resolve the given name and channel into a component.
     *
     * @param string $name    The name of the component.
     * @param string $channel The channel origin of the component.
     * @param array  $options Additional options.
     *
     * @return Component|boolean The component if the name could be
     *                                      resolved.
     */
    public function resolveName($name, $channel, $options): \Horde\Components\Component|bool
    {
        foreach ($this->_getAttempts($options) as $attempt) {
            if ($attempt == 'git' && $channel == 'pear.horde.org') {
                try {
                    $path = $this->_root->getPackageXml($name);
                    return $this->_factory->createSource(dirname($path));
                } catch (Exception) {
                }
            }
            if ($attempt == 'snapshot') {
                if ($local = $this->_identifyMatchingLocalPackage($name, $channel, $options)) {
                    return $this->_factory->createArchive(
                        $local
                    );
                }
            }
            if (!empty($options['allow_remote'])) {
                $remote = $this->_getRemote($channel);
                if ($remote->getLatestRelease($name, $attempt)) {
                    return $this->_factory->createRemote(
                        $name,
                        $attempt,
                        $channel,
                        $remote
                    );
                }
            }
        }
        return false;
    }

    /**
     * Return the order of resolve attempts.
     *
     * @param array $options Resolve options.
     *
     * @return array The list of attempts
     */
    private function _getAttempts($options)
    {
        if (isset($options['order'])) {
            return $options['order'];
        }
        $order = ['git', 'snapshot', 'stable', 'beta', 'alpha', 'devel'];
        if (!empty($options['snapshot'])) {
            $order = ['snapshot', 'stable', 'beta', 'alpha', 'devel', 'git'];
        }
        if (!empty($options['stable'])) {
            $order = ['stable', 'beta', 'alpha', 'devel', 'snapshot', 'git'];
        }
        if (!empty($options['beta'])) {
            $order = ['beta', 'stable', 'alpha', 'devel', 'snapshot', 'git'];
        }
        if (!empty($options['alpha'])) {
            $order = ['alpha', 'beta', 'stable', 'devel', 'snapshot', 'git'];
        }
        if (!empty($options['devel'])) {
            $order = ['devel', 'alpha', 'beta', 'stable', 'snapshot', 'git'];
        }
        if (empty($options['allow_remote'])) {
            $result = [];
            foreach ($order as $element) {
                if (in_array($element, ['git', 'snapshot'])) {
                    $result[] = $element;
                }
            }
            return $result;
        }
        return $order;
    }

    /**
     * Get a remote PEAR server handler for a specific channel.
     *
     * @param string $channel The channel name.
     *
     * @return \Horde_Pear_Remote The remote handler.
     */
    private function _getRemote($channel): \Horde_Pear_Remote
    {
        if (!isset($this->_remotes[$channel])) {
            $this->_remotes[$channel] = $this->_factory->createRemoteChannel(
                $channel
            );
        }
        return $this->_remotes[$channel];
    }

    /**
     * Identify a dependency that is available via a downloaded *.tgz archive.
     *
     * @param string $name    The component name.
     * @param string $channel The component channel.
     * @param array  $options Resolve options.
     *
     * @return string A path to the local archive if it was found.
     */
    public function _identifyMatchingLocalPackage($name, $channel, $options): bool|string
    {
        if (empty($options['sourcepath'])) {
            return false;
        }
        $source = $options['sourcepath'] . '/' . $channel;
        if (!file_exists($source)) {
            return false;
        }
        foreach (new \DirectoryIterator($source) as $file) {
            if (preg_match('/' . $name . '-[0-9]+(\.[0-9]+)+([a-z0-9]+)?/', $file->getBasename('.tgz'), $matches)) {
                return $file->getPathname();
            }
        }
        return false;
    }
}
