<?php
/**
 * Test the Update module.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Components\Unit\Components\Module;

use Horde\Components\Test\TestCase;

/**
 * Test the Update module.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
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
class UpdateTest extends TestCase
{
    public function testUpdateOption()
    {
        $this->assertMatchesRegularExpression('/-u,\s*--updatexml/', $this->getHelp());
    }

    public function testActionOption()
    {
        $this->assertMatchesRegularExpression('/-A ACTION,\s*--action=ACTION/m', $this->getHelp());
    }

    public function testXmlCreation()
    {
        $tmp_dir = \Horde_Util::createTempDir();
        file_put_contents(
            $tmp_dir . '/.gitignore',
            ''
        );
        mkdir($tmp_dir . '/horde');
        mkdir($tmp_dir . '/framework');
        mkdir($tmp_dir . '/framework/test');
        file_put_contents(
            $tmp_dir . '/framework/test/.horde.yml',
            "---
id: basic
name: Basic
full: Basic
description:
type: library
authors:
  -
version:
  release: 0.0.1
  api: 0.0.1
state:
  release: alpha
  api: alpha
license:
  identifier:
  uri:
dependencies:
  required:
    php: ^5
"
        );
        file_put_contents(
            $tmp_dir . '/framework/test/test.php',
            '<?php'
        );
        $_SERVER['argv'] = [
            'horde-components',
            '--updatexml',
            $tmp_dir . '/framework/test'
        ];
        $this->_callStrictComponents();
        $this->assertTrue(
            file_exists($tmp_dir . '/framework/test/package.xml')
        );
    }

    public function testXmlUpdate()
    {
        $this->assertMatchesRegularExpression(
            '/<file name="New.php" role="php" \/>/',
            $this->_simpleUpdate()
        );
    }

    public function testRetainTasks()
    {
        $this->assertMatchesRegularExpression(
            '#<tasks:replace from="@data_dir@" to="data_dir" type="pear-config" />#',
            $this->_simpleUpdate()
        );
    }

    public function testJavaScriptFiles()
    {
        $this->assertMatchesRegularExpression(
            '#<install as="js/test.js" name="js/test.js" />#',
            $this->_simpleUpdate()
        );
    }

    public function testMigrationFiles()
    {
        $this->assertMatchesRegularExpression(
            '#<install as="migration/test.sql" name="migration/test.sql" />#',
            $this->_simpleUpdate()
        );
    }

    public function testScriptFiles()
    {
        $this->assertMatchesRegularExpression(
            '#<install as="script.php" name="bin/script.php" />#',
            $this->_simpleUpdate()
        );
    }

    public function testIgnoredFile1()
    {
        $this->assertDoesNotMatchRegularExpression(
            '#IGNORE.txt#',
            $this->_simpleUpdate()
        );
    }

    public function testIgnoredFile2()
    {
        $this->assertDoesNotMatchRegularExpression(
            '#test1#',
            $this->_simpleUpdate()
        );
    }

    public function testNotIgnored()
    {
        $this->assertMatchesRegularExpression(
            '/<file name="test2" role="php" \/>/',
            $this->_simpleUpdate()
        );
    }

    private function _simpleUpdate()
    {
        $_SERVER['argv'] = [
            'horde-components',
            '--action=print',
            '--updatexml',
            __DIR__ . '/../../../fixture/framework/simple'
        ];
        return $this->_callStrictComponents();
    }

    /* /\** */
    /*  * @scenario */
    /*  *\/ */
    /* public function testEmptyChangelog() */
    /* { */
    /*     $this->given('the default Components setup') */
    /*         ->when('calling the package with the updatexml option with action "print" and a component with empty changelog') */
    /*         ->then('the new package.xml of the Horde component will have a changelog entry'); */
    /* } */


    /**
     * @todo Test (and possibly fix) three more scenarios:
     *  - invalid XML in the package.xml (e.g. tag missing)
     *  - empty file list
     *  - file list with just one entry.
     */
}
