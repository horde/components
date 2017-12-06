<?php
/**
 * Test the next sentinel release task.
 *
 * PHP version 5
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

/**
 * Test the next sentinel release task.
 *
 * Copyright 2011-2017 Horde LLC (http://www.horde.org/)
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
class Components_Unit_Components_Release_Task_NextSentinelTest
extends Components_TestCase
{
    public function testRunTaskWithoutCommit()
    {
        $tmp_dir = $this->_prepareApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextVersion', 'NextSentinel'),
            $package,
            array('next_version' => '5.0.1-git', 'next_note' => '')
        );
        $this->assertEquals(
            '----------
v5.0.1-git
----------




------
v5.0.0
------

TEST
',
            file_get_contents($tmp_dir . '/doc/CHANGES')
        );
        $this->assertEquals(
            'class Application {
    public $version = \'5.0.1-git\';
}
',
            file_get_contents($tmp_dir . '/lib/Application.php')
        );
    }

    public function testPretend()
    {
        $tmp_dir = $this->_prepareApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextSentinel', 'CommitPostRelease'),
            $package,
            array(
                'next_version' => '5.0.0-git',
                'pretend' => true,
                'commit' => new Components_Helper_Commit(
                    $this->output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                sprintf('Would replace sentinel in %s/lib/Application.php with "5.0.0-git" now.', $tmp_dir),
                sprintf('Would run "git add lib/Application.php" now.', $tmp_dir),
                'Would run "git commit -m "Development mode for Horde-5.0.0"" now.'
            ),
            $this->output->getOutput()
        );
    }

    public function testPretendWithoutVersion()
    {
        $tmp_dir = $this->_prepareApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextVersion', 'NextSentinel', 'CommitPostRelease'),
            $package,
            array(
                'next_note' => '',
                'pretend' => true,
                'commit' => new Components_Helper_Commit(
                    $this->output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                sprintf('Would add next version "5.0.1" with the initial note "" to .horde.yml, package.xml, doc/CHANGES, doc/changelog.yml now.', $tmp_dir),
                sprintf('Would replace sentinel in %s/lib/Application.php with "5.0.1-git" now.', $tmp_dir),
                //sprintf('Would extend sentinel in %s/doc/CHANGES with "5.0.1-git" now.', $tmp_dir),
                sprintf('Would run "git add .horde.yml" now.', $tmp_dir),
                sprintf('Would run "git add package.xml" now.', $tmp_dir),
                sprintf('Would run "git add doc/CHANGES" now.', $tmp_dir),
                sprintf('Would run "git add doc/changelog.yml" now.', $tmp_dir),
                sprintf('Would run "git add lib/Application.php" now.', $tmp_dir),
                'Would run "git commit -m "Development mode for Horde-5.0.1"" now.'
            ),
            $this->output->getOutput()
        );
    }

    public function testPretendAlphaWithoutVersion()
    {
        $tmp_dir = $this->_prepareAlphaApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextVersion', 'NextSentinel', 'CommitPostRelease'),
            $package,
            array(
                'next_note' => '',
                'pretend' => true,
                'commit' => new Components_Helper_Commit(
                    $this->output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                sprintf('Would add next version "5.0.0alpha2" with the initial note "" to .horde.yml, package.xml, doc/CHANGES, doc/changelog.yml now.', $tmp_dir),
                sprintf('Would replace sentinel in %s/lib/Application.php with "5.0.0-git" now.', $tmp_dir),
                sprintf('Would run "git add .horde.yml" now.', $tmp_dir),
                sprintf('Would run "git add package.xml" now.', $tmp_dir),
                sprintf('Would run "git add doc/CHANGES" now.', $tmp_dir),
                sprintf('Would run "git add doc/changelog.yml" now.', $tmp_dir),
                sprintf('Would run "git add lib/Application.php" now.', $tmp_dir),
                'Would run "git commit -m "Development mode for Horde-5.0.0alpha2"" now.'
            ),
            $this->output->getOutput()
        );
    }

    private function _prepareApplicationDirectory()
    {
        $tmp_dir = $this->getTemporaryDirectory();
        mkdir($tmp_dir . '/doc');
        file_put_contents(
            $tmp_dir . '/doc/changelog.yml',
            '---
5.0.0:
  api: 5.0.0
  date: 2017-12-31
  notes: |
    TEST
'
        );
        file_put_contents(
            $tmp_dir . '/doc/CHANGES',
            '------
v5.0.0
------

TEST
'
        );
        mkdir($tmp_dir . '/lib');
        file_put_contents(
            $tmp_dir . '/lib/Application.php',
            'class Application {
    public $version = \'5.0.0\';
}
'
        );
        file_put_contents(
            $tmp_dir . '/package.xml',
            '<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://pear.php.net/dtd/package-2.0">
 <name>Horde</name>
 <version>
  <release>5.0.0</release>
  <api>5.0.0</api>
 </version>
 <stability>
  <release>alpha</release>
  <api>stable</api>
 </stability>
 <changelog>
  <release>
   <version>
    <release>5.0.0</release>
    <api>5.0.0</api>
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
version:
  release: 5.0.0
  api: 5.0.0
state:
  release: stable
  api: stable
license:
  identifier: ~
  uri: ~
dependencies:
'
        );
        return $tmp_dir;
    }

    private function _prepareAlphaApplicationDirectory()
    {
        $tmp_dir = $this->getTemporaryDirectory();
        mkdir($tmp_dir . '/doc');
        file_put_contents(
            $tmp_dir . '/doc/changelog.yml',
            '---
5.0.0alpha1:
  api: 5.0.0
  date: 2017-12-31
  notes: |
    TEST
'
        );
        file_put_contents(
            $tmp_dir . '/doc/CHANGES',
            '------
v5.0.0
------

TEST'
        );
        mkdir($tmp_dir . '/lib');
        file_put_contents(
            $tmp_dir . '/lib/Application.php',
            'class Application {
    public $version = \'5.0.0\';
}
'
        );
        file_put_contents(
            $tmp_dir . '/package.xml',
            '<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://pear.php.net/dtd/package-2.0">
 <name>Horde</name>
 <version>
  <release>5.0.0alpha1</release>
  <api>5.0.0</api>
 </version>
 <stability>
  <release>alpha</release>
  <api>stable</api>
 </stability>
 <changelog>
  <release>
   <version>
    <release>5.0.0alpha1</release>
    <api>5.0.0</api>
   </version>
   <stability>
    <release>alpha</release>
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
full: Horde
description: Horde
type: application
version:
  release: 5.0.0alpha1
  api: 5.0.0
state:
  release: alpha
  api: stable
license:
  identifier: ~
  uri: ~
dependencies:
'
        );
        return $tmp_dir;
    }
}
