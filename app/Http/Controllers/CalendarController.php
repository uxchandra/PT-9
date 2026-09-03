<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\PatternBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request->query('month'));

        // The visible grid is padded out to whole weeks (Mon–Sun) so the first
        // and last rows are never half-empty.
        $gridStart = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $entries = CalendarEntry::query()
            ->with('patternBoard')
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get()
            ->keyBy(fn (CalendarEntry $entry) => $entry->date);

        $days = [];
        for ($cursor = $gridStart->copy(); $cursor->lte($gridEnd); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $entry = $entries->get($key);

            $days[] = [
                'date' => $key,
                'day' => $cursor->day,
                'in_month' => $cursor->month === $month->month,
                'is_today' => $cursor->isToday(),
                'is_weekend' => $cursor->isWeekend(),
                'entry' => $entry ? [
                    'id' => $entry->id,
                    'pattern_board_id' => $entry->pattern_board_id,
                    'board_name' => $entry->patternBoard?->name,
                ] : null,
            ];
        }

        return view('calendar.index', [
            'monthValue' => $month->format('Y-m'),
            'monthLabel' => $month->copy()->locale('id')->translatedFormat('F Y'),
            'prevMonth' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
            'days' => $days,
            'patternBoards' => PatternBoard::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'pattern_board_id' => ['required', 'exists:pattern_boards,id'],
        ], [], ['pattern_board_id' => 'pattern board']);

        $date = Carbon::parse($validated['date'])->toDateString();

        // One assignment per day: re-picking a board on a day that already has
        // one just overwrites it.
        CalendarEntry::updateOrCreate(
            ['date' => $date],
            ['pattern_board_id' => $validated['pattern_board_id']],
        );

        return redirect()
            ->route('calendar.index', ['month' => Carbon::parse($date)->format('Y-m')])
            ->with('status', 'Pattern untuk '.Carbon::parse($date)->locale('id')->translatedFormat('d F Y').' berhasil disimpan.');
    }

    public function destroy(Request $request, CalendarEntry $calendarEntry): RedirectResponse
    {
        $month = Carbon::parse($calendarEntry->date)->format('Y-m');
        $calendarEntry->delete();

        return redirect()
            ->route('calendar.index', ['month' => $request->query('month', $month)])
            ->with('status', 'Pattern pada tanggal tersebut berhasil dihapus.');
    }

    private function resolveMonth(?string $month): Carbon
    {
        if ($month) {
            try {
                return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            } catch (\Throwable) {
                // Fall through to the current month.
            }
        }

        return Carbon::now()->startOfMonth();
    }
}
