<?php
/**
 * Minimal stand-ins for the WordPress classes the plugin type-hints against.
 *
 * Only the properties the plugin actually reads are declared. They exist so
 * `instanceof \WP_Post` can succeed in a process where WordPress was never
 * loaded; behaviour lives in Brain Monkey function mocks, not here.
 *
 * `WP_Ability` is the one that will need watching: it comes from the Abilities
 * API in core 6.9, and the shape below is what this plugin reads from it.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

if (!class_exists('WP_Post')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_post/
     */
    class WP_Post
    {
        public int $ID = 0;

        public string $post_title = '';

        public string $post_name = '';

        public string $post_content = '';

        public string $post_excerpt = '';

        public string $post_type = 'post';

        public string $post_status = 'publish';

        public string $post_password = '';

        public string $post_date = '';

        public string $post_modified = '';

        public int $post_author = 0;

        public int $post_parent = 0;

        public int $menu_order = 0;

        /**
         * @param  array<string, int|string>  $properties
         */
        public function __construct(array $properties = [])
        {
            foreach ($properties as $name => $value) {
                if (property_exists($this, $name)) {
                    $this->$name = $value;
                }
            }
        }
    }
}

if (!class_exists('WP_Post_Type')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_post_type/
     */
    class WP_Post_Type
    {
        public string $name = '';

        public string $label = '';

        public \stdClass $labels;

        public bool $hierarchical = false;

        public bool $public = true;

        public bool $show_in_rest = true;

        public string $capability_type = 'post';

        public \stdClass $cap;

        public function __construct(string $name = '', string $label = '', ?string $pluralLabel = null)
        {
            $this->name = $name;
            $this->label = $label;
            $this->labels = (object) ['name' => $pluralLabel ?? $label];
            $this->cap = (object) [
                'edit_post' => 'edit_post',
                'edit_posts' => 'edit_posts',
                'delete_post' => 'delete_post',
                'publish_posts' => 'publish_posts',
            ];
        }
    }
}

if (!class_exists('WP_Taxonomy')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_taxonomy/
     */
    class WP_Taxonomy
    {
        public string $name = '';

        public string $label = '';

        public bool $hierarchical = false;

        public bool $public = true;

        public bool $show_in_rest = true;

        /** @var list<string> */
        public array $object_type = [];

        public function __construct(string $name = '', string $label = '')
        {
            $this->name = $name;
            $this->label = $label;
        }
    }
}

if (!class_exists('WP_Term')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_term/
     */
    class WP_Term
    {
        public int $term_id = 0;

        public string $name = '';

        public string $slug = '';

        public string $taxonomy = '';

        public string $description = '';

        public int $parent = 0;

        public int $count = 0;

        /**
         * @param  array<string, int|string>  $properties
         */
        public function __construct(array $properties = [])
        {
            foreach ($properties as $name => $value) {
                if (property_exists($this, $name)) {
                    $this->$name = $value;
                }
            }
        }
    }
}

if (!class_exists('WP_User')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_user/
     */
    class WP_User
    {
        public int $ID = 0;

        public string $user_login = '';

        public string $user_email = '';

        public string $display_name = '';

        /** @var list<string> */
        public array $roles = [];

        /**
         * @param  array<string, mixed>  $properties
         */
        public function __construct(array $properties = [])
        {
            foreach ($properties as $name => $value) {
                if (property_exists($this, $name)) {
                    $this->$name = $value;
                }
            }
        }
    }
}

if (!class_exists('WP_Error')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_error/
     */
    class WP_Error
    {
        /** @var array<string, list<string>> */
        private array $errors = [];

        /** @var array<string, mixed> */
        private array $error_data = [];

        public function __construct(string $code = '', string $message = '', mixed $data = null)
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;

                if ($data !== null) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code(): string
        {
            return (string) (array_key_first($this->errors) ?? '');
        }

        public function get_error_message(string $code = ''): string
        {
            $code = $code !== '' ? $code : $this->get_error_code();

            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data(string $code = ''): mixed
        {
            $code = $code !== '' ? $code : $this->get_error_code();

            return $this->error_data[$code] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_rest_response/
     */
    class WP_REST_Response
    {
        /** @var array<string, string> */
        private array $headers = [];

        public function __construct(private mixed $data = null, private int $status = 200)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        /**
         * @return array<string, string>
         */
        public function get_headers(): array
        {
            return $this->headers;
        }

        public function header(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_rest_request/
     */
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params = [];

        /** @var array<string, string> */
        private array $headers = [];

        /**
         * @param  array<string, mixed>  $params
         * @param  array<string, string>  $headers
         */
        public function __construct(array $params = [], array $headers = [])
        {
            $this->params = $params;
            $this->headers = $headers;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_params(): array
        {
            return $this->params;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }
    }
}

if (!class_exists('WP_Ability')) {
    /**
     * Stand-in for the Abilities API class core registers in 6.9.
     *
     * Only what this plugin reads from a third-party ability: its name, labels,
     * category and meta — the last being where behaviour annotations live, and
     * therefore what the curation in Server\ExternalAbilities is deciding on.
     *
     * @see https://developer.wordpress.org/reference/classes/wp_ability/
     */
    class WP_Ability
    {
        /**
         * @param  array<string, mixed>  $meta
         */
        public function __construct(
            private string $name = '',
            private string $label = '',
            private string $description = '',
            private string $category = '',
            private array $meta = [],
        ) {
        }

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_label(): string
        {
            return $this->label;
        }

        public function get_description(): string
        {
            return $this->description;
        }

        public function get_category(): string
        {
            return $this->category;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_meta(): array
        {
            return $this->meta;
        }
    }
}

if (!class_exists('WP_Screen')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_screen/
     */
    class WP_Screen
    {
        public function __construct(public string $id = '', public string $base = '')
        {
        }
    }
}
