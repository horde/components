<?php
/**
 * Test base.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components;
use Horde\Components\Dependencies\Injector;
use Horde\Components\Components;
use Horde\Components\Component\Source;
use Horde\Components\Stub\Output;
use Horde\Components\Stub\Config;
use Horde\Components\Release\Notes as ReleaseNotes;

/**
 * Test base.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TestCase extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Output
     */
    protected $_output;

    protected function getComponentFactory(
        $arguments = array(), $options = array()
    )
    {
        $dependencies = new Injector();
        $config = new Config($arguments, $options);
        $dependencies->initConfig($config);
        return $dependencies->getComponentFactory();
    }

    protected function getComponent(
        $directory, $arguments = array(), $options = array()
    )
    {
        $dependencies = new Injector();
        $config = new Config($arguments, $options);
        $dependencies->initConfig($config);
        $factory = $dependencies->getComponentFactory();
        return new Source(
            $directory, $config, $dependencies->getInstance(ReleaseNotes::class), $factory
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
        return \Horde_Util::createTempDir();
    }

    protected function getHelp()
    {
        $_SERVER['argv'] = array('horde-components', '--help');
        return $this->_callStrictComponents();
    }

    protected function getActionHelp($action)
    {
        $_SERVER['argv'] = array('horde-components', 'help', $action);
        return $this->_callStrictComponents();
    }

    protected function _callStrictComponents(array $parameters = array())
    {
        return $this->_callComponents($parameters, array($this, '_callStrict'));
    }

    protected function _callUnstrictComponents(array $parameters = array())
    {
        return $this->_callComponents($parameters, array($this, '_callUnstrict'));
    }

    private function _callComponents(array $parameters, $callback)
    {
        ob_start();
        $stream = fopen('php://temp', 'r+');
        $parameters['parser']['class'] = 'Horde_Test_Stub_Parser';
        $parameters['dependencies'] = new Injector();
        $parameters['dependencies']->setInstance(
            'Horde_Cli',
            new \Horde_Test_Stub_Cli(array('output' => $stream))
        );
        call_user_func_array($callback, array($parameters));
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        $output .= ob_get_contents();
        ob_end_clean();
        return $output;
    }

    private function _callUnstrict(array $parameters)
    {
        $old_errorreporting = error_reporting(E_ALL & ~(E_STRICT | E_DEPRECATED));
        error_reporting(E_ALL & ~(E_STRICT | E_DEPRECATED));
        $this->_callStrict($parameters);
        error_reporting($old_errorreporting);
    }

    private function _callStrict(array $parameters)
    {
        Components::main($parameters);
    }

    protected function fileRegexpPresent($regex, $dir)
    {
        $files = array();
        $found = false;
        foreach (new \DirectoryIterator($dir) as $file) {
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
        $GLOBALS['_PEAR_ERRORSTACK_DEFAULT_CALLBACK'] = array(
            '*' => false,
        );
        $GLOBALS['_PEAR_ERRORSTACK_DEFAULT_LOGGER'] = false;
        $GLOBALS['_PEAR_ERRORSTACK_OVERRIDE_CALLBACK'] = array();
    }

    protected function changeDirectory($path)
    {
        $this->cwd = getcwd();
        chdir($path);
    }

    protected function lessStrict()
    {
        $this->old_errorreporting = error_reporting(E_ALL & ~(E_STRICT | E_DEPRECATED));
    }

    public function tearDown()
    {
        if (!empty($this->cwd)) {
            chdir($this->cwd);
        }
        if (!empty($this->old_errorreporting)) {
            error_reporting($this->old_errorreporting);
        }
    }
}
