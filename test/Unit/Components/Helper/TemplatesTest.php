<?php
/**
 * Test the template machinery.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Unit\Components\Helper;

use Horde\Components\Exception;
use Horde\Components\Helper\Templates\Directory as TemplatesDirectory;
use Horde\Components\Helper\Templates\RecursiveDirectory as TemplatesRecursiveDirectory;
use Horde\Components\Helper\Templates\Single as TemplatesSingle;
use Horde\Components\Test\TestCase;

/**
 * Test the template machinery.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
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
class TemplatesTest extends TestCase
{
    public function testWrite()
    {
        $tdir =  $this->getTemporaryDirectory();
        $templates = new TemplatesSingle(
            __DIR__ . '/../../../fixture/templates',
            $tdir,
            'simple',
            'target'
        );
        $templates->write();
        $this->assertTrue(file_exists($tdir . '/target'));
    }

    public function testSource()
    {
        $tdir =  $this->getTemporaryDirectory();
        $templates = new TemplatesSingle(
            __DIR__ . '/../../../fixture/templates',
            $tdir,
            'simple',
            'target'
        );
        $templates->write();
        $this->assertEquals(
            "SIMPLE\n",
            file_get_contents($tdir . '/target')
        );
    }

    public function testMissingSource()
    {
        $this->expectException(Exception::class);
        $source = __DIR__ . '/NO_SUCH_TEMPLATE';
        $templates = new TemplatesSingle($source, '', '', '');
    }

    public function testVariables()
    {
        $tdir =  $this->getTemporaryDirectory();
        $templates = new TemplatesSingle(
            __DIR__ . '/../../../fixture/templates',
            $tdir,
            'variables',
            'target'
        );
        $templates->write(['1' => 'One', '2' => 'Two']);
        $this->assertEquals(
            "One : Two\n",
            file_get_contents($tdir . '/target')
        );
    }

    public function testPhp()
    {
        $tdir =  $this->getTemporaryDirectory();
        $templates = new TemplatesSingle(
            __DIR__ . '/../../../fixture/templates',
            $tdir,
            'php',
            'target'
        );
        $templates->write();
        $this->assertEquals(
            "test",
            file_get_contents($tdir . '/target')
        );
    }

    public function testInput()
    {
        $tdir =  $this->getTemporaryDirectory();
        $templates = new TemplatesSingle(
            __DIR__ . '/../../../fixture/templates',
            $tdir,
            'input',
            'target'
        );
        $templates->write(['input' => 'SOME INPUT']);
        $this->assertEquals(
            "SOME INPUT",
            file_get_contents($tdir . '/target')
        );
    }

    public function testDirectory()
    {
        $tdir =  $this->getTemporaryDirectory();
        $templates = new TemplatesDirectory(
            __DIR__ . '/../../../fixture/templates/dir',
            $tdir
        );
        $templates->write(['one' => 'One', 'two' => 'Two']);
        $this->assertEquals(
            "One",
            file_get_contents($tdir . '/one')
        );
        $this->assertEquals(
            "Two",
            file_get_contents($tdir . '/two')
        );
    }

    public function testMissingDirectory()
    {
        $this->expectException(Exception::class);
        new TemplatesDirectory(
            __DIR__ . '/../../../fixture/templates/NOSUCHDIR',
            $this->getTemporaryDirectory()
        );
    }

    public function testMissingTargetDirectory()
    {
        $tdir =  $this->getTemporaryDirectory() . DIRECTORY_SEPARATOR
            . 'a' .'/b';
        $templates = new TemplatesDirectory(
            __DIR__ . '/../../../fixture/templates/dir',
            $tdir
        );
        $templates->write(['one' => 'One', 'two' => 'Two']);
        $this->assertEquals(
            "One",
            file_get_contents($tdir . '/one')
        );
        $this->assertEquals(
            "Two",
            file_get_contents($tdir . '/two')
        );
    }

    public function testTargetRewrite()
    {
        $tdir =  $this->getTemporaryDirectory();
        $templates = new TemplatesDirectory(
            __DIR__ . '/../../../fixture/templates/rewrite',
            $tdir
        );
        $templates->write(['one' => 'One']);
        $this->assertEquals(
            "One",
            file_get_contents($tdir . '/rewritten')
        );
    }

    public function testRecursiveDirectory()
    {
        $tdir =  $this->getTemporaryDirectory();
        $templates = new TemplatesRecursiveDirectory(
            __DIR__ . '/../../../fixture/templates/rec-dir',
            $tdir
        );
        $templates->write(['one' => 'One', 'two' => 'Two']);
        $this->assertEquals(
            "One",
            file_get_contents($tdir . '/one/one')
        );
        $this->assertEquals(
            "Two",
            file_get_contents($tdir . '/two/two')
        );
    }

    public function testMissingRecursiveDirectory()
    {
        $this->expectException(Exception::class);
        new TemplatesRecursiveDirectory(
            __DIR__ . '/../../../fixture/templates/NOSUCHDIR',
            $this->getTemporaryDirectory()
        );
    }

    public function testMissingTargetRecursiveDirectory()
    {
        $tdir =  $this->getTemporaryDirectory() . DIRECTORY_SEPARATOR
            . 'a' .'/b';
        $templates = new TemplatesRecursiveDirectory(
            __DIR__ . '/../../../fixture/templates/rec-dir',
            $tdir
        );
        $templates->write(['one' => 'One', 'two' => 'Two']);
        $this->assertEquals(
            "One",
            file_get_contents($tdir . '/one/one')
        );
        $this->assertEquals(
            "Two",
            file_get_contents($tdir . '/two/two')
        );
    }
}
