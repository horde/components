<?php
/**
 * Test the document fetching helper.
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

use Horde\Components\Helper\DocsOrigin as HelperDocsOrigin;
use Horde\Components\Test\TestCase;

/**
 * Test the document fetching helper.
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
class DocsOriginTest extends TestCase
{
    public function testEmpty()
    {
        $do = __DIR__ . '/../../../fixture/docsorigin/empty';
        $docs_origin = new HelperDocsOrigin($do, $this->_getClient());
        $this->assertEquals(
            [],
            $docs_origin->getDocuments()
        );
    }

    public function testSimple()
    {
        $this->markTestIncomplete();
        $do = __DIR__ . '/../../../fixture/docsorigin/simple';
        $docs_origin = new HelperDocsOrigin($do, $this->_getClient());
        $this->assertEquals(
            ['doc/TEST' => 'http://example.com/TEST'],
            $docs_origin->getDocuments()
        );
    }

    public function testMultiple()
    {
        $this->markTestIncomplete();
        $do = __DIR__ . '/../../../fixture/docsorigin/multiple';
        $docs_origin = new HelperDocsOrigin($do, $this->_getClient());
        $this->assertEquals(
            [
                'doc/ONE' => 'http://example.com/ONE',
                'doc/TEST' => 'http://example.com/TEST',
                'doc/THREE' => 'http://example.com/THREE',
                'doc/TWO' => 'http://example.com/TWO',
            ],
            $docs_origin->getDocuments()
        );
    }

    private function _getClient()
    {
        $response = 'REMOTE';
        $body = new \Horde_Support_StringStream($response);
        $response = new \Horde_Http_Response_Mock('', $body->fopen());
        $response->code = 200;
        $request = new \Horde_Http_Request_Mock();
        $request->setResponse($response);
        return new \Horde_Http_Client(['request' => $request]);
    }
}
