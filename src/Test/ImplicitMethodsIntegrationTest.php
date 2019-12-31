<?php

/**
 * @see       https://github.com/mezzio/mezzio-router for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-router/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-router/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Router\Test;

use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use Fig\Http\Message\StatusCodeInterface as StatusCode;
use Generator;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use Mezzio\Router\Middleware\PathBasedRoutingMiddleware;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Base class for testing adapter integrations.
 *
 * Implementers of adapters should extend this class in their test suite,
 * implementing the `getRouter()` method.
 *
 * This test class tests that the router correctly marshals the allowed methods
 * for a match that matches the path, but not the request method.
 */
abstract class ImplicitMethodsIntegrationTest extends TestCase
{
    abstract public function getRouter() : RouterInterface;

    public function method() : Generator
    {
        yield RequestMethod::METHOD_HEAD => [
            RequestMethod::METHOD_HEAD,
            new ImplicitHeadMiddleware(
                function () {
                    return new Response();
                },
                function () {
                    return new Stream('php://temp', 'rw');
                }
            ),
        ];
        yield RequestMethod::METHOD_OPTIONS => [
            RequestMethod::METHOD_OPTIONS,
            new ImplicitOptionsMiddleware(
                function () {
                    return new Response();
                }
            ),
        ];
    }

    /**
     * @dataProvider method
     */
    public function testExplicitRequest(string $method, MiddlewareInterface $middleware)
    {
        $middleware1 = $this->prophesize(MiddlewareInterface::class)->reveal();
        $middleware2 = $this->prophesize(MiddlewareInterface::class)->reveal();
        $route1 = new Route('/api/v1/me', $middleware1, [$method]);
        $route2 = new Route('/api/v1/me', $middleware2, [RequestMethod::METHOD_GET]);

        $router = $this->getRouter();
        $router->addRoute($route1);
        $router->addRoute($route2);

        $finalResponse = (new Response())->withHeader('foo-bar', 'baz');
        $finalResponse->getBody()->write('FOO BAR BODY');

        $finalHandler = $this->prophesize(RequestHandlerInterface::class);
        $finalHandler
            ->handle(Argument::that(function (ServerRequestInterface $request) use ($method, $route1) {
                if ($request->getMethod() !== $method) {
                    return false;
                }

                if ($request->getAttribute(ImplicitHeadMiddleware::FORWARDED_HTTP_METHOD_ATTRIBUTE) !== null) {
                    return false;
                }

                $routeResult = $request->getAttribute(RouteResult::class);
                if (! $routeResult) {
                    return false;
                }

                if (! $routeResult->isSuccess()) {
                    return false;
                }

                $matchedRoute = $routeResult->getMatchedRoute();
                if (! $matchedRoute) {
                    return false;
                }

                if ($matchedRoute !== $route1) {
                    return false;
                }

                return true;
            }))
            ->willReturn($finalResponse)
            ->shouldBeCalledTimes(1);

        $routeMiddleware = new PathBasedRoutingMiddleware($router);
        $handler = new class ($finalHandler->reveal(), $middleware) implements RequestHandlerInterface
        {
            /** @var RequestHandlerInterface */
            private $handler;

            /** @var MiddlewareInterface */
            private $middleware;

            public function __construct(RequestHandlerInterface $handler, MiddlewareInterface $middleware)
            {
                $this->handler = $handler;
                $this->middleware = $middleware;
            }

            public function handle(ServerRequestInterface $request) : ResponseInterface
            {
                return $this->middleware->process($request, $this->handler);
            }
        };

        $request = new ServerRequest([], [], '/api/v1/me', $method);

        $response = $routeMiddleware->process($request, $handler);

        $this->assertEquals(StatusCode::STATUS_OK, $response->getStatusCode());
        $this->assertSame('FOO BAR BODY', (string) $response->getBody());
        $this->assertTrue($response->hasHeader('foo-bar'));
        $this->assertSame('baz', $response->getHeaderLine('foo-bar'));
    }

    public function testImplicitHeadRequest()
    {
        $middleware1 = $this->prophesize(MiddlewareInterface::class)->reveal();
        $middleware2 = $this->prophesize(MiddlewareInterface::class)->reveal();
        $route1 = new Route('/api/v1/me', $middleware1, [RequestMethod::METHOD_GET]);
        $route2 = new Route('/api/v1/me', $middleware2, [RequestMethod::METHOD_POST]);

        $router = $this->getRouter();
        $router->addRoute($route1);
        $router->addRoute($route2);

        $finalResponse = (new Response())->withHeader('foo-bar', 'baz');
        $finalResponse->getBody()->write('FOO BAR BODY');

        $finalHandler = $this->prophesize(RequestHandlerInterface::class);
        $finalHandler
            ->handle(Argument::that(function (ServerRequestInterface $request) use ($route1) {
                if ($request->getMethod() !== RequestMethod::METHOD_GET) {
                    return false;
                }

                if ($request->getAttribute(ImplicitHeadMiddleware::FORWARDED_HTTP_METHOD_ATTRIBUTE)
                    !== RequestMethod::METHOD_HEAD
                ) {
                    return false;
                }

                $routeResult = $request->getAttribute(RouteResult::class);
                if (! $routeResult) {
                    return false;
                }

                if (! $routeResult->isSuccess()) {
                    return false;
                }

                $matchedRoute = $routeResult->getMatchedRoute();
                if (! $matchedRoute) {
                    return false;
                }

                if ($matchedRoute !== $route1) {
                    return false;
                }

                return true;
            }))
            ->willReturn($finalResponse)
            ->shouldBeCalledTimes(1);

        $routeMiddleware = new PathBasedRoutingMiddleware($router);
        $handler = new class ($finalHandler->reveal()) implements RequestHandlerInterface
        {
            /** @var RequestHandlerInterface */
            private $handler;

            public function __construct(RequestHandlerInterface $handler)
            {
                $this->handler = $handler;
            }

            public function handle(ServerRequestInterface $request) : ResponseInterface
            {
                $middleware = new ImplicitHeadMiddleware(
                    function () {
                        return new Response();
                    },
                    function () {
                        return new Stream('php://temp', 'rw');
                    }
                );

                return $middleware->process($request, $this->handler);
            }
        };

        $request = new ServerRequest([], [], '/api/v1/me', RequestMethod::METHOD_HEAD);

        $response = $routeMiddleware->process($request, $handler);

        $this->assertEquals(StatusCode::STATUS_OK, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
        $this->assertTrue($response->hasHeader('foo-bar'));
        $this->assertSame('baz', $response->getHeaderLine('foo-bar'));
    }

    public function testImplicitOptionsRequest()
    {
        $middleware1 = $this->prophesize(MiddlewareInterface::class)->reveal();
        $middleware2 = $this->prophesize(MiddlewareInterface::class)->reveal();
        $route1 = new Route('/api/v1/me', $middleware1, [RequestMethod::METHOD_GET]);
        $route2 = new Route('/api/v1/me', $middleware2, [RequestMethod::METHOD_POST]);

        $router = $this->getRouter();
        $router->addRoute($route1);
        $router->addRoute($route2);

        $finalHandler = $this->prophesize(RequestHandlerInterface::class);
        $finalHandler->handle()->shouldNotBeCalled();

        $finalResponse = (new Response())->withHeader('foo-bar', 'baz');
        $finalResponse->getBody()->write('response body bar');

        $routeMiddleware = new PathBasedRoutingMiddleware($router);
        $handler = new class ($finalHandler->reveal(), $finalResponse) implements RequestHandlerInterface
        {
            /** @var RequestHandlerInterface */
            private $handler;

            /** @var ResponseInterface */
            private $response;

            public function __construct(RequestHandlerInterface $handler, ResponseInterface $response)
            {
                $this->handler = $handler;
                $this->response = $response;
            }

            public function handle(ServerRequestInterface $request) : ResponseInterface
            {
                return (new ImplicitOptionsMiddleware(function () {
                    return $this->response;
                }))->process($request, $this->handler);
            }
        };

        $request = new ServerRequest([], [], '/api/v1/me', RequestMethod::METHOD_OPTIONS);

        $response = $routeMiddleware->process($request, $handler);

        $this->assertSame(StatusCode::STATUS_OK, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Allow'));
        $this->assertSame('GET,POST', $response->getHeaderLine('Allow'));
        $this->assertTrue($response->hasHeader('foo-bar'));
        $this->assertSame('baz', $response->getHeaderLine('foo-bar'));
        $this->assertSame('response body bar', (string) $response->getBody());
    }
}