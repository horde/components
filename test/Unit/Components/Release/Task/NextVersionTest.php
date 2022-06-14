<?php
/**
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Unit\Components\Release\Task;
use Horde\Components\Test\TestCase;
use Horde\Components\Helper\Commit as HelperCommit;

/**
 * Tests the next-version release task.
 *
 * @category   Horde
 * @package    Components
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class NextVersionTest extends TestCase
{
    public function testRunTaskWithoutCommit()
    {
        $tmp_dir = $this->_prepareApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextVersion'),
            $package,
            array('next_version' => '5.0.1-git', 'next_note' => '')
        );
        $this->assertEquals(
            '---
5.0.1-git:
  api: 5.0.0
  date: 2017-12-31
  notes: |+
    ' . '
  state:
    release: stable
    api: stable
  license:
    identifier: ~
    uri: ~
5.0.0:
  api: 5.0.0
  date: 2017-12-31
  notes: |
    TEST
  state:
    release: stable
    api: stable
  license:
    identifier: ~
    uri: ~
',
            file_get_contents($tmp_dir . '/doc/changelog.yml')
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
        $this->assertEquals(
            '<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://pear.php.net/dtd/package-2.0">
 <name>Horde</name>
 <version>
  <release>5.0.1</release>
  <api>5.0.0</api>
 </version>
 <stability>
  <release>stable</release>
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
   <date>2017-12-31</date>
   <license uri=""></license>
   <notes>
* TEST
   </notes>
  </release>
  <release>
   <version>
    <release>5.0.1</release>
    <api>5.0.0</api>
   </version>
   <stability>
    <release>stable</release>
    <api>stable</api>
   </stability>
   <date>2017-12-31</date>
   <license uri=""></license>
   <notes>
* '.'
   </notes>
  </release>
 </changelog>
</package>
',
            file_get_contents($tmp_dir . '/package.xml')
        );
        $this->assertEquals(
            '---
id: Horde
name: Horde
type: application
full: Horde
description: Horde
authors: []
version:
  release: 5.0.1-git
  api: 5.0.0
state:
  release: stable
  api: stable
license:
  identifier: ~
  uri: ~
dependencies: []
',
            file_get_contents($tmp_dir . '/.horde.yml')
        );
    }

    public function testPretend()
    {
        $tmp_dir = $this->_prepareApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextVersion', 'CommitPostRelease'),
            $package,
            array(
                'next_version' => '5.0.0-git',
                'next_note' => '',
                'pretend' => true,
                'commit' => new HelperCommit(
                    $this->_output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                'Would add next version "5.0.0-git" with the initial note "" to .horde.yml, package.xml, composer.json, doc/CHANGES, lib/Application.php, doc/changelog.yml now.',
                'Would run "git add .horde.yml" now.',
                'Would run "git add package.xml" now.',
                'Would run "git add composer.json" now.',
                'Would run "git add doc/CHANGES" now.',
                'Would run "git add lib/Application.php" now.',
                'Would run "git add doc/changelog.yml" now.',
                'Would run "git commit -m "Development mode for Horde-5.0.0"" now.'
            ),
            $this->_output->getOutput()
        );
    }

    public function testPretendWithoutVersion()
    {
        $tmp_dir = $this->_prepareApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextVersion', 'CommitPostRelease'),
            $package,
            array(
                'next_note' => '',
                'pretend' => true,
                'commit' => new HelperCommit(
                    $this->_output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                'Would add next version "5.0.1" with the initial note "" to .horde.yml, package.xml, composer.json, doc/CHANGES, lib/Application.php, doc/changelog.yml now.',
                'Would run "git add .horde.yml" now.',
                'Would run "git add package.xml" now.',
                'Would run "git add composer.json" now.',
                'Would run "git add doc/CHANGES" now.',
                'Would run "git add lib/Application.php" now.',
                'Would run "git add doc/changelog.yml" now.',
                'Would run "git commit -m "Development mode for Horde-5.0.1"" now.'
            ),
            $this->_output->getOutput()
        );
    }

    public function testPretendAlphaWithoutVersion()
    {
        $tmp_dir = $this->_prepareAlphaApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextVersion', 'CommitPostRelease'),
            $package,
            array(
                'next_note' => '',
                'pretend' => true,
                'commit' => new HelperCommit(
                    $this->_output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                'Would add next version "5.0.0alpha2" with the initial note "" to .horde.yml, package.xml, composer.json, doc/CHANGES, lib/Application.php, doc/changelog.yml now.',
                'Would run "git add .horde.yml" now.',
                'Would run "git add package.xml" now.',
                'Would run "git add composer.json" now.',
                'Would run "git add doc/CHANGES" now.',
                'Would run "git add lib/Application.php" now.',
                'Would run "git add doc/changelog.yml" now.',
                'Would run "git commit -m "Development mode for Horde-5.0.0alpha2"" now.'
            ),
            $this->_output->getOutput()
        );
    }

    public function testPretendAlphaWithoutVersionMinorPart()
    {
        $tmp_dir = $this->_prepareAlphaApplicationDirectory();
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($tmp_dir);
        $tasks->run(
            array('NextVersion', 'CommitPostRelease'),
            $package,
            array(
                'next_note' => '',
                'version_part' => 'minor',
                'pretend' => true,
                'commit' => new HelperCommit(
                    $this->_output,
                    array('pretend' => true)
                )
            )
        );
        $this->assertEquals(
            array(
                'Would add next version "5.1.0" with the initial note "" to .horde.yml, package.xml, composer.json, doc/CHANGES, lib/Application.php, doc/changelog.yml now.',
                'Would run "git add .horde.yml" now.',
                'Would run "git add package.xml" now.',
                'Would run "git add composer.json" now.',
                'Would run "git add doc/CHANGES" now.',
                'Would run "git add lib/Application.php" now.',
                'Would run "git add doc/changelog.yml" now.',
                'Would run "git commit -m "Development mode for Horde-5.1.0"" now.'
            ),
            $this->_output->getOutput()
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
authors: []
version:
  release: 5.0.0
  api: 5.0.0
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
  state:
    release: alpha
    api: stable
  license:
    identifier: ~
    uri: ~
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
authors: []
version:
  release: 5.0.0alpha1
  api: 5.0.0
state:
  release: alpha
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
