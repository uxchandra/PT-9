<?php

namespace Tests\Feature;

use App\Models\CalendarEntry;
use App\Models\PatternBoard;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_calendar_index_renders(): void
    {
        $this->actingAs($this->authorizedUser())
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('Calendar');
    }

    public function test_a_pattern_can_be_assigned_to_a_day(): void
    {
        $board = PatternBoard::create(['name' => 'A']);

        $this->actingAs($this->authorizedUser())
            ->post(route('calendar.store'), [
                'date' => '2026-09-03',
                'pattern_board_id' => $board->id,
            ])
            ->assertRedirect(route('calendar.index', ['month' => '2026-09']));

        $entry = CalendarEntry::first();
        $this->assertSame('2026-09-03', $entry->date);
        $this->assertSame($board->id, $entry->pattern_board_id);
    }

    public function test_reassigning_the_same_day_overwrites_the_pattern(): void
    {
        $a = PatternBoard::create(['name' => 'A']);
        $b = PatternBoard::create(['name' => 'B']);
        $user = $this->authorizedUser();

        $this->actingAs($user)->post(route('calendar.store'), [
            'date' => '2026-09-03', 'pattern_board_id' => $a->id,
        ]);
        $this->actingAs($user)->post(route('calendar.store'), [
            'date' => '2026-09-03', 'pattern_board_id' => $b->id,
        ]);

        $this->assertSame(1, CalendarEntry::count());
        $this->assertSame($b->id, CalendarEntry::first()->pattern_board_id);
    }

    public function test_pattern_board_is_required(): void
    {
        $this->actingAs($this->authorizedUser())
            ->post(route('calendar.store'), ['date' => '2026-09-03'])
            ->assertSessionHasErrors('pattern_board_id');

        $this->assertSame(0, CalendarEntry::count());
    }

    public function test_pattern_board_for_date_returns_the_assigned_board(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        CalendarEntry::create(['date' => '2026-09-03', 'pattern_board_id' => $board->id]);

        $this->assertSame('A', CalendarEntry::patternBoardForDate('2026-09-03')?->name);
        $this->assertNull(CalendarEntry::patternBoardForDate('2026-09-04'));
    }

    public function test_a_day_assignment_can_be_deleted(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $entry = CalendarEntry::create(['date' => '2026-09-03', 'pattern_board_id' => $board->id]);

        $this->actingAs($this->authorizedUser())
            ->delete(route('calendar.destroy', $entry))
            ->assertRedirect();

        $this->assertSame(0, CalendarEntry::count());
    }

    public function test_deleting_the_board_cascades_to_its_calendar_entries(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        CalendarEntry::create(['date' => '2026-09-03', 'pattern_board_id' => $board->id]);

        $board->delete();

        $this->assertSame(0, CalendarEntry::count());
    }

    public function test_staff_without_manage_patterns_cannot_open_the_calendar(): void
    {
        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('calendar.index'))->assertForbidden();
    }
}
