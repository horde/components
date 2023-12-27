<?php
declare(strict_types=1);
namespace Horde\Components\Changelog;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Horde\Components\Helper\Version;
use Horde_Date;

/**
 * Maintain a special block for unreleased data
 */
class Unreleased
{
    public function __construct(
        private array $added = [],
        private array $changed = [],
        private array $deprecated = [],
        private array $removed = [],
        private array $fixed = [],
        private array $security = [],
    )
    {
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