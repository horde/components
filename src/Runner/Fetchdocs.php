<?php
/**
 * Components_Runner_Fetchdocs:: fetches documentation for a component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components\Runner;
use Horde\Components\Config;
use Horde\Components\Exception;
use Horde\Components\Output;
use Horde\Components\Helper\DocsOrigin as HelperDocsOrigin;

/**
 * Components_Runner_Fetchdocs:: fetches documentation for a component.
 *
 * Copyright 2011-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Fetchdocs
{
    /**
     * The configuration for the current job.
     *
     * @var Config
     */
    private $_config;

    /**
     * The output handler.
     *
     * @var Output
     */
    private $_output;

    /**
     * A HTTP client
     *
     * @var \Horde_Http_Client
     */
    private $_client;

    /**
     * Constructor.
     *
     * @param Config $config  The configuration for the current job.
     * @param Output  $output  The output handler.
     * @param \Horde_Http_Client $client  A HTTP client.
     */
    public function __construct(
        Config $config,
        Output $output,
        \Horde_Http_Client $client
    ) {
        $this->_config  = $config;
        $this->_output  = $output;
        $this->_client  = $client;
    }

    public function run()
    {
        $docs_origin = $this->_config->getComponent()->getDocumentOrigin();
        if ($docs_origin === null) {
            $this->_output->fail('The component does not offer a DOCS_ORIGIN file with instructions what should be fetched!');
            return;
        } else {
            $this->_output->info(sprintf('Reading instructions from %s', $docs_origin[0]));
            $options = $this->_config->getOptions();
            $helper = new HelperDocsOrigin(
                $docs_origin, $this->_client
            );
            if (empty($options['pretend'])) {
                $helper->fetchDocuments($this->_output);
            } else {
                foreach ($helper->getDocuments() as $remote => $local) {
                    $this->_output->info(
                        sprintf(
                            'Would fetch remote %s into %s!',
                            $remote,
                            $docs_origin[1] . '/' . $local
                        )
                    );
                } 
            } 
        }
    }
}
