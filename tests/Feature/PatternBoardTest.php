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

        // Scoped to the table rows: each row's edit modal (appended after the
        // tables) legitimately lists every part on the board — search-filtering
        // it too would make editing a filtered-out row's part impossible.
        $tablesHtml = $this->tablesOnly($html);

        $this->assertStringContainsString('ALPHA-1', $tablesHtml);
        $this->assertStringNotContainsString('BETA-2', $tablesHtml);
    }

    public function test_search_matches_assignment_rows_by_machine_name(): void
    {
        $board = $this->seedBoard();

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('pattern-boards.index', ['board' => $board->id, 'q' => 'PT92']))
            ->assertOk()
            ->getContent();

        // Scoped to the table rows: each row's edit modal (appended after the
        // tables) legitimately lists every machine on the board — search-filtering
        // it too would make re-assigning a filtered-out row's machine impossible.
        $tablesHtml = $this->tablesOnly($html);

        // PT92 runs BETA-2 — the assignment row for it must survive the filter.
        $this->assertStringContainsString('PT92', $tablesHtml);
        $this->assertStringContainsString('BETA-2', $tablesHtml);
        // ALPHA-1 (only on PT91) is filtered out of the Assignment table.
        $this->assertStringNotContainsString('PT91', $tablesHtml);
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

    public function test_assignment_rows_render_an_edit_modal_trigger_not_a_page_link(): void
    {
        $board = $this->seedBoard();
        $pattern = Pattern::where('pattern_board_id', $board->id)->first();

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('pattern-boards.index', ['board' => $board->id]))
            ->assertOk()
            ->getContent();

        // Opens a modal (Alpine dispatch) instead of navigating to a page.
        $this->assertStringContainsString("open-modal', 'pattern-edit-{$pattern->id}'", $html);
        $this->assertStringContainsString('_pattern_edit_id', $html);
        $this->assertStringContainsString(route('patterns.update', $pattern), $html);
    }

    public function test_the_standalone_edit_page_route_no_longer_exists(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('patterns.edit'));
    }

    public function test_updating_a_pattern_via_the_modal_form_persists_and_redirects_back_to_the_board(): void
    {
        $board = $this->seedBoard();
        $pattern = Pattern::where('pattern_board_id', $board->id)->first();
        $newMachine = Machine::create(['name' => 'PT93']);

        $this->actingAs($this->authorizedUser())
            ->put(route('patterns.update', $pattern), [
                '_pattern_edit_id' => $pattern->id,
                'shift' => $pattern->shift,
                'machine_id' => $newMachine->id,
                'part_id' => $pattern->part_id,
                'proses' => $pattern->proses,
            ])
            ->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        $this->assertSame($newMachine->id, $pattern->fresh()->machine_id);
    }

    public function test_an_invalid_update_reopens_only_that_rows_modal_with_errors(): void
    {
        $board = $this->seedBoard();
        [$patternA, $patternB] = Pattern::where('pattern_board_id', $board->id)->orderBy('id')->get()->all();

        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->from(route('pattern-boards.index', ['board' => $board->id]))
            ->put(route('patterns.update', $patternA), [
                '_pattern_edit_id' => $patternA->id,
                'shift' => $patternA->shift,
                'machine_id' => $patternA->machine_id,
                'part_id' => $patternA->part_id,
                'proses' => 999, // exceeds jumlah_proses (1) — fails validation
            ])
            ->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        // Same test-session — deliberately no second actingAs() call (which
        // would start a fresh session and lose the flashed errors/old input)
        // and no assertSessionHasErrors() beforehand (it reads the session in
        // a way that ages the very flash data this follow-up request needs).
        $followUp = $this->get(route('pattern-boards.index', ['board' => $board->id]))->getContent();

        // Only pattern A's own modal (its x-on:open-modal.window listener, not
        // the trigger button that also mentions its name) is flagged to
        // auto-open — style="display: block" right after it, not pattern B's.
        $patternAModalStart = strpos($followUp, "open-modal.window=\"\$event.detail == 'pattern-edit-{$patternA->id}'");
        $patternBModalStart = strpos($followUp, "open-modal.window=\"\$event.detail == 'pattern-edit-{$patternB->id}'");
        $this->assertStringContainsString('display: block', substr($followUp, $patternAModalStart, 800));
        $this->assertStringContainsString('display: none', substr($followUp, $patternBModalStart, 800));
    }

    public function test_group_item_rows_render_an_edit_modal_trigger_not_a_page_link(): void
    {
        $board = $this->seedBoard();
        $item = PatternGroupItem::where('pattern_board_id', $board->id)->first();

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('pattern-boards.index', ['board' => $board->id]))
            ->assertOk()
            ->getContent();

        // Opens a modal (Alpine dispatch) instead of navigating to a page.
        $this->assertStringContainsString("open-modal', 'group-item-edit-{$item->id}'", $html);
        $this->assertStringContainsString('_group_item_edit_id', $html);
        $this->assertStringContainsString(route('group-items.update', $item), $html);
    }

    public function test_the_standalone_group_item_edit_page_route_no_longer_exists(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('group-items.edit'));
    }

    public function test_updating_a_group_item_via_the_modal_form_persists_and_redirects_back_to_the_board(): void
    {
        $board = $this->seedBoard();
        $item = PatternGroupItem::where('pattern_board_id', $board->id)->first();

        $this->actingAs($this->authorizedUser())
            ->put(route('group-items.update', $item), [
                '_group_item_edit_id' => $item->id,
                'part_id' => $item->part_id,
                'shift' => $item->shift,
                'urutan' => $item->urutan,
                'lot' => 500,
                'loading_time' => $item->loading_time,
                'jumlah_proses' => $item->jumlah_proses,
                'dandori' => $item->dandori,
            ])
            ->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        $this->assertSame(500, $item->fresh()->lot);
    }

    public function test_an_invalid_group_item_update_reopens_only_that_rows_modal_with_errors(): void
    {
        $board = $this->seedBoard();
        [$itemA, $itemB] = PatternGroupItem::where('pattern_board_id', $board->id)->orderBy('id')->get()->all();

        $this->actingAs($this->authorizedUser())
            ->from(route('pattern-boards.index', ['board' => $board->id]))
            ->put(route('group-items.update', $itemA), [
                '_group_item_edit_id' => $itemA->id,
                'part_id' => $itemA->part_id,
                'shift' => $itemA->shift,
                'urutan' => $itemA->urutan,
                // 'lot' intentionally omitted — required, fails validation
                'loading_time' => $itemA->loading_time,
                'jumlah_proses' => $itemA->jumlah_proses,
                'dandori' => $itemA->dandori,
            ])
            ->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        // Same test-session — no second actingAs()/assertSessionHasErrors()
        // call, either of which would age away the flashed errors/old input
        // before this follow-up request needs them.
        $followUp = $this->get(route('pattern-boards.index', ['board' => $board->id]))->getContent();

        $itemAModalStart = strpos($followUp, "open-modal.window=\"\$event.detail == 'group-item-edit-{$itemA->id}'");
        $itemBModalStart = strpos($followUp, "open-modal.window=\"\$event.detail == 'group-item-edit-{$itemB->id}'");
        $this->assertStringContainsString('display: block', substr($followUp, $itemAModalStart, 800));
        $this->assertStringContainsString('display: none', substr($followUp, $itemBModalStart, 800));
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

    /**
     * Cuts $html down to just the two tables — every row's edit modal (both
     * Kelompok Pattern's and Assignment Mesin's, appended after the tables)
     * intentionally lists every part/machine/part on the board unfiltered by
     * search, so a raw substring check against the full page would false-
     * positive on rows the search filtered out of the visible tables.
     */
    private function tablesOnly(string $html): string
    {
        $markers = array_filter([
            strpos($html, '_group_item_edit_id'),
            strpos($html, '_pattern_edit_id'),
        ], fn ($pos) => $pos !== false);

        return substr($html, 0, min($markers));
    }
}
