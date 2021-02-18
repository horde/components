<?php
/**
 * Components_Release_Task_Satis:: Rebuild a satis repo
 *
 * Ask Satis to rebuild the repository now and find updated tags
 * 
 * PHP version 7
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @link     https://packagist.org/about#how-to-update-packages
 */
namespace Horde\Components\Release\Task;

/**
 * Components_Release_Task_Satis:: Rebuild a satis repo
 *
 * Copyright 2011-2019 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <lang@b1-systems.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @link     https://packagist.org/about#how-to-update-packages
 */
class Satis extends Base
{
    /**
     * Validate if we are setup
     * 
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options)
    {
        $issues = [];
        $options = $this->_options($options);

        foreach (['satis_bin', 'satis_json', 'satis_outdir'] as $option) {
            if (empty($options[$option])) {
                $issues[] = "Required config key '$option' not set";
            }
        } 

        return $issues;
    }

    /**
     * Ask for the \Horde_Http_Client dependency
     * 
     * @return array The list of dependencies requested
     */
    public function askDependencies()
    {
        return ['http' => 'Horde_Http_Client'];
    }

    /**
     * Run the task.
     * 
     * Checkout the wanted branch
     * Supports pretend mode
     *
     * @param array $options Additional options by reference.
     *
     * @return void;
     */
    public function run(&$options)
    {
        $options = $this->_options($options);
        $pretend = $this->getTasks()->pretend();
        $package = $this->getComponent();
        // Do we need to generalize this?
        $base = $package->getName();
        if ($base == 'horde') {
            $base = 'base';
        }
        $repo = $options['git_repo_base'] . $base . '.git';
        if ($pretend) {
            $this->getOutput()->info(
                sprintf(
                    'Would try to ensure package %s is present in json config',
                    $package->getName()
                )
            );
            $this->getOutput()->info(
                sprintf(
                    'Would try to rewrite Satis repository at %s',
                    $options['satis_outdir']
                )
            );
            if (!empty($options['satis_push'])) {
                $this->getOutput()->info(
                    sprintf(
                        'Would try to commit and push Satis repository at %s',
                        $options['satis_outdir']
                    )
                );
            }
            return;
        }
        // Ensure the package is present in the satis repo
        $res = $this->exec(
            sprintf(
                '%s add %s %s',
                $options['satis_bin'],
                $repo,
                $options['satis_json']
            )
        );
        // Any output here?

        // Rebuild the satis repo
        $this->getOutput()->info(
            sprintf(
                'Rebuilding static content at %s from json config at %s -'.
                ' This may take very long',
                $options['satis_outdir'],
                $options['satis_json']
            )
        );
        $res = $this->exec(
            sprintf(
                '%s build %s %s',
                $options['satis_bin'],
                $options['satis_json'],
                $options['satis_outdir']
            )
        );
        if ($options['satis_push']) {
            $this->execInDirectory(
                'git add index.html packages.json include',
                $options['satis_outdir']
            );
            $this->execInDirectory(
                sprintf('git commit -m "Updated by %s release at %s"',
                    $package->getName(),
                    (new \Horde_Date(time(),'UTC'))->toJson()
                ),
                $options['satis_outdir']
            );
            $this->execInDirectory(
                'git push',
                $options['satis_outdir']
            );
        }

        return;
    }

    /**
     * Ensure default and required options
     *
     * - satis_bin: path to satis cli defaults to "which"
     * - satis_json: path to satis json file defaults to ''
     * - satis_outdir: path where satis should write the repository default ''
     * - satis_push: Try to commit and push the generated content as git repo?
     *     Defaults to false
     * 
     * @param array $options Additional options.
     * 
     * @return array The processed options
     */
    protected function _options($options)
    {
        if (empty($options['satis_bin'])) {
            $satisWhich = $this->exec('which satis');
            $found = $satisWhich->getReturnValue() ? '' : (string) $satisWhich;
        }
        $options['satis_bin'] = $options['satis_bin'] ?? $found;
        $options['satis_json'] = $options['satis_json'] ?? '';
        $options['satis_outdir'] = $options['satis_outdir'] ?? '';
        $options['satis_push'] = (bool) $options['satis_push'] ?? false;
        $options['vendor'] = $options['vendor'] ?? 'horde';
        $options['git_repo_base'] = $options['git_repo_base'] ??
            'https://github.com/' . $options['vendor'] . '/';
        return $options;
    }
}
