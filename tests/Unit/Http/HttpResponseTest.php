<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Http\HttpResponse;

beforeEach(function (): void {
    Functions\when('get_bloginfo')->alias(static fn (string $show): string => $show === 'charset' ? 'UTF-8' : '');
});

describe('an HTML response', function (): void {
    it('is never cached and never framed, with the status it was given', function (): void {
        $response = HttpResponse::html('<p>x</p>', 403);

        expect($response->body)->toBe('<p>x</p>')
            ->and($response->status)->toBe(403)
            ->and($response->headers)->toBe([
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'CDN-Cache-Control' => 'no-store',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
            ]);
    });

    it('answers 200 by default', function (): void {
        expect(HttpResponse::html('')->status)->toBe(200);
    });
});

describe('a JSON response', function (): void {
    it('carries its content type and a no-store policy beside the given headers', function (): void {
        $response = HttpResponse::json('{"a":1}', 201, ['X-Custom' => 'yes']);

        expect($response->body)->toBe('{"a":1}')
            ->and($response->status)->toBe(201)
            ->and($response->headers)->toBe([
                'X-Custom' => 'yes',
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
    });

    it('lets a carried-over header win over the defaults', function (): void {
        $response = HttpResponse::json('{}', 200, ['Cache-Control' => 'max-age=60']);

        expect($response->headers['Cache-Control'])->toBe('max-age=60')
            ->and(HttpResponse::json('{}')->status)->toBe(200);
    });
});

describe('a redirect', function (): void {
    it('is a bodiless 302 that nothing may keep a copy of', function (): void {
        $response = HttpResponse::redirect('https://app.test/cb?code=abc');

        expect($response->body)->toBe('')
            ->and($response->status)->toBe(302)
            ->and($response->headers)->toBe([
                'Location' => 'https://app.test/cb?code=abc',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'CDN-Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
    });
});
