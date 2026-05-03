<?php

namespace Nyoze\Data;

use InvalidArgumentException;

/**
 * Shared identifier validation and quoting for SQL builders.
 *
 * Prevents SQL injection via table/column names by validating against
 * a whitelist and escaping backticks.
 */
trait SqlIdentifierTrait
{
    /**
     * Validate that an identifier contains only safe characters.
     *
     * Allowed: letters, digits, underscore, dot (qualified names), asterisk
     * (wildcard in SELECT) and backtick (already-quoted fragments).
     *
     * @throws InvalidArgumentException
     */
    private function validateIdentifier(string $identifier): void
    {
        if ($identifier === '*') {
            return;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.* `]*$/', $identifier)) {
            throw new InvalidArgumentException(
                "Invalid identifier: '{$identifier}'. Only alphanumeric characters, underscores, dots, asterisks and backticks are allowed."
            );
        }
    }

    /**
     * Escape internal backticks and wrap the identifier in backticks.
     *
     * Handles qualified names (e.g. "table.column" → "`table`.`column`").
     */
    public function quoteIdentifier(string $identifier): string
    {
        // Wildcard — return as-is
        if ($identifier === '*') {
            return '*';
        }

        // Qualified name: quote each part separately
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(function (string $part): string {
                if ($part === '*') {
                    return '*';
                }
                return '`' . str_replace('`', '``', $part) . '`';
            }, $parts));
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
