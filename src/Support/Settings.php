<?php

namespace Board\Marketplace\Support;

use Illuminate\Support\Facades\DB;

/**
 * The marketplace master switch, persisted in the host's `settings` key/value
 * table (the same JSON shape the host app uses) — read/written via the query
 * builder so this package stays decoupled from the host's models.
 */
class Settings
{
    private const KEY = 'marketplace_enabled';

    public static function enabled(): bool
    {
        $value = DB::table('settings')->where('key', self::KEY)->value('value');

        return $value !== null && json_decode((string) $value, true) === true;
    }

    public static function setEnabled(bool $on): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($on)],
        );
    }
}
