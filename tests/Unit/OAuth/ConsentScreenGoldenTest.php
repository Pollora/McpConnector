<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ConsentScreen;

beforeEach(function (): void {
    Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'Ada <Admin>', 'user_login' => 'ada']);
    Functions\when('get_locale')->justReturn('fr_FR');
    Functions\when('get_bloginfo')->alias(static fn (string $show): string => match ($show) {
        'name' => 'Example & Site',
        'charset' => 'UTF-8',
        default => '',
    });
    Functions\when('wp_nonce_field')->alias(static fn (string $action, string $name, bool $referer, bool $echo): string => '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '-' . var_export($referer, true) . '-' . var_export($echo, true) . '">');
});

describe('the consent screen, whole', function (): void {
    it('renders the approval form with its scopes, hidden fields and identity', function (): void {
        $client = new Client('c1', '', 'The <App>', ['https://app.test/cb'], 'none', 0, false);

        $response = (new ConsentScreen())->approval($client, ['read', 'write'], [
            'client_id' => 'c1',
            'redirect_uri' => 'https://app.test/cb?a=1&b=2',
            'state' => 's"1',
        ]);

        expect($response->status)->toBe(200)
            ->and($response->headers['X-Frame-Options'])->toBe('DENY');
        mcpcAssertGolden('consent-approval', $response->body);
    });

    it('renders the approval form without scopes or parameters', function (): void {
        $client = new Client('c1', '', 'App', [], 'none', 0, false);

        mcpcAssertGolden('consent-approval-bare', (new ConsentScreen())->approval($client, [], [])->body);
    });

    it('renders a dead end with its status', function (): void {
        $response = (new ConsentScreen())->failure('Unknown <client>', 'The redirect URI & client do not match.', 403);

        expect($response->status)->toBe(403);
        mcpcAssertGolden('consent-failure', $response->body);
    });

    it('answers 400 to a dead end by default', function (): void {
        expect((new ConsentScreen())->failure('t', 'm')->status)->toBe(400);
    });
});
