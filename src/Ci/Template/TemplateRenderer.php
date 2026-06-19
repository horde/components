<?php

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

declare(strict_types=1);

namespace Horde\Components\Ci\Template;

use Horde\Components\Exception;

/**
 * Renders CI templates with variable substitution.
 *
 * Templates use {{VARIABLE_NAME}} syntax for placeholders.
 * The renderer performs simple string replacement and validates
 * that all variables are replaced.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TemplateRenderer
{
    /**
     * Current template version.
     *
     * This version is embedded in generated files and used to detect
     * when components are using outdated templates.
     */
    private const TEMPLATE_VERSION = '1.5.0';

    /**
     * Constructor.
     *
     * @param string $templateDir Directory containing template files
     */
    public function __construct(
        private readonly string $templateDir
    ) {}

    /**
     * Render a template with variables.
     *
     * @param string $templateName Template name (without .template extension)
     * @param array<string,string> $variables Variables to substitute
     * @return string Rendered template content
     * @throws Exception If template not found or has unreplaced variables
     */
    public function render(string $templateName, array $variables): string
    {
        $templatePath = $this->templateDir . '/' . $templateName . '.template';

        if (!file_exists($templatePath)) {
            throw new Exception("Template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        if ($template === false) {
            throw new Exception("Failed to read template: {$templatePath}");
        }

        // Add automatic variables
        $variables['{{TEMPLATE_VERSION}}'] = self::TEMPLATE_VERSION;
        $variables['{{GENERATED_DATE}}'] = date('Y-m-d H:i:s T');
        $variables['{{COMPONENTS_VERSION}}'] = $this->getComponentsVersion();

        // Simple string replacement
        $rendered = str_replace(
            array_keys($variables),
            array_values($variables),
            $template
        );

        // Validate no unreplaced variables remain
        if (preg_match('/\{\{[A-Z_][A-Z0-9_]*\}\}/', $rendered, $matches)) {
            throw new Exception(
                "Unreplaced template variable in {$templateName}: {$matches[0]}"
            );
        }

        return $rendered;
    }

    /**
     * Get the current template version.
     *
     * @return string Template version
     */
    public static function getTemplateVersion(): string
    {
        return self::TEMPLATE_VERSION;
    }

    /**
     * Get horde-components version.
     *
     * @return string Version string
     */
    private function getComponentsVersion(): string
    {
        // Try to read from .horde.yml
        $hordeYmlPath = dirname(__DIR__, 3) . '/.horde.yml';
        if (file_exists($hordeYmlPath)) {
            $content = file_get_contents($hordeYmlPath);
            if ($content !== false && preg_match('/^version:\s*\n\s+release:\s*(.+)$/m', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        // Fallback to constant if defined
        if (defined('COMPONENTS_VERSION')) {
            return COMPONENTS_VERSION;
        }

        return 'dev-main';
    }
}
