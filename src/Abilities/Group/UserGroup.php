<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities\Group;

use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\AbilityDefinition;
use Pollora\McpConnector\Abilities\AbilityGroup;
use Pollora\McpConnector\Abilities\Input;
use Pollora\McpConnector\Abilities\Schema;
use Pollora\McpConnector\Support\Failure;
use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Reading user accounts.
 *
 * Read-only by design, and disabled by default. Creating users, changing roles
 * and resetting passwords are privilege-escalation primitives; handing them to a
 * remote client that a prompt can steer is a poor trade for the small
 * convenience. Email addresses are only returned to callers who hold
 * `edit_users`, so a token scoped to editing posts sees display names and
 * nothing more.
 */
final class UserGroup implements AbilityGroup
{
    /**
     * Stable group key stored in the settings option.
     *
     * @var string
     */
    public const KEY = 'users';

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('Users', 'amphibee-mcp-connector');
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return __(
            'Read the user list and individual profiles. Never writes: no account creation, role change or password reset.',
            'amphibee-mcp-connector'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function definitions(): array
    {
        return [
            $this->listUsers(),
            $this->getUser(),
        ];
    }

    /**
     * List users, optionally filtered by role.
     *
     * @return AbilityDefinition The ability.
     */
    private function listUsers(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-users',
            label: __('List users', 'amphibee-mcp-connector'),
            description: 'List user accounts with their display names and roles. Useful for finding the '
                . 'author ID to filter posts by, or to assign as a post author.',
            category: AbilityCategory::Users,
            inputSchema: Schema::object([
                'role' => Schema::string('Filter by role slug, such as editor or author.'),
                'search' => Schema::string('Match users whose login, display name or email contains this text.'),
                'number' => Schema::integer('How many users to return, at most 100.', 20, 1, 100),
            ]),
            execute: static function (Input $input): array {
                $args = ['number' => $input->integer('number', 20, 1, 100)];

                if ($input->filled('role')) {
                    $args['role'] = $input->string('role');
                }

                if ($input->filled('search')) {
                    $args['search'] = '*' . $input->string('search') . '*';
                }

                return [
                    'users' => array_map(self::format(...), get_users($args)),
                ];
            },
            permission: static fn (): bool => current_user_can('list_users'),
        );
    }

    /**
     * Read one user.
     *
     * @return AbilityDefinition The ability.
     */
    private function getUser(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-user',
            label: __('Read a user', 'amphibee-mcp-connector'),
            description: 'Retrieve one user account by ID.',
            category: AbilityCategory::Users,
            inputSchema: Schema::object([
                'id' => Schema::integer('ID of the user to read.', minimum: 1),
            ], ['id']),
            execute: static function (Input $input): array|WP_Error {
                $user = get_userdata($input->id('id'));

                return $user instanceof WP_User
                    ? self::format($user)
                    : Failure::notFound('user', $input->id('id'));
            },
            permission: static fn (): bool => current_user_can('list_users'),
        );
    }

    /**
     * Render a user, withholding the email address from callers who may not see it.
     *
     * @param WP_User $user The user to render.
     *
     * @return array<string, mixed> The user record.
     */
    private static function format(WP_User $user): array
    {
        $record = [
            'id' => $user->ID,
            'login' => $user->user_login,
            'display_name' => $user->display_name,
            'roles' => array_values($user->roles),
            'url' => get_author_posts_url($user->ID),
            'registered' => $user->user_registered,
        ];

        if (current_user_can('edit_users')) {
            $record['email'] = $user->user_email;
        }

        return $record;
    }
}
