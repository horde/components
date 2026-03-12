<?php

/**
 * Test base.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Test;

use Horde\Components\Component\ComponentDirectory;
use Horde\Components\Component\Source;
use Horde\Components\Components;
use Horde\Components\Dependencies\Injector;
use Horde\Components\Release\Notes as ReleaseNotes;
use Horde\Components\Test\Stub\Output;
use DirectoryIterator;
use Horde_Test_Stub_Cli;
use Horde_Util;

/**
 * Test base.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @coversNothing
 */
class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Output
     */
    protected $_output;

    /**
     * @var int|null
     */
    protected $old_errorreporting;

    /**
     * @var string|null
     */
    protected $cwd;

    /**
     * Temporary fixture directories created during test.
     * Cleaned up automatically in tearDown.
     *
     * @var array<string>
     */
    protected array $tempFixtures = [];

    protected function getComponentFactory(
        $arguments = [],
        $options = []
    ) {
        $dependencies = new Injector();
        return $dependencies->getComponentFactory();
    }

    protected function getComponent(
        $directory,
        $arguments = [],
        $options = []
    ) {
        $dependencies = new Injector();
        $factory = $dependencies->getComponentFactory();
        return new Source(
            new ComponentDirectory($directory),
            $dependencies->getInstance(ReleaseNotes::class),
            $factory
        );
    }

    protected function getReleaseTask($name, $package)
    {
        $dependencies = new Injector();
        $this->_output = new Output();
        $dependencies->setInstance('Output', $this->_output);
        $dependencies->setInstance('Horde\Components\Output', $this->_output);
        return $dependencies->getReleaseTasks()->getTask($name, $package);
    }

    protected function getReleaseTasks()
    {
        $dependencies = new Injector();
        $this->_output = new Output();
        $dependencies->setInstance('Output', $this->_output);
        $dependencies->setInstance('Horde\Components\Output', $this->_output);
        return $dependencies->getReleaseTasks();
    }

    protected function getTemporaryDirectory()
    {
        return Horde_Util::createTempDir();
    }

    /**
     * Copy a test fixture to a temporary directory.
     *
     * Creates an isolated copy of a fixture directory for tests that need
     * to modify files. The temporary directory is automatically cleaned up
     * in tearDown().
     *
     * @param string $fixtureName Name of fixture directory (e.g., 'simple')
     * @return string Path to temporary fixture copy
     */
    protected function copyFixture(string $fixtureName): string
    {
        $fixtureSource = __DIR__ . '/fixtures/' . $fixtureName;

        if (!is_dir($fixtureSource)) {
            throw new \RuntimeException("Fixture not found: {$fixtureName}");
        }

        $tempDir = $this->getTemporaryDirectory();
        $this->copyRecursive($fixtureSource, $tempDir);

        // Track for cleanup
        $this->tempFixtures[] = $tempDir;

        return $tempDir;
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $source Source directory
     * @param string $dest Destination directory
     */
    protected function copyRecursive(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destPath = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $destPath);
            }
        }
    }

    /**
     * Recursively remove a directory.
     *
     * @param string $dir Directory to remove
     */
    protected function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    protected function getHelp()
    {
        $_SERVER['argv'] = ['horde-components', '--help'];
        return $this->_callStrictComponents();
    }

    protected function getActionHelp($action)
    {
        $_SERVER['argv'] = ['horde-components', 'help', $action];
        return $this->_callStrictComponents();
    }

    protected function _callStrictComponents(array $parameters = [])
    {
        return $this->_callComponents($parameters, [$this, '_callStrict']);
    }

    protected function _callUnstrictComponents(array $parameters = [])
    {
        return $this->_callComponents($parameters, [$this, '_callUnstrict']);
    }

    private function _callComponents(array $parameters, $callback)
    {
        ob_start();
        $stream = fopen('php://temp', 'r+');
        $parameters['parser']['class'] = 'Horde_Test_Stub_Parser';
        $parameters['dependencies'] = new Injector();
        $parameters['dependencies']->setInstance(
            'Horde_Cli',
            new Horde_Test_Stub_Cli(['output' => $stream])
        );
        call_user_func_array($callback, [$parameters]);
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        $output .= ob_get_contents();
        ob_end_clean();
        return $output;
    }

    private function _callUnstrict(array $parameters)
    {
        $old_errorreporting = error_reporting(E_ALL & ~(E_DEPRECATED));
        error_reporting(E_ALL & ~ (E_DEPRECATED));
        $this->_callStrict($parameters);
        error_reporting($old_errorreporting);
    }

    private function _callStrict(array $parameters)
    {
        Components::main($parameters);
    }

    protected function fileRegexpPresent($regex, $dir)
    {
        $files = [];
        $found = false;
        foreach (new DirectoryIterator($dir) as $file) {
            if (preg_match($regex, $file->getBasename('.tgz'))) {
                $found = true;
            }
            $files[] = $file->getPath();
        }
        $this->assertTrue(
            $found,
            sprintf("File \"%s\" not found in \n\n%s\n", $regex, join("\n", $files))
        );
    }

    protected function setPearGlobals()
    {
        $GLOBALS['_PEAR_ERRORSTACK_DEFAULT_CALLBACK'] = [
            '*' => false,
        ];
        $GLOBALS['_PEAR_ERRORSTACK_DEFAULT_LOGGER'] = false;
        $GLOBALS['_PEAR_ERRORSTACK_OVERRIDE_CALLBACK'] = [];
    }

    protected function changeDirectory($path)
    {
        $this->cwd = getcwd();
        chdir($path);
    }

    protected function lessStrict()
    {
        $this->old_errorreporting = error_reporting(E_ALL & ~(E_DEPRECATED));
    }

    public function tearDown(): void
    {
        // Clean up temporary fixtures
        foreach ($this->tempFixtures as $tempDir) {
            $this->removeRecursive($tempDir);
        }
        $this->tempFixtures = [];

        if (!empty($this->cwd)) {
            chdir($this->cwd);
        }
        if (!empty($this->old_errorreporting)) {
            error_reporting($this->old_errorreporting);
        }
    }
}
