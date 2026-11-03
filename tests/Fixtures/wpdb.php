<?php
/**
 * An in-memory stand-in for `$wpdb`.
 *
 * It answers only the three statements OptionLock sends: the INSERT IGNORE that
 * takes a lock, the conditional DELETE that releases it and the SELECT that
 * reads its value. The rows are a plain array a test can read and rewrite, which
 * is how a lock abandoned by a dead request is set up.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

// phpcs:ignore Squiz.Classes.ClassFileName, PEAR.NamingConventions.ValidClassName -- The class has to be called wpdb.
final class wpdb
{
    /**
     * Name of the options table, as the real class exposes it.
     */
    public string $options = 'wp_options';

    /**
     * The lock rows, option name to value.
     *
     * @var array<string, string>
     */
    public array $rows = [];

    /**
     * Quote the %s placeholders the way the real prepare() does.
     */
    public function prepare(string $query, string ...$args): string
    {
        $index = 0;

        return (string) preg_replace_callback(
            '/%s/',
            static function () use (&$index, $args): string {
                return "'" . addslashes($args[$index++]) . "'";
            },
            $query,
        );
    }

    /**
     * Run an INSERT IGNORE or a conditional DELETE.
     *
     * @return int Rows affected.
     */
    public function query(string $sql): int
    {
        $values = $this->literals($sql);

        if (str_starts_with($sql, 'INSERT IGNORE')) {
            if (isset($this->rows[$values[0]])) {
                return 0;
            }

            $this->rows[$values[0]] = $values[1];

            return 1;
        }

        if (str_starts_with($sql, 'DELETE') && ($this->rows[$values[0]] ?? null) === $values[1]) {
            unset($this->rows[$values[0]]);

            return 1;
        }

        return 0;
    }

    /**
     * Run the SELECT that reads a lock's value.
     */
    public function get_var(string $sql): ?string
    {
        return $this->rows[$this->literals($sql)[0]] ?? null;
    }

    /**
     * The quoted literals of a prepared statement, in order.
     *
     * @return list<string>
     */
    private function literals(string $sql): array
    {
        preg_match_all("/'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", $sql, $matches);

        return array_map('stripslashes', $matches[1]);
    }
}
