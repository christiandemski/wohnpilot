<?php

declare(strict_types=1);

namespace Wohnpilot\App;

/** The signed-in person. $id is the person id used in erstellt_von / geaendert_von, $role one of Roles. */
final readonly class User
{
    public function __construct(
        public string $id,
        public string $name,
        public string $subject,
        public string $email = '',
        public string $role = Roles::MEMBER,
    ) {
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'role' => $this->role];
    }
}
