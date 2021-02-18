<?php
/**
 * Components_Release_Task_Packagist:: Notify Packagist of update
 *
 * Ask packagist to re-read the repository now and find updated tags
 * 
 * You could substitute this step by using the Github Webhook feature
 * Packagist also auto-updates roughly every week
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
 * Components_Release_Task_Packagist:: Notify Packagist of update
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
class Packagist extends Base
{
    /**
     * Validate if we can make a valid request
     * 
     * @param array $options Additional options.
     *
     * @return array An empty array if all preconditions are met and a list of
     *               error messages otherwise.
     */
    public function preValidate($options)
    {
        $issues = [];
        $pretend = $this->getTasks()->pretend();
        $package = $this->getComponent();
        $options = $this->_options($options);
        if (empty($options['packagist_api_key'])) {
            $issues[] = "Did not configure packagist_api_key in conf.php";
        }
        if (empty($options['packagist_user'])) {
            $issues[] = "Did not configure packagist_user in conf.php";
        }
        $http = $this->getDependency('http');
        if (empty($http)) {
            $issues[] = "Horde_Http_Client not installed";
        }
        if ($issues) {
            // These above are fatal, no way to progress
            return $issues;
        }
        if ($pretend) {
            $this->getOutput()->info(
                sprintf(
                    'Would check if package %s/%s exists in packagist',
                    $options['vendor'],
                    $package->getName()
                )
            );
        } elseif ($this->_verifyPackageExists($options, $package)) {
            $this->getOutput()->info(
                sprintf(
                    'Verified package %s/%s exists in packagist',
                    $options['vendor'],
                    $package->getName()
                )
            );           
        } else {
            $issues[] = sprintf(
                'Package %s/%s does not exists in packagist',
                $options['vendor'],
                $package->getName()
            );
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
        $http = $this->getDependency('http');
        $url = sprintf(
            '%s/api/update-package?username=%s&apiToken=%s', 
            $options['packagist_url'],
            $options['packagist_user'],
            $options['packagist_api_key']
        );
        // No need to build this with a JSON conversion
        $body = sprintf(
            '{"repository":{"url":"%s/packages/%s/%s"}}',
            $options['packagist_url'],
            $options['vendor'],
            $this->getComponent()->getName()        
        );
        $header = ['content-type' => 'application/json'];
        $response = $http->post($url, $body, $header);
        if (in_array($response->code, ['404', '500'])) {
            $this->getOutput()->warn('Notification to packagist failed!');
            $this->getOutput()->warn($response->getBody());
        }
        return;
    }

    /**
     * Ensure default and required options
     * 
     * - vendor defaults to horde
     * - packagist_api_key defaults to empty
     * - packagist_user defaults to empty
     * - packagist_url defaults to https://packagist.org
     * 
     * @param array $options Additional options.
     * 
     * @return array The processed options
     */
    protected function _options($options)
    {
        $options['vendor'] = $options['vendor'] ?? 'horde';
        $options['packagist_api_key'] = $options['packagist_api_key'] ?? '';
        $options['packagist_url'] = $options['packagist_url'] ??
            'https://packagist.org';
        $options['packagist_user'] = $options['packagist_user'] ?? '';
        return $options;
    }

    /**
     * Ensure packagist has the package we want to nudge an update for
     * 
     * Packagist has a published method for receiving update hints but
     * there is no published way of programmatically adding packages
     *
     * @param array                     $options Additional options.
     * @param Horde\Components\Component\Base $package The package to check
     * 
     * @return boolean True if the package exists
     */
    protected function _verifyPackageExists($options, $package)
    {
        $http = $this->getDependency('http');
        $url = sprintf(
            '%s/packages/%s/%s.json', 
            $options['packagist_url'],
            $options['vendor'],
            $package->getName()
        );
        $response = $http->get($url);
        if ($response->code == 404) {
            return false;
        }
        return true;
    }
}
