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
use Horde\Components\Helper\DocsOrigin as HelperDocsOrigin;
use Horde\Components\Output;

/**
 * Components_Runner_Fetchdocs:: fetches documentation for a component.
 *
 * Copyright 2011-2024 Horde LLC (http://www.horde.org/)
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
     * Constructor.
     *
     * @param Config $_config The configuration for the current job.
     * @param Output $_output The output handler.
     * @param \Horde_Http_Client $_client A HTTP client.
     */
    public function __construct(private readonly Config $_config, private readonly Output $_output, private readonly \Horde_Http_Client $_client)
    {
    }

    public function run(): void
    {
        $docs_origin = $this->_config->getComponent()->getDocumentOrigin();
        if ($docs_origin === null) {
            $this->_output->fail('The component does not offer a DOCS_ORIGIN file with instructions what should be fetched!');
            return;
        } else {
            $this->_output->info(sprintf('Reading instructions from %s', $docs_origin[0]));
            $options = $this->_config->getOptions();
            $helper = new HelperDocsOrigin(
                $docs_origin,
                $this->_client
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
