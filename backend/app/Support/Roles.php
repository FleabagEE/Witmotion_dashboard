<?php

namespace App\Support;

/**
 * Role to token-ability mapping.
 *
 * Abilities are what the API actually checks; roles are the human-facing name.
 * Keeping the mapping in one place means a new endpoint asks for an ability, not
 * a list of roles, and nobody has to remember which roles are "senior enough".
 */
final class Roles
{
    public const VIEWER = 'viewer';
    public const OPERATOR = 'operator';
    public const ENGINEER = 'engineer';
    public const ADMINISTRATOR = 'administrator';
    public const AUDITOR = 'auditor';
    public const KIOSK = 'kiosk';

    public const ALL = [
        self::VIEWER, self::OPERATOR, self::ENGINEER,
        self::ADMINISTRATOR, self::AUDITOR, self::KIOSK,
    ];

    /** @return array<int, string> */
    public static function abilitiesFor(string $role): array
    {
        return match ($role) {
            // A wall-mounted screen: read the live view and nothing else. It
            // cannot acknowledge, configure, or read the audit trail.
            self::KIOSK => ['read'],
            self::VIEWER => ['read'],
            self::OPERATOR => ['read', 'acknowledge'],
            self::ENGINEER => ['read', 'acknowledge', 'configure'],
            self::AUDITOR => ['read', 'audit'],
            self::ADMINISTRATOR => ['read', 'acknowledge', 'configure', 'audit', 'administer'],
            default => [],
        };
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
