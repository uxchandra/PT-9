<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $boardTitle ?? 'HEIKINKA LINE 9' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: #000; }
        .andon-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
        .andon-scroll::-webkit-scrollbar-track { background: #1e293b; }
        .andon-scroll::-webkit-scrollbar-thumb { background: #475569; }
    </style>
</head>
<body class="h-screen w-screen overflow-hidden bg-black font-sans antialiased text-white">
    <div class="h-screen flex flex-col">

        <div class="shrink-0 flex items-stretch border-b-2 border-white">
            <div class="flex shrink-0 items-center justify-center border-r-2 border-white px-4" style="width: 170px;">
                <img src="{{ asset('images/logo_step.png') }}" alt="STEP" class="h-14 w-auto object-contain">
            </div>
            <div class="flex flex-1 items-center justify-center py-3">
                <h1 class="text-2xl sm:text-4xl font-extrabold uppercase tracking-wide text-white">{{ $boardTitle ?? 'HEIKINKA LINE 9' }}</h1>
            </div>
            <div class="flex shrink-0 flex-col items-center justify-center border-l-2 border-white px-6 font-bold" style="width: 190px;">
                <div class="text-lg">{{ now()->format('d/m/Y') }}</div>
                <div id="hbox-clock" class="text-lg tabular-nums" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        <div class="shrink-0 flex flex-wrap items-center gap-x-6 gap-y-1 border-b-2 border-white px-4 py-2 text-base text-slate-300">
            <div class="flex items-center gap-1.5">
                <span class="w-5 h-5 rounded-sm border border-white/30 shrink-0" style="background-color: #22c55e;"></span>
                <span>{{ __('SOS Pull') }}</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="w-5 h-5 rounded-sm border border-white/30 shrink-0" style="background-color: #ff3b3b;"></span>
                <span>{{ __('Not Pulled (>15 min)') }}</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="w-5 h-5 rounded-sm border border-white/30 shrink-0" style="background-color: #3b82f6;"></span>
                <span>{{ __('Pulled') }}</span>
            </div>
            <div class="flex items-center gap-1.5 ml-auto">
                <span class="w-5 h-5 rounded-sm border border-white/30 shrink-0" style="background-color: #475569;"></span>
                <span>{{ __('Rest') }}</span>
            </div>

            {{-- History: pick a past production day (07:00 → 07:00) to see every
                 tick that day, red or blue, none of the live board's own hiding
                 rules (see HeijunkaBoxBoard::data()). Same convention as
                 Heikinka's own date picker. --}}
            <form method="GET" action="{{ route('andon-heijunka-box.show') }}" class="flex items-center gap-2">
                <label for="heijunka-date" class="text-slate-400">{{ __('Tanggal') }}:</label>
                <input id="heijunka-date" type="date" name="date" value="{{ $heijunkaDate }}" max="{{ $heijunkaMaxDate }}"
                       onchange="this.form.submit()"
                       class="rounded border border-white/30 bg-slate-900 px-2 py-1 text-white [color-scheme:dark]">
                <span class="font-bold text-white">{{ $heijunkaDateLabel }}</span>
            </form>
        </div>

        <div id="hbox-panel" class="flex-1 min-h-0">
            @include('andon-heijunka-box._grid')
        </div>
    </div>

    <button id="hbox-scroll-pause-btn" type="button" aria-pressed="false"
            style="position: fixed; bottom: 3rem; right: 1rem; z-index: 50;"
            class="fixed bottom-4 right-4 z-50 flex items-center gap-2 rounded-full border border-white/30 bg-black/70 px-4 py-2 text-sm font-bold text-white shadow-lg hover:bg-black/90">
        <span id="hbox-scroll-pause-icon">&#10074;&#10074;</span>
        <span id="hbox-scroll-pause-label">{{ __('Pause') }}</span>
    </button>

    <script>
        (function () {
            const el = document.getElementById('hbox-clock');
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

        // Live refresh: fetch fresh grid HTML every $pollMs and swap in place —
        // never cached (see AndonHeijunkaBoxController), same pattern as the
        // other Andon boards. A full reload every 30 min as a safety net.
        (function () {
            let lastGrid = document.getElementById('hbox-panel').innerHTML;

            async function refresh() {
                try {
                    const res = await fetch(window.location.href, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store',
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data.grid !== lastGrid) {
                        const panel = document.getElementById('hbox-panel');
                        const scrollEl = panel.querySelector('.andon-scroll');
                        const prevTop = scrollEl ? scrollEl.scrollTop : null;
                        const prevLeft = scrollEl ? scrollEl.scrollLeft : null;
                        panel.innerHTML = data.grid;
                        lastGrid = data.grid;
                        const fresh = panel.querySelector('.andon-scroll');
                        if (fresh && prevTop !== null) { fresh.scrollTop = prevTop; fresh.scrollLeft = prevLeft; }
                    }
                } catch (e) {
                    // Network hiccup — next tick retries.
                }
            }

            // First load: bring the progress bar into view instead of starting at 07:10.
            (function () {
                const scroller = document.querySelector('#hbox-panel .andon-scroll');
                const inner = scroller ? scroller.firstElementChild : null;
                const left = inner ? parseFloat(inner.dataset.progressLeft) : NaN;
                if (scroller && !isNaN(left)) scroller.scrollLeft = Math.max(0, left - scroller.clientWidth / 2);
            })();

            setInterval(refresh, {{ $pollMs ?? 60000 }});
            setTimeout(() => window.location.reload(), 30 * 60 * 1000);
        })();

        // Slow, looping vertical auto-scroll of the grid — same behaviour as
        // Heikinka's (see andon-kesei/show.blade.php): top -> bottom, jump
        // straight back to the top, hold there TOP_PAUSE_MS, repeat. The
        // scroller is re-queried every tick since refresh() swaps it out.
        (function () {
            const STEP_PX = 0.6;
            const TICK_MS = 40;
            const TOP_PAUSE_MS = 3000;
            let resumeAt = Date.now() + TOP_PAUSE_MS;

            let paused = false;
            const btn = document.getElementById('hbox-scroll-pause-btn');
            const icon = document.getElementById('hbox-scroll-pause-icon');
            const label = document.getElementById('hbox-scroll-pause-label');
            if (btn) {
                btn.addEventListener('click', function () {
                    paused = !paused;
                    btn.setAttribute('aria-pressed', paused ? 'true' : 'false');
                    if (icon) icon.innerHTML = paused ? '&#9654;' : '&#10074;&#10074;';
                    if (label) label.textContent = paused ? @json(__('Play')) : @json(__('Pause'));
                });
            }

            setInterval(function () {
                const scroller = document.querySelector('#hbox-panel .andon-scroll');
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
    </script>
</body>
</html>
