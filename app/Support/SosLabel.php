<?php

namespace App\Support;

/**
 * Parses SOS label QR payloads shaped like "S9 09 I 26 A_8_57183-BZ010_1" —
 * the part number is the 3rd underscore-separated segment. Shared by every
 * scanner feature (Kesei, Lot Making, …) that reads this label format.
 */
class SosLabel
{
    public static function parsePartNo(string $raw): ?string
    {
        $segments = explode('_', trim($raw));

        $partNo = trim($segments[2] ?? '');

        return $partNo !== '' ? $partNo : null;
    }
}
