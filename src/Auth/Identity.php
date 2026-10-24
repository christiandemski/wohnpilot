<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

/** Who BearID says the user is. */
final readonly class Identity
{
    public function __construct(
        public string $subject,
        public string $username,
        public string $name = '',
        public string $email = '',
    ) {
    }

    public function toArray(): array
    {
        return ['subject' => $this->subject, 'username' => $this->username, 'name' => $this->name, 'email' => $this->email];
    }

    public static function fromArray(array $data): self
    {
        return new self((string) $data['subject'], (string) $data['username'], (string) ($data['name'] ?? ''), (string) ($data['email'] ?? ''));
    }
}
