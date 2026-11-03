<?php

declare(strict_types=1);

/**
 * A request carrying form parameters and, optionally, headers.
 *
 * The shared WP_REST_Request double has no body accessors; the token endpoint
 * reads JSON first and falls back to the form body.
 *
 * @param array<string, mixed>  $params
 * @param array<string, string> $headers Lower-cased header names.
 * @param array<string, mixed>|null $json
 */
function mcpcTokenRequest(array $params, array $headers = [], ?array $json = null): WP_REST_Request
{
    return new class ($params, $headers, $json) extends WP_REST_Request {
        /**
         * @param array<string, string>     $headers
         */
        public function __construct(private array $formParams, array $headers, private ?array $jsonParams)
        {
            parent::__construct($formParams, $headers);
        }

        public function get_json_params(): ?array
        {
            return $this->jsonParams;
        }

        public function get_body_params(): array
        {
            return $this->formParams;
        }
    };
}
