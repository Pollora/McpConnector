<?php

declare(strict_types=1);

use Pollora\McpConnector\OAuth\Controller\DiscoveryController;
use Pollora\McpConnector\Settings;

describe('the authorization server metadata', function (): void {
    it('says how a client authenticates at the revocation endpoint, as at the token endpoint', function (): void {
        $metadata = (new DiscoveryController(Settings::fromArray([])))->authorizationServer()->get_data();

        expect($metadata['revocation_endpoint_auth_methods_supported'])
            ->toBe($metadata['token_endpoint_auth_methods_supported'])
            ->toContain('client_secret_post', 'client_secret_basic', 'none');
    });
});
