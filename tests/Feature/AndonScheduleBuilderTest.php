<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Services\AndonScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndonScheduleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_windows_lists_the_actual_gaps_on_a_busy_machine(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1']);

        // 07:00 + 120min loading (no dandori) -> busy 07:00-09:00, free the
        // rest of shift 1 (09:00-16:00) and the whole of shift 2 (20:00-07:00+1).
        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 120, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 1]);

        $windows = app(AndonScheduleBuilder::class)->freeWindows($board);
        $labels = collect($windows)->pluck('label')->all();

        $this->assertContains('PT91 (09:00 - 16:00)', $labels);

        $shift1Window = collect($windows)->firstWhere('label', 'PT91 (09:00 - 16:00)');
        $this->assertSame(1, $shift1Window['shift']);
        $this->assertSame($machine->id, $shift1Window['machine_id']);

        // Shift 2 is fully open — wraps past midnight to 07:00 the next day.
        $shift2Window = collect($windows)->first(fn ($w) => $w['shift'] === 2);
        $this->assertNotNull($shift2Window);
        $this->assertStringStartsWith('PT91 (20:00', $shift2Window['label']);
    }

    public function test_free_windows_includes_a_machine_with_no_patterns_at_all(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $idle = Machine::create(['name' => 'PT99']);

        $windows = app(AndonScheduleBuilder::class)->freeWindows($board);

        $this->assertTrue(collect($windows)->contains(fn ($w) => $w['machine_id'] === $idle->id));
    }
}
