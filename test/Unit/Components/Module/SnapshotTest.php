<?php
/**
 * Test the Snapshot module.
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

use Horde\Components\Exception\Pear as ExceptionPear;
use Horde\Components\Test\TestCase;

/**
 * Test the Snapshot module.
 *
 * Copyright 2010-2024 Horde LLC (http://www.horde.org/)
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
class SnapshotTest extends TestCase
{
    public function testSnapshotOption()
    {
        $this->assertMatchesRegularExpression('/-z,\s*--snapshot/', $this->getHelp());
    }

    public function testSnapshotAction()
    {
        $this->assertMatchesRegularExpression('/ACTION "snapshot"/', $this->getActionHelp('snapshot'));
    }

    public function testSnapshot()
    {
        $tmp_dir = \Horde_Util::createTempDir();
        $_SERVER['argv'] = [
            'horde-components',
            '--verbose',
            '--snapshot',
            '--destination=' . $tmp_dir,
            __DIR__ . '/../../../fixture/framework/Install'
        ];
        $this->_callUnstrictComponents();
        $this->fileRegexpPresent(
            '/Install-[0-9]+(\.[0-9]+)+([a-z0-9]+)?/',
            $tmp_dir
        );
    }

    public function testKeepVersion()
    {
        $tmp_dir = \Horde_Util::createTempDir();
        $_SERVER['argv'] = [
            'horde-components',
            '--keep-version',
            '--snapshot',
            '--destination=' . $tmp_dir,
            __DIR__ . '/../../../fixture/framework/Install'
        ];
        $this->_callUnstrictComponents();
        $this->fileRegexpPresent('/Install-0.0.1/', $tmp_dir);
    }

    public function testError()
    {
        $this->setPearGlobals();
        $cwd = getcwd();
        $tmp_dir = \Horde_Util::createTempDir();
        $_SERVER['argv'] = [
            'horde-components',
            '--verbose',
            '--snapshot',
            '--destination=' . $tmp_dir,
            __DIR__ . '/../../../fixture/simple'
        ];
        try {
            $this->_callUnstrictComponents();
        } catch (ExceptionPear $e) {
            ob_end_clean();
            $this->assertStringContainsString(
                'PEAR_Packagefile_v2::toTgz: invalid package.xml',
                (string) $e
            );
            $this->assertStringContainsString(
                'Old.php" in package.xml does not exist',
                $e
            );
        }
        chdir($cwd);
    }
}
