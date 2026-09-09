<?php

namespace Tests\Feature;

use App\Models\CalendarEntry;
use App\Models\KeseiClosingNotification;
use App\Models\KeseiPart;
use App\Models\Part;
use App\Models\PatternBoard;
use App\Models\StockSnapshot;
use App\Services\KeseiClosingNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeseiClosingNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fonnte.token' => 'test-token',
            'services.fonnte.url' => 'https://api.fonnte.com/send',
            'services.kesei_closing.recipients' => '6289676366158, 62811111111',
        ]);
        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    private function fakeGatewayOk(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['x']])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function notifier(): KeseiClosingNotifier
    {
        return app(KeseiClosingNotifier::class);
    }

    private function keseiPart(string $partNo, ?string $closing, ?string $qtyKbn = null, ?string $mode = null): KeseiPart
    {
        return KeseiPart::create(array_filter([
            'part_id' => Part::create(['part_no' => $partNo, 'qty_kbn' => $qtyKbn])->id,
            'closing_time' => $closing,
            'closing_mode' => $mode,
            'urutan' => KeseiPart::max('urutan') + 1,
        ], fn ($v) => $v !== null));
    }

    public function test_sends_a_whatsapp_when_a_closing_time_has_passed(): void
    {
        $this->fakeGatewayOk();
        $board = PatternBoard::create(['name' => 'A']);
        // pre_run closing 03:00 → the plan for the 09-15 07:00 run, fixed 09-15 03:00.
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $board->id]);

        $this->keseiPart('57453-BZ140', '03:00', qtyKbn: '1');

        $result = $this->notifier()->run();

        $this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $result);

        Http::assertSentCount(2); // one per recipient
        Http::assertSent(function ($request) {
            $msg = $request->data()['message'];

            return $request->data()['target'] === '6289676366158'
                && str_contains($msg, 'Closing 03:00')
                && str_contains($msg, 'Part    : 57453-BZ140')
                && str_contains($msg, 'Pattern : A')
                && str_contains($msg, 'Qty Kbn : 0');
        });

        $this->assertDatabaseHas('kesei_closing_notifications', [
            'notified_on' => '2026-09-15',
        ]);
    }

    public function test_does_not_send_twice_for_the_same_part_on_the_same_day(): void
    {
        $this->fakeGatewayOk();
        $this->keseiPart('P1', '03:00');

        $this->notifier()->run();
        $second = $this->notifier()->run();

        $this->assertSame(0, $second['sent']);
        $this->assertSame(1, $second['skipped']);
        Http::assertSentCount(2); // still just the first run's 2 recipients
        $this->assertSame(1, KeseiClosingNotification::count());
    }

    public function test_parts_sharing_a_closing_time_go_out_in_one_message(): void
    {
        $this->fakeGatewayOk();
        $this->keseiPart('P1', '03:00');
        $this->keseiPart('P2', '03:00');
        $this->keseiPart('P3', '04:00');

        $result = $this->notifier()->run();

        $this->assertSame(3, $result['sent']);
        // 2 recipients x 2 clock times (03:00 grouped, 04:00 on its own) = 4.
        Http::assertSentCount(4);
        Http::assertSent(function ($request) {
            $msg = $request->data()['message'];

            return str_contains($msg, 'Closing 03:00')
                && str_contains($msg, 'Part    : P1')
                && str_contains($msg, 'Part    : P2')
                && ! str_contains($msg, 'P3');
        });
        $this->assertSame(3, KeseiClosingNotification::count());
    }

    public function test_pattern_is_the_run_being_planned_not_the_one_currently_running(): void
    {
        // Tuesday 04:30 — pattern MON still running, but the 03:00–07:00 window
        // plans the 07:00 run, which is pattern TUE.
        Carbon::setTestNow('2026-09-15 04:30:00');
        $this->fakeGatewayOk();

        $monday = PatternBoard::create(['name' => 'MON']);
        $tuesday = PatternBoard::create(['name' => 'TUE']);
        CalendarEntry::create(['date' => '2026-09-14', 'pattern_board_id' => $monday->id]);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $tuesday->id]);

        $this->keseiPart('P1', '04:00');

        $this->notifier()->run();

        Http::assertSent(fn ($request) => str_contains($request->data()['message'], 'Pattern : TUE'));
    }

    public function test_does_not_send_before_the_closing_time(): void
    {
        Http::fake();
        // Only run-day is tomorrow; its 03:00 pre-run cutoff is still in the future.
        $board = PatternBoard::create(['name' => 'B']);
        CalendarEntry::create(['date' => '2026-09-16', 'pattern_board_id' => $board->id]);

        $kesei = $this->keseiPart('P1', '03:00');
        $kesei->patternBoards()->sync([$board->id]);

        $result = $this->notifier()->run();

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $result);
        Http::assertNothingSent();
    }

    public function test_pattern_is_a_dash_when_the_calendar_has_no_entry_for_the_planned_run(): void
    {
        $this->fakeGatewayOk();
        $this->keseiPart('P1', '03:00');

        $this->notifier()->run();

        Http::assertSent(fn ($request) => str_contains($request->data()['message'], 'Pattern : -'));
    }

    public function test_qty_kbn_is_the_accumulated_kanban_up_to_the_closing(): void
    {
        $this->fakeGatewayOk();
        $this->keseiPart('P1', '03:00', qtyKbn: '1'); // 1 pc = 1 kanban

        // A 20 -> 16 drop before the 09-15 03:00 cutoff = 4 kanban.
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 20, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-14 12:00')]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 16, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 02:30')]);
        // A drop AFTER the cutoff must not be counted.
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 9, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 09:30')]);

        $this->notifier()->run();

        Http::assertSent(fn ($request) => str_contains($request->data()['message'], 'Qty Kbn : 4'));
    }

    public function test_qty_kbn_matches_the_board_and_can_span_several_days_for_an_infrequent_part(): void
    {
        // now 10:00 Tuesday; part runs on board D, which ran 09-11 then again 09-15.
        // end_of_day closing 08:00 → the 09-15 cycle folds 09-15 08:00, covering
        // back to 09-11 08:00.
        $this->fakeGatewayOk();
        $d = PatternBoard::create(['name' => 'D']);
        $x = PatternBoard::create(['name' => 'X']);
        CalendarEntry::create(['date' => '2026-09-11', 'pattern_board_id' => $d->id]);
        CalendarEntry::create(['date' => '2026-09-12', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-13', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-14', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $d->id]);

        $kesei = $this->keseiPart('EVERY4', '08:00', qtyKbn: '1', mode: 'end_of_day');
        $kesei->patternBoards()->sync([$d->id]);

        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-11 09:00')]);
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-12 09:00')]);   // -4, folds
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 90, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-14 09:00')]);   // -6, folds
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 88, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 09:00')]);   // -2, after 08:00

        $result = $this->notifier()->run();

        $this->assertSame(1, $result['sent']);
        Http::assertSent(function ($request) {
            $msg = $request->data()['message'];

            return str_contains($msg, 'Part    : EVERY4')
                && str_contains($msg, 'Pattern : D')
                && str_contains($msg, 'Qty Kbn : 10'); // 4 + 6 across the 4-day cycle
        });
    }

    public function test_nothing_is_sent_on_a_day_the_part_does_not_run(): void
    {
        Http::fake();
        $d = PatternBoard::create(['name' => 'D']);
        $x = PatternBoard::create(['name' => 'X']);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $x->id]); // today runs X, not D

        $kesei = $this->keseiPart('OFFDAY', '03:00', qtyKbn: '1');
        $kesei->patternBoards()->sync([$d->id]);

        $result = $this->notifier()->run();

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $result);
        Http::assertNothingSent();
        $this->assertSame(0, KeseiClosingNotification::count());
    }

    public function test_a_gateway_failure_is_not_recorded_so_the_next_tick_retries(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => false, 'reason' => 'nope'])]);
        $this->keseiPart('P1', '03:00');

        $result = $this->notifier()->run();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, KeseiClosingNotification::count());
    }

    public function test_no_recipients_configured_sends_nothing(): void
    {
        config(['services.kesei_closing.recipients' => '']);
        Http::fake();
        $this->keseiPart('P1', '03:00');

        $result = $this->notifier()->run();

        $this->assertSame(0, $result['sent']);
        Http::assertNothingSent();
        $this->assertSame(0, KeseiClosingNotification::count());
    }

    public function test_capture_snapshot_command_triggers_the_notifier(): void
    {
        $this->keseiPart('P1', '03:00');
        // Fake the SOS feed so the command's own work succeeds too.
        Http::fake([
            'api.fonnte.com/*' => Http::response(['status' => true]),
            'sos.step.co.id/*' => Http::response(['data' => []]),
        ]);

        $this->artisan('stock:capture-snapshot')->assertSuccessful();

        $this->assertSame(1, KeseiClosingNotification::count());
    }
}
