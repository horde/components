<?php
/**
 * Represents a component.
 *
 * PHP Version 7
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
namespace Horde\Components;
use Horde\Components\Component\DependencyList;
use Horde\Components\Helper\Commit as HelperCommit;
use Horde\Components\Helper\Root as HelperRoot;
use Horde\Components\Pear\Environment as PearEnvironment;

/**
 * Represents a component.
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
interface Component
{
    /**
     * Return the name of the component.
     *
     * @return string The component name.
     */
    public function getName();

    /**
     * Return the component summary.
     *
     * @return string The summary of the component.
     */
    public function getSummary();

    /**
     * Return the component description.
     *
     * @return string The description of the component.
     */
    public function getDescription();

    /**
     * Return the version of the component.
     *
     * @return string The component version.
     */
    public function getVersion();

    /**
     * Returns the previous version of the component.
     *
     * @return string The previous component version.
     */
    public function getPreviousVersion();

    /**
     * Return the last release date of the component.
     *
     * @return string The date.
     */
    public function getDate();

    /**
     * Return the channel of the component.
     *
     * @return string The component channel.
     */
    public function getChannel();

    /**
     * Return the dependencies for the component.
     *
     * @return array The component dependencies.
     */
    public function getDependencies();

    /**
     * Return the stability of the release or api.
     *
     * @param string $key "release" or "api"
     *
     * @return string The stability.
     */
    public function getState($key = 'release');

    /**
     * Return the component lead developers.
     *
     * @return string The component lead developers.
     */
    public function getLeads();

    /**
     * Return the component license.
     *
     * @return string The component license.
     */
    public function getLicense();

    /**
     * Return the component license URI.
     *
     * @return string The component license URI.
     */
    public function getLicenseLocation();

    /**
     * Indicate if the component has a local package.xml.
     *
     * @return boolean True if a package.xml exists.
     */
    public function hasLocalPackageXml();

    /**
     * Returns the link to the change log.
     *
     * @return string The link to the change log.
     */
    public function getChangelogLink();

    /**
     * Return the path to the release notes.
     *
     * @return string|boolean The path to the release notes or false.
     */
    public function getReleaseNotesPath();

    /**
     * Return the dependency list for the component.
     *
     * @return DependencyList The dependency list.
     */
    public function getDependencyList();

    /**
     * Return a data array with the most relevant information about this
     * component.
     *
     * @return \stdClass Information about this component.
     */
    public function getData();

    /**
     * Return the path to a DOCS_ORIGIN file within the component.
     *
     * @return string|null The path name or NULL if there is no DOCS_ORIGIN file.
     */
    public function getDocumentOrigin();

    /**
     * Update the package.xml file for this component.
     *
     * @param string $action  The action to perform. Either "update", "diff",
     *                        or "print".
     * @param array  $options Options for this operation.
     *
     * @return void
     */
    public function updatePackage($action, $options);

    /**
     * Update the component changelog.
     *
     * @param string $log     The log entry.
     * @param array $options  Options for the operation.
     *
     * @return string[]  Output messages.
     */
    public function changed($log, $options);

    /**
     * Timestamp the package.xml file with the current time.
     *
     * @param array $options Options for the operation.
     *
     * @return string The success message.
     */
    public function timestamp($options);

    /**
     * Add the next version to the package.xml.
     *
     * @param string $version           The new version number.
     * @param string $initial_note      The text for the initial note.
     * @param string $stability_api     The API stability for the next release.
     * @param string $stability_release The stability for the next release.
     * @param array $options Options for the operation.
     *
     * @return void
     */
    public function nextVersion(
        $version,
        $initial_note,
        $stability_api = null,
        $stability_release = null,
        $options = array()
    );

    /**
     * Replace the current sentinel.
     *
     * @param string $changes New version for the CHANGES file.
     * @param string $app     New version for the Application.php file.
     * @param array  $options Options for the operation.
     *
     * @return string The success message.
     */
    public function currentSentinel($changes, $app, $options);

    /**
     * Tag the component.
     *
     * @param string       $tag     Tag name.
     * @param string       $message Tag message.
     * @param HelperCommit $commit  The commit helper.
     *
     * @return void
     */
    public function tag(string $tag, string $message, HelperCommit $commit);

    /**
     * Place the component source archive at the specified location.
     *
     * @param string $destination The path to write the archive to.
     * @param array  $options     Options for the operation.
     *
     * @return array An array with at least [0] the path to the resulting
     *               archive, optionally [1] an array of error strings, and [2]
     *               PEAR output.
     */
    public function placeArchive($destination, $options = array());

    /**
     * Identify the repository root.
     *
     * @param HelperRoot $helper The root helper.
     *
     * @return void
     */
    public function repositoryRoot(HelperRoot $helper);

    /**
     * Install the channel of this component in the environment.
     *
     * @param PearEnvironment $env     The environment to install into.
     * @param array           $options Install options.
     *
     * @return void
     */
    public function installChannel(PearEnvironment $env, $options = array());

    /**
     * Install a component.
     *
     * @param PearEnvironment $env     The environment to install into.
     * @param array           $options Install options.
     * @param string          $reason  Optional reason for adding the package.
     *
     * @return void
     */
    public function install(
        PearEnvironment $env, $options = array(), $reason = ''
    );
}
