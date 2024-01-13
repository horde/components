<?php
/**
 * Test the timestamp release task.
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

use Horde\Components\Component\Source as SourceComponent;
use Horde\Components\Helper\Commit as HelperCommit;
use Horde\Components\Test\TestCase;
use Horde\Components\Wrapper\ChangelogYml;

/**
 * Test the timestamp release task.
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
class TimestampTest extends TestCase
{
    protected $_fixture;

    public function setUp(): void
    {
        $this->_fixture = __DIR__ . '/../../../../fixture/simple';
    }

    public function testPreValidateSucceeds()
    {
        $package = $this->getComponent($this->_fixture);
        $task = $this->getReleaseTask('timestamp', $package);
        $this->assertEquals([], $task->preValidate([]));
    }

    public function testPreValidateFails()
    {
        $package = $this->getComponent($this->_fixture . '/NO_SUCH_PACKAGE');
        $task = $this->getReleaseTask('timestamp', $package);
        $this->assertFalse($task->preValidate([]) === []);
    }

    public function testRunTaskWithoutCommit()
    {
        $tasks = $this->getReleaseTasks();
        $package = $this->_getValidPackage();
        $package->expects($this->once())
            ->method('timestamp');
        $tasks->run(['timestamp'], $package);
    }

    public function testPretend()
    {
        $this->markTestSkipped('Release no longer possible with outdated package.xml');
        $tasks = $this->getReleaseTasks();
        $package = $this->getComponent($this->_fixture);
        $tasks->run(
            ['Timestamp', 'CommitPreRelease'],
            $package,
            [
                'pretend' => true,
                'commit' => new HelperCommit(
                    $this->_output,
                    ['pretend' => true]
                )
            ]
        );
        $this->assertEquals(
            [
                sprintf('Would timestamp "%s" now and synchronize its change log.', realpath($this->_fixture . '/package.xml')),
                sprintf('Would run "git add %s" now.', realpath($this->_fixture . '/package.xml')),
                'Would run "git commit -m "Released Fixture-0.0.1"" now.'
            ],
            $this->_output->getOutput()
        );
    }

    private function _getValidPackage()
    {
        $wrapper = $this->getMockBuilder(ChangelogYml::class)->disableOriginalConstructor()->getMock();
        $wrapper->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));
        $package = $this->getMockBuilder(SourceComponent::class)->disableOriginalConstructor()->getMock();
        $package->expects($this->any())
            ->method('getWrapper')
            ->will(($this->returnValue($wrapper)));
        return $package;
    }
}
