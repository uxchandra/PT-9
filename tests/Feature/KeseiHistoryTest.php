<?php

namespace Tests\Feature;

use App\Models\KeseiClosingNotification;
use App\Models\KeseiPart;
use App\Models\KeseiScan;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KeseiHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        (new RolePermissionSeeder)->run();
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_history_scan_lists_scans_newest_first_and_filters_by_part(): void
    {
        KeseiScan::create(['part_no' => 'AAA-111', 'location' => 'finish-goods', 'raw' => 'x_x_AAA-111_1', 'scanned_at' => Carbon::parse('2026-09-10 08:00')]);
        KeseiScan::create(['part_no' => 'BBB-222', 'location' => 'store-3', 'raw' => 'x_x_BBB-222_1', 'scanned_at' => Carbon::parse('2026-09-10 09:00')]);

        $this->actingAs($this->admin())
            ->get(route('kesei-scans.index'))
            ->assertOk()
            ->assertSee('AAA-111')
            ->assertSee('BBB-222')
            ->assertSeeInOrder(['BBB-222', 'AAA-111']); // newest first

        $this->actingAs($this->admin())
            ->get(route('kesei-scans.index', ['q' => 'AAA']))
            ->assertOk()
            ->assertSee('AAA-111')
            ->assertDontSee('BBB-222');
    }

    public function test_history_closing_lists_the_datetime_part_no_and_qty_kbn(): void
    {
        $kesei = KeseiPart::create(['part_id' => Part::create(['part_no' => 'CLOSE-1'])->id, 'urutan' => 1]);
        KeseiClosingNotification::create(['kesei_part_id' => $kesei->id, 'notified_on' => '2026-09-10', 'qty_kbn' => 17]);

        $this->actingAs($this->admin())
            ->get(route('kesei-closings.index'))
            ->assertOk()
            ->assertSee('History Closing Time')
            ->assertSeeInOrder(['CLOSE-1', '17']);
    }

    public function test_history_pages_require_manage_kesei(): void
    {
        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('kesei-scans.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('kesei-closings.index'))->assertForbidden();
    }
}
