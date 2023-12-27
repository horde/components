<?php
declare(strict_types=1);
namespace Horde\Components\Changelog;

use Horde\Components\Helper\Version;

class ApiVersion extends Version
{
    public static function fromVersion(Version $version)
    {
        return new ApiVersion(
            original: $version->getOriginal(),
            prefix: $version->getPrefix(),
            major: $version->getMajor(),
            minor: $version->getMinor(),
            patch: $version->getPatch(),
            subpatch: $version->getSubPatch(),
            stability: $version->getStability(),
            stabilityVersion: $version->getStabilityVersion(),
            buildInfo: $version->getBuildInfo(),
            other:  $version->getOther());
    }
}