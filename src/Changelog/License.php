<?php
declare(strict_types=1);
namespace Horde\Components\Changelog;

use Horde\Components\Helper\Version;

class License
{
    public function __construct(
        public readonly string $spdxTag,
        public readonly string $uri = ''
    )
    {
    }

    public function upgradeDeprecatedSpdxTags()
    {
    }

    public function defaultLicenseUris()
    {
    }
}