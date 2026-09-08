<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatternBoardTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function seedBoard(): PatternBoard
    {
        $board = PatternBoard::create(['name' => 'A']);
        $m91 = Machine::create(['name' => 'PT91']);
        $m92 = Machine::create(['name' => 'PT92']);
        $alpha = Part::create(['part_no' => 'ALPHA-1']);
        $beta = Part::create(['part_no' => 'BETA-2']);

        foreach ([$alpha, $beta] as $i => $part) {
            PatternGroupItem::create([
                'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
                'urutan' => $i + 1, 'lot' => 10, 'loading_time' => 10, 'jumlah_proses' => 1,
                'total_kanban' => 1, 'dandori' => 0,
            ]);
        }

        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $m91->id, 'part_id' => $alpha->id, 'shift' => 1, 'proses' => 1]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $m92->id, 'part_id' => $beta->id, 'shift' => 1, 'proses' => 1]);

        return $board;
    }

    public function test_index_requires_the_manage_patterns_permission(): void
    {
        $this->get(route('pattern-boards.index'))->assertRedirect(route('login'));

        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->get(route('pattern-boards.index'))->assertForbidden();

        $this->actingAs($this->authorizedUser())->get(route('pattern-boards.index'))->assertOk();
    }

    public function test_search_filters_both_tables_by_part_no(): void
    {
        $board = $this->seedBoard();

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('pattern-boards.index', ['board' => $board->id, 'q' => 'ALPHA']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ALPHA-1', $html);
        $this->assertStringNotContainsString('BETA-2', $html);
    }

    public function test_search_matches_assignment_rows_by_machine_name(): void
    {
        $board = $this->seedBoard();

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('pattern-boards.index', ['board' => $board->id, 'q' => 'PT92']))
            ->assertOk()
            ->getContent();

        // PT92 runs BETA-2 — the assignment row for it must survive the filter.
        $this->assertStringContainsString('PT92', $html);
        $this->assertStringContainsString('BETA-2', $html);
        // ALPHA-1 (only on PT91) is filtered out of the Assignment table.
        $this->assertStringNotContainsString('PT91', $html);
    }

    public function test_ajax_request_returns_only_the_results_partial(): void
    {
        $board = $this->seedBoard();

        $response = $this->actingAs($this->authorizedUser())
            ->get(route('pattern-boards.index', ['board' => $board->id, 'q' => 'ALPHA']), ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('ALPHA-1', $html);
        $this->assertStringContainsString('id="kp-sortable"', $html);
        // No full page shell.
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('id="pattern-search"', $html);
    }

    public function test_ajax_partial_marks_the_reorder_table_locked_while_searching(): void
    {
        $board = $this->seedBoard();
        $user = $this->authorizedUser();

        $clean = $this->actingAs($user)
            ->get(route('pattern-boards.index', ['board' => $board->id]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->getContent();
        $this->assertStringContainsString('data-locked=""', $clean);

        $filtered = $this->actingAs($user)
            ->get(route('pattern-boards.index', ['board' => $board->id, 'q' => 'ALPHA']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->getContent();
        $this->assertStringContainsString('data-locked="1"', $filtered);
    }
}
