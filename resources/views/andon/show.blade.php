<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Andon {{ $patternBoard->name }} — {{ config('app.name', 'Laravel') }}</title>
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
                    <a href="{{ route('andon.show', $board) }}"
                       class="px-5 py-2 rounded-lg text-sm font-bold border transition shadow-sm
                              {{ $board->id === $patternBoard->id
                                    ? 'bg-brand-800 text-white border-brand-800'
                                    : 'bg-white text-slate-600 border-slate-200 hover:border-brand-400 hover:text-brand-800' }}">
                        {{ $board->name }}
                    </a>
                @endforeach
            </div>

            <h1 class="text-center text-xl sm:text-2xl font-extrabold tracking-wide text-brand-900 whitespace-nowrap">ANDON MONITORING PT 9</h1>

            <div class="flex flex-wrap items-center justify-end gap-4 text-sm">
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm border border-white bg-slate-900"></span><span class="text-slate-500">Dandori</span></div>
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm border border-blue-400" style="background-image:repeating-linear-gradient(135deg,#93c5fd 0 3px,#bfdbfe 3px 6px)"></span><span class="text-slate-500">Rest</span></div>
                <div class="flex items-center gap-1.5"><span class="flex items-center gap-px h-3"><span class="block w-0.5 h-full bg-red-500 rounded-sm"></span><span class="block w-0.5 h-full bg-red-500 rounded-sm"></span></span><span class="text-slate-500">Stok Turun (kanban)</span></div>
                <div id="andon-clock" class="font-mono text-brand-800 text-base font-semibold tabular-nums ml-2" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        @if (empty($rows))
            <div class="flex-1 min-h-0 flex items-center justify-center rounded-xl border border-slate-200 bg-white text-center text-slate-400 shadow-sm">
                {{ __('Belum ada pattern yang di-assign ke mesin untuk board ini.') }}
            </div>
        @else
            <div class="flex-1 min-h-0 flex flex-col gap-4" x-data="{ expanded: null }"
                 id="andon-panels"
                 data-day-start="{{ $dayStart }}"
                 data-px-per-minute="{{ $pxPerMinute }}"
                 data-now-minute="{{ $nowMinute }}">

                <!-- Card: Andon -->
                <div class="flex flex-col min-h-0 rounded-xl border border-slate-300 bg-white shadow-sm overflow-hidden flex-1"
                     x-show="expanded === null || expanded === 'andon'">
                    <div class="shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-300 bg-slate-50">
                        <span class="font-bold text-slate-700 text-sm tracking-wide">{{ __('PATTERN') }}</span>
                        <button type="button" @click="expanded = expanded === 'andon' ? null : 'andon'"
                                class="w-6 h-6 flex items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-100 transition">
                            <svg x-show="expanded !== 'andon'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <svg x-show="expanded === 'andon'" x-cloak class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/>
                            </svg>
                        </button>
                    </div>
                    <div id="andon-panel-timeline" class="flex-1 min-h-0">
                        @include('andon._timeline')
                    </div>
                </div>

                <!-- Card: Kosei -->
                <div class="flex flex-col min-h-0 rounded-xl border border-slate-300 bg-white shadow-sm overflow-hidden flex-1"
                     x-show="expanded === null || expanded === 'kosei'">
                    <div class="shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-300 bg-slate-50">
                        <span class="font-bold text-slate-700 text-sm tracking-wide">{{ __('KESEI') }}</span>
                        <button type="button" @click="expanded = expanded === 'kosei' ? null : 'kosei'"
                                class="w-6 h-6 flex items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-100 transition">
                            <svg x-show="expanded !== 'kosei'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <svg x-show="expanded === 'kosei'" x-cloak class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/>
                            </svg>
                        </button>
                    </div>
                    <div class="flex-1 min-h-0 flex gap-3 p-3">
                        <div id="andon-panel-kosei" class="h-full min-w-0" style="flex: 3 1 0%;">
                            @include('andon._kosei-timeline')
                        </div>
                        <div id="andon-panel-stock" class="h-full min-w-0 rounded-lg border border-slate-300 overflow-hidden" style="flex: 1 1 0%;">
                            @include('andon._stock-timeline')
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
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

        // Keeps the board live without ever navigating: every 60s it fetches
        // fresh HTML for the 3 panels in the background and swaps them in
        // place, so the browser tab never shows a reload/spinner. A full
        // reload only happens rarely (every 30 min) as a safety net against
        // long-running tab drift — the display normally never visibly reloads.
        (function () {
            const panels = document.getElementById('andon-panels');
            if (!panels) return;

            const dayStart = parseFloat(panels.dataset.dayStart);
            const pxPerMinute = parseFloat(panels.dataset.pxPerMinute);
            let nowMinute = parseFloat(panels.dataset.nowMinute);
            let lastTimelineHtml = document.getElementById('andon-panel-timeline').innerHTML;
            let lastKoseiHtml = document.getElementById('andon-panel-kosei').innerHTML;
            let lastStockHtml = document.getElementById('andon-panel-stock').innerHTML;

            // Auto-follow pauses for a while after a manual scroll/touch/drag,
            // otherwise the per-second nudge below would fight the user and
            // snap straight back — it resumes on its own once they stop.
            const FOLLOW_PAUSE_MS = 15000;
            let userInteractedAt = 0;
            const isFollowPaused = () => Date.now() - userInteractedAt < FOLLOW_PAUSE_MS;

            ['wheel', 'touchstart', 'pointerdown'].forEach((eventName) => {
                panels.addEventListener(eventName, () => { userInteractedAt = Date.now(); }, { passive: true });
            });

            // Always instant, never animated: an animated scroll right after
            // swapping innerHTML is what caused the visible "blink" — the
            // fresh element's scroll position resets to 0, and animating back
            // to target every 60s looked like the whole board twitching.
            function scrollToNow() {
                if (isFollowPaused()) return;

                const targets = [
                    document.querySelector('#andon-panel-timeline .andon-scroll'),
                    document.querySelector('#andon-panel-kosei .andon-scroll'),
                ];
                const left = Math.max(0, (nowMinute - dayStart) * pxPerMinute - 500);

                targets.forEach((elx) => {
                    if (elx) elx.scrollLeft = left;
                });

                // Timeline Stok scrolls vertically (rows = time) — the latest
                // capture is always the newest row, so just jump to the bottom.
                const stockScroll = document.querySelector('#andon-panel-stock .andon-scroll');
                if (stockScroll) stockScroll.scrollTop = stockScroll.scrollHeight;
            }

            // Swapping innerHTML always resets that element's own scroll to 0,
            // regardless of auto-follow being paused or not — so whenever the
            // user is mid-manual-scroll, capture where they were first and
            // restore it right after, instead of letting the refresh yank
            // them back to 0 (or to "now") out of nowhere.
            function replacePanel(id, html) {
                const scrollEl = document.querySelector(`#${id} .andon-scroll`);
                const paused = isFollowPaused();
                const prevLeft = paused && scrollEl ? scrollEl.scrollLeft : null;
                const prevTop = paused && scrollEl ? scrollEl.scrollTop : null;

                document.getElementById(id).innerHTML = html;

                if (paused) {
                    const freshScrollEl = document.querySelector(`#${id} .andon-scroll`);
                    if (freshScrollEl && prevLeft !== null) freshScrollEl.scrollLeft = prevLeft;
                    if (freshScrollEl && prevTop !== null) freshScrollEl.scrollTop = prevTop;
                }
            }

            async function refresh() {
                try {
                    const res = await fetch(window.location.href, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) return;
                    const data = await res.json();

                    // Only touch the DOM for panels whose content actually
                    // changed — most 60s ticks have nothing new (patterns
                    // rarely change, stock only every 5min), so this avoids
                    // needless repaint/flicker on every single tick.
                    if (data.timeline !== lastTimelineHtml) {
                        replacePanel('andon-panel-timeline', data.timeline);
                        lastTimelineHtml = data.timeline;
                    }
                    if (data.kosei !== lastKoseiHtml) {
                        replacePanel('andon-panel-kosei', data.kosei);
                        lastKoseiHtml = data.kosei;
                    }
                    if (data.stockTimeline !== lastStockHtml) {
                        replacePanel('andon-panel-stock', data.stockTimeline);
                        lastStockHtml = data.stockTimeline;
                    }

                    nowMinute = data.nowMinute;
                    scrollToNow();
                } catch (e) {
                    // Network hiccup — the next scheduled tick will retry.
                }
            }

            scrollToNow();
            setInterval(refresh, 60000);
            setTimeout(() => window.location.reload(), 30 * 60 * 1000);

            // Nudges the scroll position forward every second between data
            // refreshes, so "now" keeps creeping right in real time instead of
            // only jumping once a minute.
            setInterval(() => {
                nowMinute += 1 / 60;
                scrollToNow();
            }, 1000);
        })();
    </script>
</body>
</html>
