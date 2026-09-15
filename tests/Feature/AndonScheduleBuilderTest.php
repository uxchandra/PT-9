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

    public function test_a_job_crossing_the_shift_change_gap_keeps_running_through_it_as_overtime(): void
    {
        // Nothing pauses a running job anymore — not a regular rest, and not
        // the shift-change gap either. A job already underway when 16:00
        // hits just keeps going straight through as overtime (lembur)
        // instead of splitting into two pieces on either side of it.
        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'OVERTIME-1']);

        // 07:00 (420) + 600min dandori-free loading: would've split into
        // 420-960 + 1200-1260 under the old gap-jumping behaviour. Now it's
        // one unbroken 420-1020 block straight through 16:00-20:00.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 600, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 1]);

        [$rows] = app(AndonScheduleBuilder::class)->build($board);
        $blocks = collect($rows)->firstWhere(fn ($row) => $row['machine']->id === $machine->id)['blocks'];
        $loadingBlocks = collect($blocks)->where('type', 'loading')->where('part_id', $part->id);

        $this->assertCount(1, $loadingBlocks);
        $this->assertSame(420, $loadingBlocks->first()['start']);
        $this->assertSame(1020, $loadingBlocks->first()['end']);
    }

    public function test_a_shift_1_job_running_late_into_shift_2_pushes_back_shift_2s_own_start(): void
    {
        // Shift 1 and shift 2 are scheduled independently on the same
        // machine — but a shift 1 job running long enough to reach into
        // shift 2's own 20:00 start (nothing pauses it, so it just keeps
        // going as overtime) is still occupying the machine past that
        // point. Without accounting for that, shift 2's own blocks would
        // start at a fixed 20:00 and land right on top of it.
        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $shift1Part = Part::create(['part_no' => 'SHIFT1-OVERTIME']);
        $shift2Part = Part::create(['part_no' => 'SHIFT2-NEXT']);

        // 07:00 (420) + 900min, no dandori — runs on to 1320 (22:00), 120min
        // past shift 2's usual 20:00 start.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $shift1Part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 900, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $shift1Part->id, 'shift' => 1, 'proses' => 1]);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $shift2Part->id, 'shift' => 2,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 50, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $shift2Part->id, 'shift' => 2, 'proses' => 1]);

        [$rows] = app(AndonScheduleBuilder::class)->build($board);
        $blocks = collect($rows)->firstWhere(fn ($row) => $row['machine']->id === $machine->id)['blocks'];

        $shift1Block = collect($blocks)->first(fn ($b) => ($b['part_id'] ?? null) === $shift1Part->id);
        $shift2Block = collect($blocks)->first(fn ($b) => ($b['part_id'] ?? null) === $shift2Part->id);

        $this->assertNotNull($shift1Block);
        $this->assertSame(420, $shift1Block['start']);
        $this->assertSame(1320, $shift1Block['end']);

        $this->assertNotNull($shift2Block);
        // Shift 2 waits for the overtime to actually clear (1320), not the
        // usual fixed 20:00 (1200) — no overlap between the two parts.
        $this->assertSame(1320, $shift2Block['start']);
    }
}
