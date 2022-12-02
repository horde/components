<?php
declare(strict_types=1);
namespace Horde\Components\Quality;

use Horde\Components\Exception;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Horde\Injector\Injector;
use PharData;

class UnitTestCoverageUpload implements MiddlewareInterface
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
            throw new Exception('Cannot run UnitTestCoverageUpload without a Horde\Components\ComposedConfig attribute');
        }
        if (empty($config)) {
            throw new Exception('Route only accepts PUT');
        }
        if (empty($vendor) || empty($package) || empty($branch) ) {
            throw new Exception('Route does not provide required details');
        }
        // We don't use $_FILES so we need to read the coverage file from the input stream.
        // TODO: Shouldn't this be factored out to a helper?
        $coverageDir = $config->get('output_dir') . '/' . $vendor . '/' . $package . '/' . $branch . '/coverage';
        $coverageTgz = $coverageDir . '/coverage.tgz';
        $phar = new PharData("egal.tgz");
        $phar->extractTo('./output/');

        if (!is_dir($coverageDir)) {
            mkdir($coverageDir, 0777, true);
        }
        $input = fopen("php://input", "r");
        file_put_contents($coverageTgz, $input);
        // Unpack and overwrite
        $phar = new PharData($coverageTgz);
        $phar->extractTo($coverageDir, null, true);

        // Happy Path, give some useful response
        $result = [];
        $stream = $this->streamFactory->createStream(json_encode($result));
        return $this->responseFactory->createResponse(201)->withBody($stream);
    }
}