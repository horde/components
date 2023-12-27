<?php
declare(strict_types=1);
namespace Horde\Components\Changelog;

use Horde\Components\Wrapper\ChangelogYml;
use SplFileInfo;
use DateTime;
use DateTimeZone;

/**
 * Create a Changelog object from a changelog.yml version 1
 */
class YmlV1FormatReader
{

    public function __construct(private ChangelogYml $yml)
    {

    }

    public function read(): Changelog
    {
        $logEntries = new VersionLogEntries();
        // The keys are expected newest first
        foreach (array_reverse(array_keys( (array) $this->yml)) as $key) {
            $packageVersion = PackageVersion::fromComposerString($key);
            // TODO: Fallback to package version
            $apiVersion = ApiVersion::fromComposerString($this->yml[$key]['api']);
            $date = new DateTime($this->yml[$key]['date'], new DateTimeZone('UTC'));
            $logEntries->addVersionLogEntry(new VersionLogEntry(
                license: new License(
                    spdxTag: $this->yml[$key]['license']['identifier'],
                    uri: $this->yml[$key]['license']['uri'] ?? ''
                ),
                packageVersion: $packageVersion,
                date: $date,
                apiVersion: $apiVersion,
                changed: $this->yml[$key]['notes']
            ));
        }
        return new Changelog(versionLogEntries: $logEntries, unreleased: new Unreleased());
    }
}