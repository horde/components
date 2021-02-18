<?php
/**
 * Components_Runner_Snapshot:: packages a snapshot.
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
use Horde\Components\Output;
use Horde\Components\Pear\Factory as PearFactory;

/**
 * Components_Runner_Snapshot:: packages a snapshot.
 *
 * Copyright 2010-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Snapshot
{
    /**
     * The configuration for the current job.
     *
     * @var Config
     */
    private $_config;

    /**
     * The factory for PEAR dependencies.
     *
     * @var PearFactory
     */
    private $_factory;

    /**
     * The output handler.
     *
     * @param Output
     */
    private $_output;

    /**
     * Constructor.
     *
     * @param Config      $config  The current job's configuration
     * @param PearFactory $factory The factory for PEAR dependencies.
     * @param Output      $output  The output handler.
     */
    public function __construct(
        Config $config,
        PearFactory $factory,
        Output $output
    ) {
        $this->_config = $config;
        $this->_factory = $factory;
        $this->_output = $output;
    }

    public function run()
    {
        $options = $this->_config->getOptions();
        if (!empty($options['destination'])) {
            $archivedir = $options['destination'];
        } else {
            $archivedir = getcwd();
        }
        $options['logger'] = $this->_output;
        $result = $this->_config->getComponent()->placeArchive(
            $archivedir, $options
        );
        if (isset($result[2])) {
            $this->_output->pear($result[2]);
        }
        if (!empty($result[1])) {
            $this->_output->fail(
                'Generating snapshot failed with:'. "\n\n" . join("\n", $result[1])
            );
        } else {
            $this->_output->ok('Generated snapshot ' . $result[0]);
        }
    }
}
