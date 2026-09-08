<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatternGroupItemTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_lot_is_required_to_create_a_group_item(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $part = Part::create(['part_no' => 'P1']);

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.group-items.store', $board), [
                'part_id' => $part->id,
                'shift' => 1,
                'urutan' => 1,
                'loading_time' => 10,
                'jumlah_proses' => 1,
                'total_kanban' => 5,
                'dandori' => 0,
                // 'lot' intentionally omitted
            ]);

        $response->assertSessionHasErrors('lot');
        $this->assertSame(0, PatternGroupItem::count());
    }

    public function test_group_item_can_be_created_and_updated_with_lot(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $part = Part::create(['part_no' => 'P1']);

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.group-items.store', $board), [
                'part_id' => $part->id,
                'shift' => 1,
                'urutan' => 1,
                'lot' => 100,
                'loading_time' => 10,
                'jumlah_proses' => 1,
                'total_kanban' => 5,
                'dandori' => 0,
            ]);

        $response->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        $groupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $part->id)->first();
        $this->assertNotNull($groupItem);
        $this->assertSame(100, $groupItem->lot);

        $updateResponse = $this->actingAs($this->authorizedUser())
            ->put(route('group-items.update', $groupItem), [
                'part_id' => $part->id,
                'shift' => 1,
                'urutan' => 1,
                'lot' => 250,
                'loading_time' => 10,
                'jumlah_proses' => 1,
                'total_kanban' => 5,
                'dandori' => 0,
            ]);

        $updateResponse->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));
        $this->assertSame(250, $groupItem->fresh()->lot);
    }

    public function test_total_kanban_is_derived_from_lot_and_the_parts_qty_kbn_rounded_up(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 25]);

        // 130 / 25 = 5.2 -> rounded up to 6. Any manually-submitted total_kanban
        // must be ignored — it's no longer a user-controlled field.
        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.group-items.store', $board), [
                'part_id' => $part->id,
                'shift' => 1,
                'urutan' => 1,
                'lot' => 130,
                'loading_time' => 10,
                'jumlah_proses' => 1,
                'total_kanban' => 999,
                'dandori' => 0,
            ]);

        $response->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        $groupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $part->id)->first();
        $this->assertSame(6, $groupItem->total_kanban);
    }

    public function test_group_items_can_be_reordered_by_drag_and_drop(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $items = collect(['P1', 'P2', 'P3'])->map(fn ($no, $i) => PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => Part::create(['part_no' => $no])->id,
            'shift' => 1,
            'urutan' => $i + 1,
            'lot' => 10,
            'loading_time' => 10,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]));

        $this->actingAs($this->authorizedUser())
            ->postJson(route('pattern-boards.group-items.reorder', $board), [
                'order' => [$items[2]->id, $items[0]->id, $items[1]->id],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, $items[2]->fresh()->urutan);
        $this->assertSame(2, $items[0]->fresh()->urutan);
        $this->assertSame(3, $items[1]->fresh()->urutan);
    }

    public function test_reorder_ignores_ids_from_other_boards_and_keeps_numbering_gapless(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $other = PatternBoard::create(['name' => 'B']);

        $a = PatternGroupItem::create(['pattern_board_id' => $board->id, 'part_id' => Part::create(['part_no' => 'P1'])->id, 'shift' => 1, 'urutan' => 1, 'lot' => 10, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0]);
        $b = PatternGroupItem::create(['pattern_board_id' => $board->id, 'part_id' => Part::create(['part_no' => 'P2'])->id, 'shift' => 1, 'urutan' => 2, 'lot' => 10, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0]);
        $foreign = PatternGroupItem::create(['pattern_board_id' => $other->id, 'part_id' => Part::create(['part_no' => 'P3'])->id, 'shift' => 1, 'urutan' => 1, 'lot' => 10, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0]);

        $this->actingAs($this->authorizedUser())
            ->postJson(route('pattern-boards.group-items.reorder', $board), [
                'order' => [$foreign->id, $b->id], // only $b is on this board; $a not listed
            ])
            ->assertOk();

        // $b goes first (it was the only valid listed id), $a keeps its relative
        // spot after it — renumbered 1..N with no gaps.
        $this->assertSame(1, $b->fresh()->urutan);
        $this->assertSame(2, $a->fresh()->urutan);
        $this->assertSame(1, $foreign->fresh()->urutan); // untouched
    }

    public function test_total_kanban_falls_back_to_0_when_the_part_has_no_qty_kbn(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $part = Part::create(['part_no' => 'P1']); // qty_kbn left null

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.group-items.store', $board), [
                'part_id' => $part->id,
                'shift' => 1,
                'urutan' => 1,
                'lot' => 130,
                'loading_time' => 10,
                'jumlah_proses' => 1,
                'dandori' => 0,
            ]);

        $response->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        $groupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $part->id)->first();
        $this->assertSame(0, $groupItem->total_kanban);
    }
}
