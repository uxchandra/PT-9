<?php

namespace Tests\Feature;

use App\Models\HeijunkaBoxCycleGroup;
use App\Models\HeijunkaBoxSchedule;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportHeijunkaBoxGroupingMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Same fixture as ImportHeijunkaBoxTd9ScheduleMigrationTest — see its own doc. */
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

    private function runMigrations(): void
    {
        foreach (self::ALL_PART_NOS as $partNo) {
            Part::firstOrCreate(['part_no' => $partNo]);
        }

        // The schema migration (2026_10_10, CREATE TABLE + ALTER TABLE) has
        // already run once — RefreshDatabase applies every migration file
        // for real before the first test, same as it does for every other
        // table in this suite. Only the two pure-data migrations need
        // re-running here, now that the parts they match against exist.
        $schedule = require database_path('migrations/2026_10_09_000000_import_heijunka_box_td9_schedule.php');
        $schedule->up();

        $grouping = require database_path('migrations/2026_10_11_000000_import_heijunka_box_grouping_data.php');
        $grouping->up();
    }

    public function test_creates_one_group_per_cycle_issue_in_sheet_order(): void
    {
        $this->runMigrations();

        $this->assertSame(
            ['1-2-X', '1-4-X', '1-5-X', '1-10-X', '1-1-X'],
            HeijunkaBoxCycleGroup::orderBy('sort_order')->pluck('cycle_issue')->all()
        );
    }

    public function test_a_groups_random_numbers_match_the_sheets_own_row(): void
    {
        $this->runMigrations();

        $group = HeijunkaBoxCycleGroup::where('cycle_issue', '1-2-X')->first();

        $this->assertNotNull($group);
        // Row 7 of the sheet, first half of the day (Cyc-1).
        $this->assertSame(1, $group->random_numbers['07:10']);
        $this->assertSame(9, $group->random_numbers['07:40']);
        $this->assertSame(16, $group->random_numbers['15:45']);
    }

    public function test_parts_get_a_sort_order_matching_the_sheets_own_row_order(): void
    {
        $this->runMigrations();

        $first = HeijunkaBoxSchedule::whereHas('part', fn ($q) => $q->where('part_no', '57183-BZ020'))->first();
        $second = HeijunkaBoxSchedule::whereHas('part', fn ($q) => $q->where('part_no', '57184-BZ030'))->first();

        $this->assertSame(0, $first->sort_order);
        $this->assertSame(1, $second->sort_order);
    }

    public function test_is_idempotent_when_run_more_than_once(): void
    {
        $this->runMigrations();
        $this->runMigrations();

        $this->assertSame(5, HeijunkaBoxCycleGroup::count());
    }
}
