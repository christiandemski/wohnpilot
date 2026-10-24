<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use RuntimeException;

/**
 * An error the API reports to the client with a stable code; the frontend
 * turns the code into a German message.
 */
final class AppException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message = '',
        public readonly array $details = [],
    ) {
        parent::__construct($message === '' ? $errorCode : $message);
    }

    public static function notFound(string $what): self
    {
        return new self('not_found', 404, "$what not found");
    }

    /** @param array<string, string> $fields field => problem */
    public static function validation(array $fields): self
    {
        return new self('validation', 422, 'Invalid input', ['fields' => $fields]);
    }
}
