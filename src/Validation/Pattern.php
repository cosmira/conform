<?php

declare(strict_types=1);

namespace Cosmira\Conform\Validation;

final class Pattern
{
    /**
     * Treat an invalid OpenAPI pattern as a mismatch without emitting a PHP warning.
     */
    public static function matches(string $pattern, string $value): bool
    {
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            return preg_match('/'.$pattern.'/u', $value) === 1;
        } finally {
            restore_error_handler();
        }
    }
}
