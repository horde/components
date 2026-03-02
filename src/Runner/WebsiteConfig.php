<?php

/**
 * Website configuration parameters
 *
 * PHP Version 8.2+
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Runner;

use Horde\Components\ConfigProvider\EffectiveConfigProvider;

/**
 * Website configuration parameters
 *
 * This parameter object encapsulates all website-related configuration
 * settings, providing a clean interface for the WebsiteRunner.
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
readonly class WebsiteConfig
{
    /**
     * Create website configuration from individual values
     *
     * @param string $inputDir Directory containing webhook JSON files
     * @param string $outputDir Directory for generated website
     * @param string $templatesDir Directory containing templates
     * @param string $componentsFile Path to components.json catalog file
     * @param string $organization GitHub organization name
     * @param string|null $gitDir Optional local git repository directory
     * @param string|null $token Optional GitHub API token
     */
    public function __construct(
        public string $inputDir,
        public string $outputDir,
        public string $templatesDir,
        public string $componentsFile,
        public string $organization = 'horde',
        public ?string $gitDir = null,
        public ?string $token = null
    ) {}

    /**
     * Create configuration from ConfigProvider with defaults
     *
     * @param EffectiveConfigProvider $config The configuration provider
     * @param string $componentsRoot The components root directory for relative paths
     * @param string|null $fallbackToken Fallback token if not in config
     * @return self
     */
    public static function fromConfigProvider(
        EffectiveConfigProvider $config,
        string $componentsRoot,
        ?string $fallbackToken = null
    ): self {
        // Get values with defaults relative to components root
        $inputDir = $config->hasSetting('web_input')
            ? $config->getSetting('web_input')
            : $componentsRoot . '/data/webhooks';

        $outputDir = $config->hasSetting('web_output')
            ? $config->getSetting('web_output')
            : $componentsRoot . '/build/dev.horde.org';

        $templatesDir = $config->hasSetting('web_templates')
            ? $config->getSetting('web_templates')
            : $componentsRoot . '/data/website';

        $componentsFile = $config->hasSetting('web_components')
            ? $config->getSetting('web_components')
            : $templatesDir . '/components.json';

        $organization = $config->hasSetting('web_org')
            ? $config->getSetting('web_org')
            : 'horde';

        $gitDir = $config->hasSetting('web_git_dir')
            ? $config->getSetting('web_git_dir')
            : null;

        // Token priority: web_token config > fallback token (from GithubApiConfig)
        $token = $config->hasSetting('web_token')
            ? $config->getSetting('web_token')
            : $fallbackToken;

        return new self(
            $inputDir,
            $outputDir,
            $templatesDir,
            $componentsFile,
            $organization,
            $gitDir,
            $token
        );
    }
}
