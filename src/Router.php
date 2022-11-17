<?php
namespace Horde\Components;

class Router
{
    public function __construct(Horde_Routes_Mapper $mapper)
    {
        $mapper->connect(
            '/ci/phpunit/coverage/:vendor/:package/:branch',
            [
                'stack' => [
                    AuthCheckMiddleware::class,
                    Quality\UnitTestCoverageUpload::class
                ]
            ]
        );
    }
}