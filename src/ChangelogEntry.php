<?php
declare(strict_types=1);
namespace Horde\Components;
use Horde\Components\Helper\Version;
use DateTimeImmutable;
use DateTimeZone;

class ChangelogEntry
{
    public function __construct(
        public readonly Version $releaseVersion, 
        public readonly Version $apiVersion, 
        public readonly DateTimeImmutable $date = new DateTimeImmutable('now', new DateTimeZone('UTC')),
        public readonly License $license = new License('LGPL-2.1-or-later', 'https://spdx.org/licenses/LGPL-2.1-or-later.html'),
        public readonly string $notes = ''
    )
    {
    }
    /**
     * Array format expected by ChangelogYml
     */
    public function toChangelogEntryArray(bool $withTopLevelVersion = false): array
    {
        $versionTag = $this->releaseVersion->toFullSemVerV2();
	$releaseStability = strlen($this->releaseVersion->getStability()) ? $this->releaseVersion()->getStability() : 'stable';
	$apiStability = strlen($this->apiVersion->getStability()) ? $this->apiVersion()->getStability() : 'stable';
	$log = [
            'api' => $this->apiVersion->toFullSemVerV2(),
            'state' => [
                'release' => $releaseStability,
                'api' => $apiStability,
            ],
            'date' => $this->date->format('Y-m-d'),
            'license' => $this->license->toArray(),
            'notes' => $this->notes,
	];
        if ($withTopLevelVersion) {
            return [$versionTag => $log];
        }
        return $log;
    }
}
