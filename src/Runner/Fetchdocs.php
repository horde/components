<?php

/**
 * Components_Runner_Fetchdocs:: fetches documentation for a component.
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\Component;
use Horde\Components\Exception;
use Horde\Components\Helper\DocsOrigin as HelperDocsOrigin;
use Horde\Components\Output;
use Horde_Http_Client;

/**
 * Components_Runner_Fetchdocs:: fetches documentation for a component.
 *
 * Copyright 2011-2026 Horde LLC (http://www.horde.org/)
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
     * @param Component $component The component
     * @param array $options CLI options
     * @param Output $output The output handler
     * @param Horde_Http_Client $client A HTTP client
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $options,
        private readonly Output $output,
        private readonly Horde_Http_Client $client
    ) {}

    public function run(): void
    {
        $docs_origin = $this->component->getDocumentOrigin();
        if ($docs_origin === null) {
            $this->output->fail('The component does not offer a DOCS_ORIGIN file with instructions what should be fetched!');
            return;
        } else {
            $this->output->info(sprintf('Reading instructions from %s', $docs_origin[0]));
            $helper = new HelperDocsOrigin(
                $docs_origin,
                $this->client
            );
            if (empty($this->options['pretend'])) {
                $helper->fetchDocuments($this->output);
            } else {
                foreach ($helper->getDocuments() as $remote => $local) {
                    $this->output->info(
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
