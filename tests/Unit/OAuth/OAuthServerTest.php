<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\Endpoints;
use Pollora\McpConnector\OAuth\OAuthServer;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/wp-rest-server.php';

/**
 * Thrown by the status_header() stand-in: HttpResponse::send() ends in exit,
 * so the test stops the response at its first observable step instead.
 */
final class McpcResponseSent extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('sent ' . $status);
    }
}

/**
 * A REST request that knows its route.
 */
function mcpcRoutedRequest(string $route): WP_REST_Request
{
    return new class ($route) extends WP_REST_Request {
        public function __construct(private string $route)
        {
            parent::__construct();
        }

        public function get_route(): string
        {
            return $this->route;
        }
    };
}

beforeEach(function (): void {
    $this->server = new OAuthServer(Settings::fromArray([]));
    $this->restServer = new WP_REST_Server();

    Functions\when('untrailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\'));
    Functions\when('status_header')->alias(static function (int $status): void {
        throw new McpcResponseSent($status);
    });
});

afterEach(function (): void {
    unset($_SERVER['REQUEST_URI']);
});

describe('hook registration', function (): void {
    it('attaches the front-end router, the REST routes and the response decorator', function (): void {
        $hooks = [];
        $record = static function (string $hook, callable $callback, int $priority = 10, int $args = 1) use (&$hooks): bool {
            $hooks[$hook] = [$priority, $args];

            return true;
        };
        Functions\when('add_action')->alias($record);
        Functions\when('add_filter')->alias($record);

        $this->server->register();

        expect($hooks)
            ->toHaveKey('parse_request')
            ->toHaveKey('rest_api_init')
            ->and($hooks['rest_post_dispatch'])->toBe([10, 3]);
    });
});

describe('the REST routes', function (): void {
    it('registers the five machine-facing routes, all public', function (): void {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $namespace, string $route, array $args) use (&$routes): void {
                $routes[$namespace . $route] = $args;
            },
        );

        $this->server->registerRestRoutes();

        expect(array_keys($routes))->toEqualCanonicalizing([
            'mcp-connector/v1/token',
            'mcp-connector/v1/revoke',
            'mcp-connector/v1/register',
            'mcp-connector/v1/protected-resource',
            'mcp-connector/v1/server-metadata',
        ]);

        foreach ($routes as $args) {
            expect($args['permission_callback'])->toBe('__return_true');
        }
    });

    it('takes credentials by POST and serves the discovery mirrors by GET only', function (): void {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $namespace, string $route, array $args) use (&$routes): void {
                $routes[$route] = $args['methods'];
            },
        );

        $this->server->registerRestRoutes();

        expect($routes['/token'])->toBe('POST')
            ->and($routes['/revoke'])->toBe('POST')
            ->and($routes['/register'])->toBe('POST')
            ->and($routes['/protected-resource'])->toBe('GET')
            ->and($routes['/server-metadata'])->toBe('GET');
    });

    it('serves the mirrored documents with the same content as the well-known ones', function (): void {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $namespace, string $route, array $args) use (&$routes): void {
                $routes[$route] = $args['callback'];
            },
        );

        $this->server->registerRestRoutes();

        $metadata = $routes['/server-metadata']()->get_data();

        expect($metadata['issuer'])->toBe(Endpoints::issuer())
            ->and($metadata['token_endpoint'])->toBe(Endpoints::token())
            ->and($routes['/protected-resource']()->get_data()['authorization_servers'])->toBe([Endpoints::issuer()]);
    });
});

describe('the response decorator', function (): void {
    it('opens up CORS on the OAuth routes', function (): void {
        $response = new WP_REST_Response([], 200);

        $this->server->decorateResponse($response, $this->restServer, mcpcRoutedRequest('/mcp-connector/v1/token'));

        expect($response->get_headers())
            ->toHaveKey('Access-Control-Allow-Origin', '*')
            ->toHaveKey('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->toHaveKey('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->not->toHaveKey('WWW-Authenticate');
    });

    it('leaves other routes without CORS headers', function (): void {
        $response = new WP_REST_Response([], 200);

        $this->server->decorateResponse($response, $this->restServer, mcpcRoutedRequest('/wp/v2/posts'));

        expect($response->get_headers())->toBe([]);
    });

    it('does not treat a namespace that merely shares the prefix as an OAuth route', function (): void {
        $response = new WP_REST_Response([], 200);

        $this->server->decorateResponse($response, $this->restServer, mcpcRoutedRequest('/mcp-connector/v10/token'));

        expect($response->get_headers())->toBe([]);
    });

    it('challenges a refused MCP request, pointing at the protected resource document', function (int $status): void {
        $response = new WP_REST_Response([], $status);

        $this->server->decorateResponse($response, $this->restServer, mcpcRoutedRequest('/mcp/connector'));

        expect($response->get_headers()['WWW-Authenticate'])->toBe(sprintf(
            'Bearer realm="%s", resource_metadata="%s"',
            'https://example.test',
            'https://example.test/.well-known/oauth-protected-resource',
        ));
    })->with([401, 403]);

    it('does not challenge a successful or otherwise failed MCP request', function (int $status): void {
        $response = new WP_REST_Response([], $status);

        $this->server->decorateResponse($response, $this->restServer, mcpcRoutedRequest('/mcp/connector'));

        expect($response->get_headers())->not->toHaveKey('WWW-Authenticate');
    })->with([200, 400, 404, 500]);

    it('does not challenge a refusal outside the MCP namespace', function (): void {
        $response = new WP_REST_Response([], 401);

        $this->server->decorateResponse($response, $this->restServer, mcpcRoutedRequest('/wp/v2/users'));

        expect($response->get_headers())->not->toHaveKey('WWW-Authenticate');
    });

    it('passes anything that is not a REST response through untouched', function (): void {
        $error = new WP_Error('rest_forbidden', 'No.');

        expect($this->server->decorateResponse($error, $this->restServer, mcpcRoutedRequest('/mcp/connector')))->toBe($error)
            ->and($this->server->decorateResponse(null, $this->restServer, mcpcRoutedRequest('/mcp/connector')))->toBeNull();
    });
});

describe('the front-end router', function (): void {
    it('does nothing for a path that is not an OAuth one', function (string $uri): void {
        $_SERVER['REQUEST_URI'] = $uri;

        $this->server->routeFrontEnd(new WP());

        expect(true)->toBeTrue();
    })->with([
        'home' => '/',
        'a page' => '/about/',
        'a near miss' => '/oauth/authorized',
        'a sub-path' => '/.well-known/oauth-protected-resource/extra',
        'another well-known' => '/.well-known/openid-configuration',
    ]);

    it('does nothing when there is no request URI', function (): void {
        unset($_SERVER['REQUEST_URI']);

        $this->server->routeFrontEnd(new WP());

        expect(true)->toBeTrue();
    });

    it('answers the two discovery paths with a 200 document', function (string $uri): void {
        $_SERVER['REQUEST_URI'] = $uri;

        try {
            $this->server->routeFrontEnd(new WP());
            $this->fail('The discovery path was not served.');
        } catch (McpcResponseSent $sent) {
            expect($sent->status)->toBe(200);
        }
    })->with([
        'protected resource' => '/.well-known/oauth-protected-resource',
        'server metadata' => '/.well-known/oauth-authorization-server',
        'trailing slash' => '/.well-known/oauth-authorization-server/',
        'query string' => '/.well-known/oauth-authorization-server?x=1',
        'percent-encoded' => '/%2Ewell-known/oauth-authorization-server',
    ]);

    it('strips the home path of a subdirectory installation before matching', function (): void {
        Functions\when('home_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/blog/' . ltrim($path, '/'),
        );
        $_SERVER['REQUEST_URI'] = '/blog/.well-known/oauth-protected-resource';

        try {
            $this->server->routeFrontEnd(new WP());
            $this->fail('The discovery path was not served.');
        } catch (McpcResponseSent $sent) {
            expect($sent->status)->toBe(200);
        }
    });

    it('does not match the OAuth path outside the installation subdirectory', function (): void {
        Functions\when('home_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/blog/' . ltrim($path, '/'),
        );
        $_SERVER['REQUEST_URI'] = '/other/.well-known/oauth-protected-resource';

        $this->server->routeFrontEnd(new WP());

        expect(true)->toBeTrue();
    });
});
