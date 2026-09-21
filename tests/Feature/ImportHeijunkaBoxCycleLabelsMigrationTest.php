<?php

namespace Tests\Feature;

use App\Models\HeijunkaBoxCycleGroup;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportHeijunkaBoxCycleLabelsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Same fixture as the other Heijunka Box import tests — see their own doc. */
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

        // Schema for cycle_labels (2026_10_12) has already run for real via
        // RefreshDatabase's own initial migrate, same as every other table
        // in this suite — only the data-import migrations need re-running
        // here, now that the parts they key off of actually exist.
        $schedule = require database_path('migrations/2026_10_09_000000_import_heijunka_box_td9_schedule.php');
        $schedule->up();

        $grouping = require database_path('migrations/2026_10_11_000000_import_heijunka_box_grouping_data.php');
        $grouping->up();

        $labels = require database_path('migrations/2026_10_13_000000_import_heijunka_box_cycle_labels.php');
        $labels->up();
    }

    public function test_a_groups_cycle_labels_match_the_sheets_own_row(): void
    {
        $this->runMigrations();

        $group = HeijunkaBoxCycleGroup::where('cycle_issue', '1-2-X')->first();

        $this->assertNotNull($group);
        $this->assertSame('Cyc-1', $group->cycle_labels['07:10']);
        $this->assertSame('Cyc-2', $group->cycle_labels['20:05']);
        // No label on the other 30 slots for this group.
        $this->assertArrayNotHasKey('07:40', $group->cycle_labels);
    }

    public function test_a_group_with_more_sub_cycles_has_more_labels(): void
    {
        $this->runMigrations();

        $group = HeijunkaBoxCycleGroup::where('cycle_issue', '1-10-X')->first();

        $this->assertNotNull($group);
        $this->assertSame('Cyc-1', $group->cycle_labels['07:10']);
        $this->assertSame('Cyc-2', $group->cycle_labels['08:40']);
        $this->assertSame('Cyc-10', $group->cycle_labels['02:45']);
    }

    public function test_is_idempotent_when_run_more_than_once(): void
    {
        $this->runMigrations();
        $this->runMigrations();

        $group = HeijunkaBoxCycleGroup::where('cycle_issue', '1-2-X')->first();
        $this->assertSame('Cyc-1', $group->cycle_labels['07:10']);
    }
}
