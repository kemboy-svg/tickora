<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The few SQL fragments that differ between databases.
 *
 * Production runs on PostgreSQL (constraints, triggers and row locks
 * depend on it), but the app should still work on SQLite or MySQL for
 * quick local setups.
 */
final class Sql
{
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Case-insensitive LIKE. SQLite's and MySQL's LIKE already ignore case
     * (for ASCII / with the default collations); PostgreSQL needs ILIKE.
     */
    public static function like(): string
    {
        return self::driver() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Whether ORDER BY ... NULLS LAST is available (PostgreSQL, SQLite
     * 3.30+; not MySQL).
     */
    public static function supportsNullsLast(): bool
    {
        return ! in_array(self::driver(), ['mysql', 'mariadb'], true);
    }

    /**
     * A "YYYY-MM-DD" calendar day of a UTC timestamp column, in a timezone.
     *
     * PostgreSQL converts with the full timezone rules. SQLite and MySQL
     * have no timezone database by default, so they use the zone's current
     * UTC offset: exact for zones without daylight saving (Mauritius,
     * Kenya, South Africa), off by an hour around DST changes elsewhere.
     *
     * @return array{0: string, 1: list<string>} SQL and its bindings
     */
    public static function localDate(string $column, string $timezone): array
    {
        $minutes = intdiv(CarbonImmutable::now($timezone)->getOffset(), 60);

        return match (self::driver()) {
            'pgsql' => ["to_char({$column} at time zone ?, 'YYYY-MM-DD')", [$timezone]],
            'mysql', 'mariadb' => ["date_format(date_add({$column}, interval ? minute), '%Y-%m-%d')", [(string) $minutes]],
            default => ["strftime('%Y-%m-%d', {$column}, ?)", [sprintf('%+d minutes', $minutes)]],
        };
    }
}
