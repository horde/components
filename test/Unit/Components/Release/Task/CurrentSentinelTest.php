<?php
/**
 * Test the current sentinel release task.
 *
 * PHP Version 7
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Unit\Components\Release\Task;
use Horde\Components\TestCase;
use Horde\Components\Helper\Commit as HelperCommit;
/**
 * Test the current sentinel release task.
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
class CurrentSentinelTest extends TestCase
{
    public function testRunTaskWithoutCommit()
    {
        $tmp_dir = $this->_prepareApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(array('CurrentSentinel'), $package);
        $this->assertEquals(
            '---------
v4.0.1RC1
---------

TEST
',
            file_get_contents($tmp_dir . '/doc/CHANGES')
        );
        $this->assertEquals(
            'class Application {
public $version = \'4.0.1RC1\';
}
',
            file_get_contents($tmp_dir . '/lib/Application.php')
        );
    }

    public function testRunTaskWithoutCommitOnBundle()
    {
        $tmp_dir = $this->_prepareApplicationDirectory(true);
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(array('CurrentSentinel'), $package);
        $this->assertEquals(
            '---------
v4.0.1RC1
---------

TEST
',
            file_get_contents($tmp_dir . '/doc/CHANGES')
        );
        $this->assertEquals(
            'class Horde_Bundle {
const VERSION = \'4.0.1RC1\';
}
',
            file_get_contents($tmp_dir . '/lib/Bundle.php')
        );
    }

    public function testPretend()
    {
        $tmp_dir = $this->_prepareApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('CurrentSentinel', 'CommitPreRelease'),
            $package,
            array(
                'pretend' => true,
                'commit' => new HelperCommit(
                    $this->_output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                'Would set release version "4.0.1RC1" and api version "" in doc/changelog.yml, .horde.yml, package.xml, composer.json, doc/CHANGES, lib/Application.php now.',
                'Would run "git add doc/changelog.yml" now.',
                'Would run "git add .horde.yml" now.',
                'Would run "git add package.xml" now.',
                'Would run "git add composer.json" now.',
                'Would run "git add doc/CHANGES" now.',
                'Would run "git add lib/Application.php" now.',
                'Would run "git commit -m "Released Horde-4.0.1RC1"" now.'
            ),
            $this->_output->getOutput()
        );
    }

    public function testPretendOnBundle()
    {
        $tmp_dir = $this->_prepareApplicationDirectory(true);
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('CurrentSentinel', 'CommitPreRelease'),
            $package,
            array(
                'pretend' => true,
                'commit' => new HelperCommit(
                    $this->_output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                'Would set release version "4.0.1RC1" and api version "" in doc/changelog.yml, .horde.yml, package.xml, composer.json, doc/CHANGES, lib/Bundle.php now.',
                'Would run "git add doc/changelog.yml" now.',
                'Would run "git add .horde.yml" now.',
                'Would run "git add package.xml" now.',
                'Would run "git add composer.json" now.',
                'Would run "git add doc/CHANGES" now.',
                'Would run "git add lib/Bundle.php" now.',
                'Would run "git commit -m "Released Horde-4.0.1RC1"" now.'
            ),
            $this->_output->getOutput()
        );
    }

    private function _prepareApplicationDirectory($bundle = false)
    {
        $tmp_dir = $this->getTemporaryDirectory();
        mkdir($tmp_dir . '/doc');
        file_put_contents(
            $tmp_dir . '/doc/changelog.yml',
            '---
4.0.1RC1:
  api: 4.0.0
  date: 2017-12-31
  notes: |
    TEST
  state:
    release: stable
    api: stable
  license:
    identifier: ~
    uri: ~
'
        );
        file_put_contents(
            $tmp_dir . '/doc/CHANGES',
            '---
OLD
---
TEST'
        );
        mkdir($tmp_dir . '/lib');
        if ($bundle) {
            file_put_contents(
                $tmp_dir . '/lib/Bundle.php',
                'class Horde_Bundle {
const VERSION = \'0.0.0\';
}
'
            );
        } else {
            file_put_contents(
                $tmp_dir . '/lib/Application.php',
                'class Application {
public $version = \'0.0.0\';
}
'
            );
        }
        file_put_contents(
            $tmp_dir . '/package.xml',
            '<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://pear.php.net/dtd/package-2.0">
 <name>Horde</name>
 <version>
  <release>4.0.1RC1</release>
  <api>4.0.0</api>
 </version>
 <stability>
  <release>stable</release>
  <api>stable</api>
 </stability>
 <changelog>
  <release>
   <version>
    <release>4.0.1RC1</release>
    <api>4.0.0</api>
   </version>
   <stability>
    <release>stable</release>
    <api>stable</api>
   </stability>
  </release>
 </changelog>
</package>'
        );
        file_put_contents(
            $tmp_dir . '/.horde.yml',
            'id: Horde
name: Horde
type: application
full: Horde
description: Horde
type: application
authors: []
version:
  release: 4.0.1RC1
  api: 4.0.0
state:
  release: stable
  api: stable
license:
  identifier: ~
  uri: ~
dependencies: []
'
        );
        return $tmp_dir;
    }
}
