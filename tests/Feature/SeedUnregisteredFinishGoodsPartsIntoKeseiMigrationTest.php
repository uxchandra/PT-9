<?php

namespace Tests\Feature;

use App\Models\KeseiPart;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedUnregisteredFinishGoodsPartsIntoKeseiMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PART_NOS = [
        'B121-97517',
        '57184-BZ020',
        '67168-0K020',
        '67167-0K020',
        'B121-97513',
        '51321-BZ110',
    ];

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_07_000000_seed_unregistered_finish_goods_parts_into_kesei.php');
        $migration->up();
    }

    public function test_registers_every_listed_part_that_exists_in_the_part_list(): void
    {
        foreach (self::PART_NOS as $partNo) {
            Part::create(['part_no' => $partNo]);
        }

        $this->runMigration();

        foreach (self::PART_NOS as $partNo) {
            $part = Part::where('part_no', $partNo)->first();
            $this->assertNotNull(
                KeseiPart::where('part_id', $part->id)->first(),
                "Expected {$partNo} to be registered in Kesei."
            );
        }

        $this->assertSame(count(self::PART_NOS), KeseiPart::count());
    }

    public function test_urutan_continues_after_the_existing_highest_one_instead_of_colliding(): void
    {
        $existing = Part::create(['part_no' => 'ALREADY-THERE']);
        KeseiPart::create(['part_id' => $existing->id, 'urutan' => 5]);

        Part::create(['part_no' => 'B121-97517']);

        $this->runMigration();

        $new = KeseiPart::whereHas('part', fn ($q) => $q->where('part_no', 'B121-97517'))->first();
        $this->assertSame(6, $new->urutan);
    }

    public function test_skips_a_part_not_present_in_the_part_list_at_all(): void
    {
        // None of the seeded part_nos exist as Part rows here.
        $this->runMigration();

        $this->assertSame(0, KeseiPart::count());
    }

    public function test_is_idempotent_and_does_not_duplicate_an_already_registered_part(): void
    {
        $part = Part::create(['part_no' => 'B121-97517']);
        KeseiPart::create(['part_id' => $part->id, 'urutan' => 1]);

        $this->runMigration();

        $this->assertSame(1, KeseiPart::where('part_id', $part->id)->count());
    }
}
