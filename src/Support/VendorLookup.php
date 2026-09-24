<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Looks up a device vendor from its MAC address OUI prefix, using the
 * small curated table in oui-table.php. Best-effort only — see that
 * file's docblock for coverage caveats.
 */
final class VendorLookup
{
    private static ?array $table = null;

    public static function lookup(?string $mac): ?string
    {
        if ($mac === null || $mac === '') {
            return null;
        }

        $normalized = strtoupper(str_replace([':', '-'], '', $mac));
        if (strlen($normalized) < 6) {
            return null;
        }

        $prefix = substr($normalized, 0, 6);

        return self::table()[$prefix] ?? null;
    }

    private static function table(): array
    {
        if (self::$table === null) {
            self::$table = require __DIR__ . '/oui-table.php';
        }

        return self::$table;
    }
}
