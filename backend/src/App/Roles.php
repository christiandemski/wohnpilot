<?php

declare(strict_types=1);

namespace Wohnpilot\App;

/**
 * What a person of the household may do. Administrators may do everything;
 * members enter and tick off, but do not manage people, settings or homes
 * and do not delete; guests only read. Checked by the server on every request.
 */
final class Roles
{
    public const ADMIN = 'verwalter';
    public const MEMBER = 'mitglied';
    public const GUEST = 'gast';
    public const ALL = [self::ADMIN, self::MEMBER, self::GUEST];

    /** Writes anyone may do for themselves: their calendar link, push devices, loading the notes anew */
    private const OWN = ['#^/api/calendar$#', '#^/api/push/#', '#^/api/sync$#'];

    /** Writes for administrators only */
    private const ADMIN_ONLY = ['#^/api/people(/|$)#', '#^/api/settings$#', '#^/api/waste(/|$)#', '#^/api/objects(/|$)#', '#^/api/move(/|$)#', '#^/api/entries/#'];

    /** A role as written in _haushalt.md: missing means member, anything unknown may only read. */
    public static function of(mixed $role): string
    {
        if ($role === null || $role === '') {
            return self::MEMBER;
        }
        return in_array($role, self::ALL, true) ? $role : self::GUEST;
    }

    public static function allows(string $role, string $method, string $path): bool
    {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || $role === self::ADMIN) {
            return true;
        }
        foreach (self::OWN as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }
        if ($role !== self::MEMBER) {
            return false;
        }
        foreach (self::ADMIN_ONLY as $pattern) {
            if (preg_match($pattern, $path)) {
                return false;
            }
        }
        return true;
    }

    public static function forbidden(string $role): AppException
    {
        return new AppException('role', 403, "Not allowed for the role $role", ['role' => $role]);
    }
}
