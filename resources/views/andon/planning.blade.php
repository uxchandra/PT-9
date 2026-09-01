<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Andon Planning {{ $patternBoard->name }} — {{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: #f1f5f9; }

        .andon-scroll::-webkit-scrollbar { height: 10px; }
        .andon-scroll::-webkit-scrollbar-track { background: #e2e8f0; }
        .andon-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        .block-loading {
            background-color: #f59e0b;
            border: 1px solid #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.25);
        }
        .block-dandori {
            background-color: #1e293b;
            border: 1px solid #ffffff;
        }
        .rest-band {
            background-image: repeating-linear-gradient(135deg, #93c5fd 0 8px, #bfdbfe 8px 16px);
            border-left: 1px solid #60a5fa;
            border-right: 1px solid #60a5fa;
        }
        .grid-line {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1px;
            background: #e2e8f0;
        }
    </style>
</head>
<body class="h-screen overflow-hidden font-sans antialiased text-slate-800">
    <div class="h-screen flex flex-col px-4 sm:px-6 py-5">

        <!-- Header -->
        <div class="shrink-0 grid grid-cols-[1fr_auto_1fr] items-center gap-4 mb-5">
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($patternBoards as $board)
                    <a href="{{ route('andon.planning', $board) }}"
                       class="px-5 py-2 rounded-lg text-sm font-bold border transition shadow-sm
                              {{ $board->id === $patternBoard->id
                                    ? 'bg-brand-800 text-white border-brand-800'
                                    : 'bg-white text-slate-600 border-slate-200 hover:border-brand-400 hover:text-brand-800' }}">
                        {{ $board->name }}
                    </a>
                @endforeach
            </div>

            <h1 class="text-center text-xl sm:text-2xl font-extrabold tracking-wide text-brand-900 whitespace-nowrap">ANDON PLANNING PT 9</h1>

            <div class="flex flex-wrap items-center justify-end gap-4 text-sm">
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm border border-white bg-slate-900"></span><span class="text-slate-500">Dandori</span></div>
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm border border-blue-400" style="background-image:repeating-linear-gradient(135deg,#93c5fd 0 3px,#bfdbfe 3px 6px)"></span><span class="text-slate-500">Rest</span></div>
                <a href="{{ route('andon.show', $patternBoard) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-slate-600 hover:border-brand-400 hover:text-brand-800 transition font-semibold">
                    {{ __('Lihat Pattern') }}
                </a>
                <div id="andon-clock" class="font-mono text-brand-800 text-base font-semibold tabular-nums ml-2" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        @if (empty($rows))
            <div class="flex-1 min-h-0 flex items-center justify-center rounded-xl border border-slate-200 bg-white text-center text-slate-400 shadow-sm">
                {{ __('Belum ada pattern yang di-assign ke mesin untuk board ini.') }}
            </div>
        @else
            <div class="flex-1 min-h-0 flex flex-col gap-4"
                 id="andon-panels"
                 data-day-start="{{ $dayStart }}"
                 data-px-per-minute="{{ $pxPerMinute }}"
                 data-now-minute="{{ $nowMinute }}">

                <!-- Card: Planning -->
                <div class="flex flex-col min-h-0 rounded-xl border border-slate-300 bg-white shadow-sm overflow-hidden flex-1">
                    <div class="shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-300 bg-slate-50">
                        <span class="font-bold text-slate-700 text-sm tracking-wide">{{ __('PLANNING') }}</span>
                        <p class="text-xs text-slate-400">{{ __('Kanban = demand Kesei pada closing time (H-4 jam dari mulai produksi)') }}</p>
                    </div>
                    <div id="andon-panel-timeline" class="flex-1 min-h-0">
                        @include('andon._timeline', ['isPlanning' => true])
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        // "Actual kanban produced" and "kanban override" inputs — each saves
        // on its own change event (delegated to `document` so it keeps
        // working after the panel's innerHTML is replaced by the refresh
        // cycle below). Only the field that actually changed is sent, so
        // editing one never clobbers the other.
        document.addEventListener('change', function (e) {
            const input = e.target;
            if (!input.classList) return;

            let field = null;
            if (input.classList.contains('andon-actual-input')) field = 'actual_kanban';
            if (input.classList.contains('andon-kanban-override-input')) field = 'kanban_override';
            if (!field) return;

            const patternId = input.dataset.patternId;

            fetch(`/andon-planning/pattern/${patternId}/actual`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ [field]: input.value === '' ? null : input.value }),
            }).catch(() => {
                // Network hiccup — the value stays in the field; changing it
                // again (even back to the same number) will retry the save.
            });
        });

        (function () {
            const el = document.getElementById('andon-clock');
            if (!el) return;
            let t = el.dataset.serverTime.split(':').map(Number);
            let seconds = t[0] * 3600 + t[1] * 60 + t[2];
            const render = () => {
                const h = String(Math.floor(seconds / 3600) % 24).padStart(2, '0');
                const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
                const s = String(seconds % 60).padStart(2, '0');
                el.textContent = `${h}:${m}:${s}`;
            };
            render();
            setInterval(() => { seconds++; render(); }, 1000);
        })();

        // Same live-refresh approach as the Pattern board: fetch fresh HTML in
        // the background every 60s and swap it in place, so the tab never
        // shows a reload/spinner. A full reload only happens rarely (every 30
        // min) as a safety net against long-running tab drift.
        (function () {
            const panels = document.getElementById('andon-panels');
            if (!panels) return;

            let lastTimelineHtml = document.getElementById('andon-panel-timeline').innerHTML;

            // No auto-scroll here anymore — the view stays wherever the user
            // left it. Swapping innerHTML always resets scroll to 0 though,
            // so it's captured before and restored right after every refresh.
            function replacePanel(html) {
                const panel = document.getElementById('andon-panel-timeline');

                // Never yank an "actual kanban" or "kanban override" input out
                // from under someone mid-edit — skip this cycle's swap and
                // retry on the next one.
                const active = document.activeElement;
                if (panel.contains(active) && active.classList
                    && (active.classList.contains('andon-actual-input') || active.classList.contains('andon-kanban-override-input'))) {
                    return false;
                }

                const scrollEl = document.querySelector('#andon-panel-timeline .andon-scroll');
                const prevLeft = scrollEl ? scrollEl.scrollLeft : null;

                panel.innerHTML = html;

                const freshScrollEl = document.querySelector('#andon-panel-timeline .andon-scroll');
                if (freshScrollEl && prevLeft !== null) freshScrollEl.scrollLeft = prevLeft;

                return true;
            }

            async function refresh() {
                try {
                    const res = await fetch(window.location.href, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) return;
                    const data = await res.json();

                    if (data.timeline !== lastTimelineHtml && replacePanel(data.timeline)) {
                        lastTimelineHtml = data.timeline;
                    }
                } catch (e) {
                    // Network hiccup — the next scheduled tick will retry.
                }
            }

            setInterval(refresh, 60000);
            setTimeout(() => window.location.reload(), 30 * 60 * 1000);
        })();
    </script>
</body>
</html>
