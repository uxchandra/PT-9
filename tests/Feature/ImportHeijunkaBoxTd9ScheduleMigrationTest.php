<?php

namespace Tests\Feature;

use App\Models\HeijunkaBoxSchedule;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportHeijunkaBoxTd9ScheduleMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every part_no the source sheet covers (see the migration's own GROUPS
     * constant) — a fresh test database has no Part rows at all, so without
     * these the migration would skip everything. Real environments already
     * have every one of these registered (verified 2026-09-21), which is
     * exactly what this fixture stands in for.
     */
    private const ALL_PART_NOS = [
        '57183-BZ020', '57184-BZ030', '51321-BZ100', '57183-BZ010', '57433-BZ020',
        '57184-BZ020', '57434-BZ020', 'JJB03-001530', '55114-VT020', '61642-VT020',
        'B113-97514', 'B111-97510', 'B122-97505', 'B121-97514', 'B121-97512',
        'B114-97515', 'B121-97515', 'B111-97511', 'B121-97517', 'B111-97505',
        'B114-97512', 'B122-97504', 'B121-97518', 'B111-97502', 'B114-97511', 'B121-97511',
        '61673-BZ020', '61674-BZ020', '67391-KK010',
        '33146-0K060',
        '13-10681-A09', '64123-TG2-K000-H1', '65325-T86-K000-50', '65325-T86-X000-50',
        '61143-T86-X000-50', '61143-T86-K000-50', 'GA241-06980', 'GA251-24260',
    ];

    private function seedAllParts(): void
    {
        foreach (self::ALL_PART_NOS as $partNo) {
            Part::firstOrCreate(['part_no' => $partNo]);
        }
    }

    private function runMigration(): void
    {
        $this->seedAllParts();

        $migration = require database_path('migrations/2026_10_09_000000_import_heijunka_box_td9_schedule.php');
        $migration->up();
    }

    public function test_imports_every_part_that_has_at_least_one_marked_slot(): void
    {
        // The 38 part_nos on the source sheet, minus the one whose row is
        // entirely blank (Kbn per Cycle = 0 there) — nothing to schedule for
        // it, so it's correctly left out rather than getting an empty row.
        $this->runMigration();

        $this->assertSame(37, HeijunkaBoxSchedule::count());
    }

    public function test_a_known_part_gets_exactly_the_slots_marked_for_it_on_the_sheet(): void
    {
        $this->runMigration();

        $schedule = HeijunkaBoxSchedule::whereHas('part', fn ($q) => $q->where('part_no', '57183-BZ020'))->first();

        $this->assertNotNull($schedule);
        $this->assertSame('1-2-X', $schedule->cycle_issue);
        // 7 slots in shift 1 (07:10 → 15:45), the identical relative
        // pattern repeated in shift 2 (20:05 → 04:55 the next day).
        $this->assertSame([
            '07:10', '08:10', '09:10', '10:20', '11:20', '13:05', '14:05',
            '20:05', '21:05', '22:05', '23:05', '00:45', '01:45', '02:45',
        ], $schedule->slots);
    }

    public function test_a_slot_marked_with_a_count_above_1_repeats_that_time_in_the_list(): void
    {
        // The "1-5-X" group's cells hold small counts (2 or 3), not just 1 —
        // e.g. 3 kanban all released at the exact same 07:10 slot.
        $this->runMigration();

        $schedule = HeijunkaBoxSchedule::whereHas('part', fn ($q) => $q->where('part_no', '61673-BZ020'))->first();

        $this->assertNotNull($schedule);
        $this->assertSame('1-5-X', $schedule->cycle_issue);
        $this->assertSame(3, substr_count(json_encode($schedule->slots), '"07:10"'));
    }

    public function test_a_part_with_no_marked_slots_at_all_is_left_out(): void
    {
        $this->runMigration();

        $part = Part::where('part_no', '61143-T86-X000-50')->first();
        $this->assertNotNull($part);
        $this->assertNull(HeijunkaBoxSchedule::where('part_id', $part->id)->first());
    }

    public function test_is_idempotent_when_run_more_than_once(): void
    {
        $this->runMigration();
        $this->runMigration();

        $this->assertSame(37, HeijunkaBoxSchedule::count());
    }
}
