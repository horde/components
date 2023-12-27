<?php
declare(strict_types=1);
namespace Horde\Components\Changelog;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Horde\Components\Helper\Version;
use Horde_Date;

/**
 * Maintain a single version's metadata
 */
class VersionLogEntry
{
    public readonly PackageVersion $packageVersion;
    public readonly ApiVersion $apiVersion;
    public function __construct(
        Version|string $packageVersion,
        DateTime $date = new DateTime('now', new DateTimeZone('UTC')),
        ApiVersion|string|null $apiVersion = null,
        public readonly License $license,
        private array $added = [],
        private array $changed = [],
        private array $deprecated = [],
        private array $removed = [],
        private array $fixed = [],
        private array $security = [],
    )
    {
        // A version log must have a version - the API version can be guessed from it of missing
        $this->packageVersion = is_string($packageVersion) ? PackageVersion::fromComposerString($packageVersion) : $packageVersion;
        if (is_null($apiVersion)) {
            $apiVersion = ApiVersion::fromVersion($this->packageVersion);
        }
        $this->apiVersion = is_string($apiVersion) ? ApiVersion::fromComposerString($apiVersion) : $apiVersion;
    }

    public function appendAdded(string $added)
    {
        array_push($this->added, $added);
    }

    public function appendChanged(string $added)
    {
        array_push($this->added, $added);
    }
    public function appendDeprecated(string $added)
    {
        array_push($this->added, $added);
    }
    public function appendRemoved(string $added)
    {
        array_push($this->added, $added);
    }
    public function appendFixed(string $added)
    {
        array_push($this->added, $added);
    }
    public function appendSecurity(string $added)
    {
        array_push($this->added, $added);
    }


}