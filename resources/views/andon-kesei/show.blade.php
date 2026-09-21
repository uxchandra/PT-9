<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $boardTitle ?? "KESEI KANBAN LINE 9" }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: #000; }
        .andon-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
        .andon-scroll::-webkit-scrollbar-track { background: #1e293b; }
        .andon-scroll::-webkit-scrollbar-thumb { background: #475569; }
        .grid-line-dark { position: absolute; top: 0; bottom: 0; width: 1px; background: rgba(255,255,255,0.1); }
        {{-- Base color is overridden inline per row with that row's own pola
             colour (see KeseiBoard::polaColor) — this is just the fallback. --}}
        .closing-time-marker { width: 0; border-left: 6px dashed #94a3b8; }
        {{-- Heijunka-only: a thin, faint planning-release line (see
             KeseiBoard::planningMarkers) — deliberately subtler than the
             closing-time marker above so it reads as a background guide,
             not competing with the actual pull ticks drawn over it. --}}
        .planning-marker { width: 0; border-left: 1px dashed rgba(255,255,255,0.45); }
    </style>
</head>
<body class="h-screen w-screen overflow-hidden bg-black font-sans antialiased text-white">
    <div class="h-screen flex flex-col">

        {{-- 4 columns: logo | title | pattern | date-time. --}}
        <div class="shrink-0 flex items-stretch border-b-2 border-white">
            <div class="flex shrink-0 items-center justify-center border-r-2 border-white px-4" style="width: 170px;">
                <img src="{{ asset('images/logo_step.png') }}" alt="STEP" class="h-14 w-auto object-contain">
            </div>
            <div class="flex flex-1 items-center justify-center py-3">
                <h1 class="text-2xl sm:text-4xl font-extrabold uppercase tracking-wide text-white">{{ $boardTitle ?? "KESEI KANBAN LINE 9" }}</h1>
            </div>
            <div class="flex shrink-0 flex-col items-center justify-center gap-0.5 border-l-2 border-white px-6" style="width: 240px;">
                <span class="text-lg font-extrabold uppercase tracking-wide text-white">{{ __('Pattern') }}</span>
                <span class="text-2xl sm:text-4xl font-extrabold uppercase tracking-wide text-white truncate">{{ $currentPattern ?? '—' }}</span>
            </div>
            <div class="flex shrink-0 flex-col items-center justify-center border-l-2 border-white px-6 font-bold" style="width: 190px;">
                <div class="text-lg">{{ now()->format('d/m/Y') }}</div>
                <div id="andon-clock" class="text-lg tabular-nums" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        <div class="shrink-0 flex flex-wrap items-center gap-x-4 gap-y-1 border-b-2 border-white px-4 py-2 text-base text-slate-300">
            @if ($isHeijunka ?? false)
                {{-- Heijunka's own tick colours (see KeseiBoard::heijunkaVisualEvents)
                     — the green one, not red, is the actual "Kanban Pull" (still
                     queued, hasn't crossed the progress bar or is within its
                     15-minute grace period yet). All grouped on the right, same
                     diagonal-stripe boxed swatch style as the Closing Time legend
                     below (not a bare line). --}}
                <div class="flex items-center gap-6 ml-auto">
                    @foreach ([
                        '#22c55e' => __('SOS Pull'),
                        '#ff3b3b' => __('Not Pulled (>15 min)'),
                        '#3b82f6' => __('Pulled'),
                    ] as $color => $label)
                        <span class="flex items-center gap-1.5">
                            <span class="w-5 h-5 rounded-sm border border-white/30 shrink-0"
                                  style="background-image: repeating-linear-gradient(45deg, {{ $color }} 0, {{ $color }} 2px, transparent 2px, transparent 5px);"></span>
                            <span class="text-white">{{ $label }}</span>
                        </span>
                    @endforeach
                    <div class="flex items-center gap-1.5">
                        <span class="w-0 h-4 border-l-2" style="border-color:#22d3ee;"></span>
                        <span>{{ __('Progress Bar') }}</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-0 h-4 border-l" style="border-left-style: dashed; border-color: rgba(255,255,255,0.45);"></span>
                        <span>{{ __('Planning') }}</span>
                    </div>
                </div>
            @else
                <div class="flex items-center gap-6">
                    <span class="text-slate-400">{{ __('Closing Time') }}:</span>
                    @foreach ($polaLegend as $pola => $color)
                        <span class="flex items-center gap-1.5">
                            <span class="w-5 h-5 rounded-sm border border-white/30 shrink-0"
                                  style="background-image: repeating-linear-gradient(45deg, {{ $color }} 0, {{ $color }} 2px, transparent 2px, transparent 5px);"></span>
                            <span class="text-white">{{ $pola }}</span>
                        </span>
                    @endforeach
                </div>
                <div class="flex items-center gap-1.5 ml-auto">
                    <span class="flex items-center gap-px h-4">
                        <span class="block w-0.5 h-full rounded-sm" style="background-color: #ff3b3b;"></span>
                        <span class="block w-0.5 h-full rounded-sm" style="background-color: #ff3b3b;"></span>
                    </span>
                    <span>{{ __('Kanban Pull') }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-0 h-4 border-l-2" style="border-color:#22d3ee;"></span>
                    <span>{{ __('Progress Bar') }}</span>
                </div>
            @endif
        </div>

        <div class="flex-1 min-h-0">
            @include('andon-kesei._board-dark')
        </div>
    </div>

    <button id="kesei-scroll-pause-btn" type="button" aria-pressed="false"
            class="fixed bottom-4 right-4 z-50 flex items-center gap-2 rounded-full border border-white/30 bg-black/70 px-4 py-2 text-sm font-bold text-white shadow-lg hover:bg-black/90">
        <span id="kesei-scroll-pause-icon">&#10074;&#10074;</span>
        <span id="kesei-scroll-pause-label">{{ __('Pause') }}</span>
    </button>

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

        // Live refresh: fetch fresh panel HTML every $pollMs and swap in place
        // (the scan board polls much faster than the stock board — see
        // AndonKeseiController); a full reload only as a 30-min safety net.
        // Scroll position of each panel is preserved across swaps.
        (function () {
            // The heijunka board drops the closing/antrian sidebar entirely
            // (see AndonKeseiController::showHeijunka) — those elements
            // simply don't exist there, so every read/write against them is
            // guarded rather than assumed present.
            let lastTimeline = document.getElementById('kesei-panel-timeline').innerHTML;
            let lastAntrian = document.getElementById('kesei-panel-antrian')?.innerHTML ?? null;
            let lastClosing = document.getElementById('kesei-panel-closing')?.innerHTML ?? null;

            function replacePanel(id, html) {
                const panel = document.getElementById(id);
                if (!panel) return;
                const scrollEl = panel.querySelector('.andon-scroll');
                const prevLeft = scrollEl ? scrollEl.scrollLeft : null;
                const prevTop = scrollEl ? scrollEl.scrollTop : null;

                panel.innerHTML = html;

                const fresh = panel.querySelector('.andon-scroll');
                if (fresh && prevLeft !== null) fresh.scrollLeft = prevLeft;
                if (fresh && prevTop !== null) fresh.scrollTop = prevTop;
            }

            async function refresh() {
                try {
                    const res = await fetch(window.location.href, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store',
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data.timeline !== lastTimeline) {
                        replacePanel('kesei-panel-timeline', data.timeline);
                        lastTimeline = data.timeline;
                        if (window.__keseiNowSync) window.__keseiNowSync();
                        if (window.__keseiHeaderSync) window.__keseiHeaderSync();
                    }
                    if (lastClosing !== null && data.closingTable !== lastClosing) { replacePanel('kesei-panel-closing', data.closingTable); lastClosing = data.closingTable; }
                    if (lastAntrian !== null && data.antrianFixVolume !== lastAntrian) { replacePanel('kesei-panel-antrian', data.antrianFixVolume); lastAntrian = data.antrianFixVolume; }
                } catch (e) {
                    // Network hiccup — next tick retries.
                }
            }

            setInterval(refresh, {{ $pollMs ?? 60000 }});
            setTimeout(() => window.location.reload(), 30 * 60 * 1000);

            // Auto-scroll the antrian table to the newest (bottom) row on load.
            const antrianScroll = document.querySelector('#kesei-panel-antrian .andon-scroll');
            if (antrianScroll) antrianScroll.scrollTop = antrianScroll.scrollHeight;
        })();

        // Slow, looping auto-scroll of the part timeline (top -> bottom) so a
        // row list taller than the screen is fully visible over time on this
        // unattended wall board — jumps straight back to the top the instant
        // it hits bottom (no pause there), then holds still at the top for
        // TOP_PAUSE_MS so the first row is actually readable before it starts
        // moving again. The pause is a plain timestamp checked fresh every
        // tick — not a setTimeout — so (unlike an earlier version of this)
        // it can't outlive a panel refresh: replacePanel() swaps in a fresh
        // .andon-scroll element via innerHTML, and a live re-query every tick
        // means there's no reference to a scroller that could go stale.
        (function () {
            const STEP_PX = 0.6;
            const TICK_MS = 40;
            const TOP_PAUSE_MS = 3000;
            let resumeAt = Date.now() + TOP_PAUSE_MS;

            // Manual pause/resume — an operator can freeze the auto-scroll to
            // actually read a row instead of chasing it, then resume where it
            // left off (no jump back to top on resume).
            let paused = false;
            const btn = document.getElementById('kesei-scroll-pause-btn');
            const icon = document.getElementById('kesei-scroll-pause-icon');
            const label = document.getElementById('kesei-scroll-pause-label');
            if (btn) {
                btn.addEventListener('click', function () {
                    paused = !paused;
                    btn.setAttribute('aria-pressed', paused ? 'true' : 'false');
                    if (icon) icon.innerHTML = paused ? '&#9654;' : '&#10074;&#10074;';
                    if (label) label.textContent = paused ? @json(__('Play')) : @json(__('Pause'));
                });
            }

            setInterval(function () {
                const scroller = document.querySelector('#kesei-panel-timeline .andon-scroll');
                if (!scroller) return;

                const max = scroller.scrollHeight - scroller.clientHeight;
                if (max <= 0) return;

                if (paused) return;
                if (Date.now() < resumeAt) return;

                const next = scroller.scrollTop + STEP_PX;
                if (next >= max) {
                    scroller.scrollTop = 0;
                    resumeAt = Date.now() + TOP_PAUSE_MS;
                } else {
                    scroller.scrollTop = next;
                }
            }, TICK_MS);
        })();

        // Keeps the time-axis header's AND the Total footer's horizontal
        // scroll position mirroring the rows between them — both are
        // structurally separate scrollers now (see andon-kesei/
        // _timeline-dark.blade.php) so neither can disappear on a vertical
        // scroll the way a "position: sticky" row could in some browsers,
        // but that means their horizontal position has to be driven
        // explicitly instead of coming along for free. A panel refresh
        // replaces all three elements via innerHTML, so the scroll listener
        // is (re)bound every time this runs, same reasoning as
        // __keseiNowSync below.
        (function () {
            let boundRows = null;
            let onScroll = null;

            function sync() {
                const header = document.getElementById('kesei-timeline-header-scroll');
                const footer = document.getElementById('kesei-timeline-footer-scroll');
                const rows = document.getElementById('kesei-timeline-rows-scroll');
                if (!rows || (!header && !footer)) return;

                if (rows !== boundRows) {
                    if (boundRows && onScroll) boundRows.removeEventListener('scroll', onScroll);
                    onScroll = function () {
                        if (header) header.scrollLeft = rows.scrollLeft;
                        if (footer) footer.scrollLeft = rows.scrollLeft;
                    };
                    rows.addEventListener('scroll', onScroll);
                    boundRows = rows;
                }

                if (header) header.scrollLeft = rows.scrollLeft;
                if (footer) footer.scrollLeft = rows.scrollLeft;
            }

            window.__keseiHeaderSync = sync;
            sync();
        })();

        // Vertical "now" line on the Kesei timeline — nudged forward every
        // second so it tracks the real clock between panel refreshes.
        (function () {
            let base = null;
            let baseAt = 0;

            function currentNow() {
                return base.now + (Date.now() - baseAt) / 60000;
            }

            // Offset in px from the axis origin, wrapped onto the 07:00 → 07:00 face.
            function offsetPx() {
                const total = base.total || (24 * 60);
                let m = (currentNow() - base.dayStart) % total;
                if (m < 0) m += total;
                return m * base.px;
            }

            function sync(el) {
                base = {
                    now: parseFloat(el.dataset.now),
                    dayStart: parseFloat(el.dataset.dayStart),
                    px: parseFloat(el.dataset.px),
                    total: parseFloat(el.dataset.total),
                    sidebar: parseFloat(el.dataset.sidebar),
                };
                baseAt = Date.now();
                place(el);

                const scroller = document.querySelector('#kesei-panel-timeline .andon-scroll');
                if (scroller) scroller.scrollLeft = Math.max(0, offsetPx() - 320);
            }

            function place(el) {
                el.style.left = (base.sidebar + offsetPx()) + 'px';
            }

            window.__keseiNowSync = function () {
                const el = document.getElementById('kesei-now-line');
                if (el) sync(el);
            };

            window.__keseiNowSync();

            setInterval(function () {
                const el = document.getElementById('kesei-now-line');
                if (!el) { base = null; return; }
                // A fresh panel render bumps data-now — re-sync to the server value.
                if (!base || parseFloat(el.dataset.now) !== base.now) { sync(el); return; }
                place(el);
            }, 1000);
        })();
    </script>
</body>
</html>
