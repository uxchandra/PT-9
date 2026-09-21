<?php

use App\Models\KeseiPart;
use App\Models\Part;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time seed: registers a batch of parts that were found to exist in the
 * Part List but weren't registered anywhere (neither Kesei nor Lot Making) —
 * see the part_no audit from 2026-09-21. Added to Kesei with no level/stock
 * source/closing time set yet (same blank state as adding one by hand via
 * the "Tambah" modal without filling those in) — someone still needs to
 * configure each one properly; this just gets them onto the list.
 *
 * Idempotent: skips a part_no that's already a KeseiPart, or that doesn't
 * exist in the Part List at all, so it's safe to run more than once (e.g.
 * across environments where some of these already got added by hand).
 */
return new class extends Migration
{
    private const PART_NOS = [
        'B121-97517',
        '57184-BZ020',
        '67168-0K020',
        '67167-0K020',
        'B121-97513',
        '51321-BZ110',
    ];

    public function up(): void
    {
        $nextUrutan = (int) KeseiPart::max('urutan') + 1;

        foreach (self::PART_NOS as $partNo) {
            $part = Part::where('part_no', $partNo)->first();

            if ($part === null || KeseiPart::where('part_id', $part->id)->exists()) {
                continue;
            }

            KeseiPart::create([
                'part_id' => $part->id,
                'urutan' => $nextUrutan,
            ]);

            $nextUrutan++;
        }
    }

    public function down(): void
    {
        $partIds = Part::whereIn('part_no', self::PART_NOS)->pluck('id');

        KeseiPart::whereIn('part_id', $partIds)->delete();
    }
};
