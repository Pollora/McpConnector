<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

use Pollora\McpConnector\Http\HttpResponse;

defined('ABSPATH') || exit;

/**
 * Renders the pages the authorization endpoint shows a human.
 *
 * There is a consent screen at all because the alternative — silently issuing a
 * code to any registered client the moment a logged-in user lands on the
 * endpoint — turns every `<img src="/oauth/authorize?...">` on any site the user
 * visits into a grant. Dynamic registration is open, so obtaining a client
 * identifier to put in that URL costs one unauthenticated POST. The nonce on the
 * approval form is what actually closes this; the screen is what makes the
 * decision an informed one.
 *
 * Pages are rendered as self-contained documents rather than through the theme.
 * A theme can enqueue anything, and its header may well contain a navigation
 * menu that walks the user away mid-flow.
 */
final class ConsentScreen
{
    /**
     * Nonce action guarding the approval form.
     *
     * @var string
     */
    public const NONCE_ACTION = 'mcp_connector_authorize';

    /**
     * Render the approval form.
     *
     * @param Client             $client     Client asking for access.
     * @param list<string>       $scopes     Scopes it is asking for.
     * @param array<string, string> $params  Authorization request parameters to carry through the POST.
     *
     * @return HttpResponse The rendered page.
     */
    public function approval(Client $client, array $scopes, array $params): HttpResponse
    {
        $user = wp_get_current_user();

        $permissions = '';

        foreach ($scopes as $scope) {
            $permissions .= sprintf('<li>%s</li>', esc_html(Scope::describe($scope)));
        }

        $hidden = '';

        foreach ($params as $name => $value) {
            $hidden .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr($name),
                esc_attr($value),
            );
        }

        $body = sprintf(
            '<main class="mcpc-card">
                <h1>%1$s</h1>
                <p class="mcpc-lede">%2$s</p>
                <ul class="mcpc-scopes">%3$s</ul>
                <p class="mcpc-identity">%4$s</p>
                <form method="post" action="%5$s">
                    %6$s
                    %7$s
                    <div class="mcpc-actions">
                        <button type="submit" name="mcpc_decision" value="approve" class="mcpc-button mcpc-button--primary">%8$s</button>
                        <button type="submit" name="mcpc_decision" value="deny" class="mcpc-button">%9$s</button>
                    </div>
                </form>
                <p class="mcpc-footnote">%10$s</p>
            </main>',
            esc_html__('Authorise access', 'amphibee-mcp-connector'),
            sprintf(
                /* translators: 1: client application name, 2: site name. */
                esc_html__('%1$s is asking to connect to %2$s on your behalf. It will be able to:', 'amphibee-mcp-connector'),
                '<strong>' . esc_html($client->name) . '</strong>',
                '<strong>' . esc_html(get_bloginfo('name')) . '</strong>',
            ),
            $permissions,
            sprintf(
                /* translators: 1: user display name, 2: user login. */
                esc_html__('Acting as %1$s (%2$s). Everything it does will be recorded as done by you.', 'amphibee-mcp-connector'),
                '<strong>' . esc_html($user->display_name) . '</strong>',
                esc_html($user->user_login),
            ),
            esc_url(Endpoints::authorize()),
            $hidden,
            wp_nonce_field(self::NONCE_ACTION, '_wpnonce', true, false),
            esc_html__('Allow access', 'amphibee-mcp-connector'),
            esc_html__('Cancel', 'amphibee-mcp-connector'),
            sprintf(
                /* translators: %s: the redirect URI the authorization code will be sent to. */
                esc_html__('You will be returned to %s.', 'amphibee-mcp-connector'),
                '<code>' . esc_html($params['redirect_uri'] ?? '') . '</code>',
            ),
        );

        return HttpResponse::html($this->document(__('Authorise access', 'amphibee-mcp-connector'), $body));
    }

    /**
     * Render a dead end: something is wrong that cannot safely be reported to the client.
     *
     * Used when the client identifier or the redirect URI is not one we
     * recognise. In that situation there is no address we are willing to send an
     * error to — redirecting to an unverified URI is the open-redirect hole that
     * exact-match validation exists to prevent — so the message goes to the
     * person in front of the browser instead.
     *
     * @param string $title   Short heading.
     * @param string $message What went wrong, in plain language.
     * @param int    $status  HTTP status code.
     *
     * @return HttpResponse The rendered page.
     */
    public function failure(string $title, string $message, int $status = 400): HttpResponse
    {
        $body = sprintf(
            '<main class="mcpc-card">
                <h1>%1$s</h1>
                <p class="mcpc-lede">%2$s</p>
                <p class="mcpc-footnote"><a href="%3$s">%4$s</a></p>
            </main>',
            esc_html($title),
            esc_html($message),
            esc_url(home_url('/')),
            esc_html(
                sprintf(
                    /* translators: %s: site name. */
                    __('Back to %s', 'amphibee-mcp-connector'),
                    get_bloginfo('name'),
                ),
            ),
        );

        return HttpResponse::html($this->document($title, $body), $status);
    }

    /**
     * Wrap a fragment in a minimal standalone HTML document.
     *
     * @param string $title Document title.
     * @param string $body  Already-escaped body markup.
     *
     * @return string The complete document.
     */
    private function document(string $title, string $body): string
    {
        return sprintf(
            '<!DOCTYPE html>
<html lang="%1$s">
<head>
<meta charset="%2$s">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>%3$s</title>
<style>%4$s</style>
</head>
<body>%5$s</body>
</html>',
            esc_attr(str_replace('_', '-', get_locale())),
            esc_attr(get_bloginfo('charset')),
            esc_html($title . ' — ' . get_bloginfo('name')),
            $this->styles(),
            $body,
        );
    }

    /**
     * The stylesheet, inlined.
     *
     * Inline because this page must render correctly on a site whose theme is
     * broken, whose asset host is down, or whose Content Security Policy forbids
     * external stylesheets — the user is mid-flow and has nowhere to go if it
     * does not.
     *
     * @return string The CSS.
     */
    private function styles(): string
    {
        return <<<'CSS'
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem; background: #f0f0f1; color: #1e1e1e;
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .mcpc-card {
            background: #fff; max-width: 32rem; width: 100%; padding: 2rem;
            border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.13);
        }
        h1 { margin: 0 0 1rem; font-size: 1.5rem; line-height: 1.3; }
        .mcpc-lede { margin: 0 0 1rem; }
        .mcpc-scopes { margin: 0 0 1.5rem; padding-left: 1.25rem; }
        .mcpc-scopes li { margin-bottom: .375rem; }
        .mcpc-identity {
            margin: 0 0 1.5rem; padding: .75rem 1rem; border-radius: 4px;
            background: #f6f7f7; border-left: 3px solid #2271b1; font-size: .9375rem;
        }
        .mcpc-actions { display: flex; gap: .75rem; flex-wrap: wrap; }
        .mcpc-button {
            font: inherit; padding: .5rem 1.25rem; border-radius: 3px; cursor: pointer;
            border: 1px solid #2271b1; background: #f6f7f7; color: #2271b1;
        }
        .mcpc-button--primary { background: #2271b1; color: #fff; }
        .mcpc-button:hover { filter: brightness(.95); }
        .mcpc-footnote { margin: 1.5rem 0 0; font-size: .8125rem; color: #646970; overflow-wrap: anywhere; }
        code { font-size: .8125rem; }
        @media (prefers-color-scheme: dark) {
            body { background: #1d2327; color: #f0f0f1; }
            .mcpc-card { background: #2c3338; box-shadow: none; }
            .mcpc-identity { background: #1d2327; border-left-color: #72aee6; }
            .mcpc-button { background: #2c3338; border-color: #72aee6; color: #72aee6; }
            .mcpc-button--primary { background: #2271b1; border-color: #2271b1; color: #fff; }
            .mcpc-footnote { color: #a7aaad; }
        }
        CSS;
    }
}
