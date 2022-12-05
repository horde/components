<?php
declare(strict_types=1);
namespace Horde\Components\Middleware;

use Horde\Components\Exception;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Horde\Injector\Injector;
use PharData;

/**
 * Upload PHPDoc
 * 
 * Receive curl tgz packaged phpdoc like so:
 *  curl https://dev.maintaina.com/ci/phpdoc/horde/components/FRAMEWORK_6_0 -X PUT -H "Authorization: Bearer foo" -T foo.tgz -v
 */
class SatisUpdate implements MiddlewareInterface
{
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        Injector $injector,
    ) {
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->injector = $injector;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeConfig = $request->getAttribute('route');
        $vendor = $routeConfig['vendor'] ?? '';
        $package = $routeConfig['package'] ?? '';
        $branch = $routeConfig['branch'] ?? '';
        $method = $request->getMethod();
        $config = $request->getAttribute('Horde\Components\ComposedConfig');

        if ($method !== 'PUT') {
            throw new Exception('Route only accepts PUT');
        }
        $config = $request->getAttribute('Horde\Components\ComposedConfig');
        if (empty($config)) {
            throw new Exception('Cannot run PHPUnitUpload without a Horde\Components\ComposedConfig attribute');
        }
        if (empty($config)) {
            throw new Exception('Route only accepts PUT');
        }
        if (empty($vendor) || empty($package) || empty($branch) ) {
            throw new Exception('Route does not provide required details');
        }
        // We don't use $_FILES so we need to read the coverage file from the input stream.
        // TODO: Shouldn't this be factored out to a helper?
        $phpdocDir = $config->get('output_dir') . '/' . strtolower($vendor) . '/' . $package . '/' . $branch . '/phpdoc';
        $phpdocTgz = $phpdocDir . '/phpdoc.tgz';

        if (!is_dir($phpdocDir)) {
            mkdir($phpdocDir, 0777, true);
        }
        if (!is_dir($phpdocDir)) {
            throw new Exception('Could not create target dir ' . $phpdocDir);
        }
        $input = fopen("php://input", "r");
        file_put_contents($phpdocTgz, $input);
        // Unpack and overwrite
        $phar = new PharData($phpdocTgz);
        $phar->extractTo($phpdocDir, null, true);

        // Happy Path, give some useful response
        $result = [];
        $stream = $this->streamFactory->createStream(json_encode($result));
        return $this->responseFactory->createResponse(201)->withBody($stream);
    }
}