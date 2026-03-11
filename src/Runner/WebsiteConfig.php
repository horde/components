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
        // Try new names first, then fall back to old names for backwards compatibility
        $inputDir = $config->hasSetting('devsite.input_dir')
            ? $config->getSetting('devsite.input_dir')
            : ($config->hasSetting('web_input')
                ? $config->getSetting('web_input')
                : $componentsRoot . '/data/webhooks');

        $outputDir = $config->hasSetting('devsite.output_dir')
            ? $config->getSetting('devsite.output_dir')
            : ($config->hasSetting('web_output')
                ? $config->getSetting('web_output')
                : $componentsRoot . '/build/dev.horde.org');

        $templatesDir = $config->hasSetting('devsite.template_dir')
            ? $config->getSetting('devsite.template_dir')
            : ($config->hasSetting('web_templates')
                ? $config->getSetting('web_templates')
                : $componentsRoot . '/data/website');

        $componentsFile = $config->hasSetting('devsite.components')
            ? $config->getSetting('devsite.components')
            : ($config->hasSetting('web_components')
                ? $config->getSetting('web_components')
                : $templatesDir . '/components.json');

        // For organization, prefer repo.org over devsite.org over legacy web_org
        $organization = $config->hasSetting('repo.org')
            ? $config->getSetting('repo.org')
            : ($config->hasSetting('devsite.org')
                ? $config->getSetting('devsite.org')
                : ($config->hasSetting('web_org')
                    ? $config->getSetting('web_org')
                    : 'horde'));

        // For git directory, prefer checkout.dir over devsite.git_dir over legacy web_git_dir
        $gitDir = $config->hasSetting('checkout.dir')
            ? $config->getSetting('checkout.dir')
            : ($config->hasSetting('devsite.git_dir')
                ? $config->getSetting('devsite.git_dir')
                : ($config->hasSetting('web_git_dir')
                    ? $config->getSetting('web_git_dir')
                    : null));

        // For token, prefer github.token over devsite.token over legacy web_token
        $token = $config->hasSetting('github.token')
            ? $config->getSetting('github.token')
            : ($config->hasSetting('devsite.token')
                ? $config->getSetting('devsite.token')
                : ($config->hasSetting('web_token')
                    ? $config->getSetting('web_token')
                    : $fallbackToken));

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
