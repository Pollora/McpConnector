<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Admin\Diagnostics;
use Pollora\McpConnector\Admin\SettingsPage;
use Pollora\McpConnector\Admin\Wizard;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';

const ADMINSMALL_WIZARD_PROBE_KEY = 'mcp_connector_discovery_probe';

beforeEach(function (): void {
    adminsmallPrepare();
    $_GET = [];

    Functions\when('wp_roles')->justReturn((object) ['roles' => [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['edit_posts' => true]],
    ]]);
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('wp_nonce_url')->alias(static fn (string $url, string $action): string => $url . '&_wpnonce=nonce-' . $action);
    Functions\when('wp_verify_nonce')->alias(static fn (string $nonce, string $action): int|false => $nonce === 'nonce-' . $action ? 1 : false);
    $this->setOption('permalink_structure', '/%postname%/');
    $this->transientValues[ADMINSMALL_WIZARD_PROBE_KEY] = [
        'https://example.test/.well-known/oauth-protected-resource' => 200,
        'https://example.test/.well-known/oauth-authorization-server' => 200,
    ];
});

afterEach(function (): void {
    $_GET = [];
});

/**
 * Render the assistant at a given step.
 *
 * @param array<string, mixed> $query    The query string.
 * @param array<string, mixed> $settings Stored settings.
 * @param list<Client>         $clients  Registered clients.
 * @param array<string, array{count: int, users: list<int>, issued: int, expires: int}> $tokens Live tokens.
 */
function adminsmallWizard(array $query = [], array $settings = [], array $clients = [], array $tokens = []): string
{
    $_GET = $query;

    return adminsmallCapture(fn () => (new Wizard(Settings::fromArray($settings), $clients, $tokens))->render());
}

describe('the step addresses', function (): void {
    it('builds a link to the hidden settings page carrying the step', function (): void {
        expect(Wizard::url('connect'))->toBe('https://example.test/wp-admin/options-general.php?page=mcp-connector-setup&step=connect')
            ->and(Wizard::url())->toContain('step=check');
    });

    it('recognises exactly the four steps', function (): void {
        foreach (['check', 'expose', 'connect', 'verify'] as $step) {
            expect(Wizard::isStep($step))->toBeTrue();
        }

        expect(Wizard::isStep(''))->toBeFalse()
            ->and(Wizard::isStep('CHECK'))->toBeFalse()
            ->and(Wizard::isStep('check '))->toBeFalse()
            ->and(Wizard::isStep('../expose'))->toBeFalse();
    });
});

describe('the step selection', function (): void {
    it('opens on the preparation step by default', function (): void {
        expect(adminsmallWizard())->toContain('Step 1 of 4');
    });

    it('shows the step the query asks for', function (): void {
        expect(adminsmallWizard(['step' => 'expose']))->toContain('Step 2 of 4')
            ->and(adminsmallWizard(['step' => 'connect']))->toContain('Step 3 of 4')
            ->and(adminsmallWizard(['step' => 'verify']))->toContain('Step 4 of 4');
    });

    it('falls back to the first step for an unknown, hostile or array-valued step', function (): void {
        foreach (['nope', '<script>alert(1)</script>', ['expose'], 42] as $hostile) {
            $html = adminsmallWizard(['step' => $hostile]);

            expect($html)->toContain('Step 1 of 4')->and($html)->not->toContain('<script>alert');
        }
    });

    it('normalises the step the way a key is normalised', function (): void {
        expect(adminsmallWizard(['step' => 'EXPOSE']))->toContain('Step 2 of 4');
    });
});

describe('the progress band', function (): void {
    it('links every step and marks the current one', function (): void {
        $html = adminsmallWizard(['step' => 'connect']);

        expect(substr_count($html, 'mcpc-setup__steplink'))->toBe(4)
            ->and($html)->toContain('mcpc-setup__step is-current')
            ->and(substr_count($html, 'aria-current="step"'))->toBe(1)
            ->and($html)->toContain('Leave the assistant');
    });

    it('marks the unmeasured steps before the current one as done, and never the last without a token', function (): void {
        $html = adminsmallWizard(['step' => 'connect']);

        expect($html)->toMatch('/is-done"><a[^>]*><span[^>]*>Reach</')
            ->and($html)->not->toMatch('/is-done"><a[^>]*><span[^>]*>Verify</');
    });

    it('marks the last step done only when a client holds a token', function (): void {
        $none = adminsmallWizard(['step' => 'check'], [], [], ['client-1' => ['count' => 0, 'users' => [], 'issued' => 0, 'expires' => 0]]);
        $live = adminsmallWizard(['step' => 'check'], [], [], ['client-1' => ['count' => 2, 'users' => [1], 'issued' => 0, 'expires' => 0]]);

        expect(substr_count($none, 'is-done'))->toBe(substr_count($live, 'is-done') - 1);
    });

    it('does not mark the first step done while a check blocks', function (): void {
        $this->setOption('permalink_structure', '');

        expect(adminsmallWizard(['step' => 'expose']))->not->toContain('is-done');
    });
});

describe('the preparation step', function (): void {
    it('words its verdict and its primary action after what the diagnostics found', function (): void {
        $html = adminsmallWizard();
        $blocked = Diagnostics::blocked(Settings::fromArray([]));

        expect($html)->toContain($blocked ? 'Something here blocks every client' : 'The site is ready')
            ->and(str_contains($html, 'Continue anyway'))->toBe($blocked)
            ->and(str_contains($html, 'Ask the server again'))->toBe($blocked);
    });

    it('stops at a blocking failure but leaves a quiet way past it', function (): void {
        $this->setOption('permalink_structure', '');

        $html = adminsmallWizard();

        expect($html)->toContain('Something here blocks every client')
            ->and($html)->toContain('Ask the server again')
            ->and($html)->toContain('Continue anyway')
            ->and($html)->toContain('href="https://example.test/wp-admin/options-general.php?page=mcp-connector-setup&step=expose"');
    });

    it('offers the probe link under the preparation step', function (): void {
        $this->setOption('permalink_structure', '');

        expect(adminsmallWizard())->toContain('probe=1');
    });

    it('re-requests the discovery documents only when asked to', function (): void {
        Functions\expect('wp_remote_get')->twice()->andReturn(['code' => 200]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        adminsmallWizard(['probe' => '1', '_wpnonce' => 'nonce-' . SettingsPage::NONCE_PROBE]);

        expect(true)->toBeTrue();
    });

    it('does not re-request anything for a probe link without a valid nonce', function (): void {
        Functions\expect('wp_remote_get')->never();

        adminsmallWizard(['probe' => '1']);
        adminsmallWizard(['probe' => '1', '_wpnonce' => 'forged']);
        adminsmallWizard(['probe' => '1', '_wpnonce' => ['nonce-' . SettingsPage::NONCE_PROBE]]);
    });

    it('signs the probe link with a nonce', function (): void {
        $this->setOption('permalink_structure', '');

        expect(adminsmallWizard())->toContain('_wpnonce=nonce-' . SettingsPage::NONCE_PROBE);
    });

    it('does not request anything on an ordinary page load', function (): void {
        Functions\expect('wp_remote_get')->never();

        adminsmallWizard();
    });
});

describe('the reach step', function (): void {
    it('carries a nonce and the step marker inside the form that saves', function (): void {
        $html = adminsmallWizard(['step' => 'expose']);

        expect($html)->toContain('<form method="post" id="mcpc-wizard-form">')
            ->and($html)->toContain('name="_wpnonce" value="nonce-' . SettingsPage::NONCE_SETTINGS . '"')
            ->and($html)->toContain('name="mcpc_step" value="connect"')
            ->and($html)->toContain('name="mcp_connector_wizard_save" value="1" form="mcpc-wizard-form"');
    });

    it('reflects the stored settings in the two switches', function (): void {
        $on = adminsmallWizard(['step' => 'expose'], ['read_only_mode' => true, 'dynamic_registration_open' => false]);
        $off = adminsmallWizard(['step' => 'expose'], ['read_only_mode' => false, 'dynamic_registration_open' => true]);

        expect($on)->toMatch('/name="read_only_mode" value="1" checked/')
            ->and($on)->not->toMatch('/name="dynamic_registration_open" value="1" checked/')
            ->and($off)->not->toMatch('/name="read_only_mode" value="1" checked/')
            ->and($off)->toMatch('/name="dynamic_registration_open" value="1" checked/');
    });

    it('names the capability and the number of addressable post types', function (): void {
        $html = adminsmallWizard(['step' => 'expose'], ['required_capability' => 'edit_pages']);

        expect($html)->toContain('A user needs the edit_pages capability')->toContain('3 post types are currently addressable');
    });

    it('writes nothing when it is merely displayed', function (): void {
        Functions\expect('update_option')->never();

        adminsmallWizard(['step' => 'expose']);

        expect($this->optionValues)->not->toHaveKey(Settings::OPTION);
    });
});

describe('the URL step', function (): void {
    it('hands over the endpoint URL and every walkthrough', function (): void {
        $html = adminsmallWizard(['step' => 'connect']);

        expect($html)->toContain('value="https://example.test/wp-json/mcp/connector"')
            ->and(substr_count($html, '<details class="mcpc-guide__client">'))->toBe(4);
    });

    it('follows a renamed server route', function (): void {
        $this->setSettings(['server_route' => 'site_two']);

        expect(adminsmallWizard(['step' => 'connect']))
            ->toContain('https://example.test/wp-json/mcp/site-two');
    });

    it('mentions registered applications that never completed, in the right number', function (): void {
        $client = new Client('c1', 'h', 'App', ['https://a.test/cb'], 'none', 0, true);

        $one = adminsmallWizard(['step' => 'connect'], [], [$client]);
        $two = adminsmallWizard(['step' => 'connect'], [], [$client, $client]);
        $none = adminsmallWizard(['step' => 'connect']);

        expect($one)->toContain('1 application has already registered')
            ->and($two)->toContain('2 applications have already registered')
            ->and($none)->not->toContain('already registered');
    });
});

describe('the verification step', function (): void {
    it('invites a test when nothing holds a token', function (): void {
        $html = adminsmallWizard(['step' => 'verify']);

        expect($html)->toContain('See it work')
            ->and($html)->toContain('id="mcpc-run-test"')
            ->and($html)->toContain('mcpc-button--quiet');
    });

    it('acknowledges a live token and promotes the way out', function (): void {
        $html = adminsmallWizard(['step' => 'verify'], [], [], ['c1' => ['count' => 1, 'users' => [1], 'issued' => 0, 'expires' => 0]]);

        expect($html)->toContain('Something is connected')
            ->and($html)->toContain('mcpc-button--primary" href="https://example.test/wp-admin/options-general.php?page=mcp-connector&tab=dashboard"');
    });

    it('counts a token held by any client, not only the first', function (): void {
        $tokens = [
            'a' => ['count' => 0, 'users' => [], 'issued' => 0, 'expires' => 0],
            'b' => ['count' => 3, 'users' => [1], 'issued' => 0, 'expires' => 0],
        ];

        expect(adminsmallWizard(['step' => 'verify'], [], [], $tokens))->toContain('Something is connected');
    });
});
