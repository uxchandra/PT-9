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
        .closing-time-marker { width: 0; border-left: 2px dashed #22c55e; filter: drop-shadow(0 0 3px rgba(34,197,94,0.7)); }
    </style>
</head>
<body class="h-screen w-screen overflow-hidden bg-black font-sans antialiased text-white">
    <div class="h-screen flex flex-col">

        <div class="shrink-0 flex items-stretch border-b-2 border-white">
            <div class="flex shrink-0 items-center justify-center border-r-2 border-white px-4" style="width: 170px;">
                <img src="{{ asset('images/logo_step.png') }}" alt="STEP" class="h-14 w-auto object-contain">
            </div>
            <div class="flex flex-1 items-center justify-center py-3">
                <h1 class="text-2xl sm:text-4xl font-extrabold uppercase tracking-wide text-white">{{ $boardTitle ?? "KESEI KANBAN LINE 9" }}</h1>
            </div>
            <div class="flex shrink-0 flex-col items-center justify-center border-l-2 border-white px-6 font-bold" style="width: 190px;">
                <div class="text-lg">{{ now()->format('d/m/Y') }}</div>
                <div id="andon-clock" class="text-lg tabular-nums" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        <div class="shrink-0 flex items-center justify-between gap-4 border-b-2 border-white px-4 py-2">
            <div class="inline-flex items-baseline gap-2">
                <span class="text-base font-extrabold uppercase tracking-wide text-slate-400">{{ __('Pattern') }}</span>
                <span class="text-base font-extrabold uppercase text-white">{{ $currentPattern ?? '—' }}</span>
            </div>
            <div class="flex items-center gap-4 text-xs text-slate-300">
                <div class="flex items-center gap-1.5">
                    <span class="flex items-center gap-px h-3">
                        <span class="block w-0.5 h-full rounded-sm" style="background-color: #ff3b3b;"></span>
                        <span class="block w-0.5 h-full rounded-sm" style="background-color: #ff3b3b;"></span>
                    </span>
                    <span>{{ __('Stok Turun (kanban)') }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-0 h-3 border-l-2 border-dashed" style="border-color:#22c55e;"></span>
                    <span>{{ __('Closing Time') }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-0 h-3 border-l-2" style="border-color:#3b82f6;"></span>
                    <span>{{ __('Now') }}</span>
                </div>
            </div>
        </div>

        <div class="flex-1 min-h-0">
            @include('andon-kesei._board-dark')
        </div>
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

        // Live refresh: fetch fresh panel HTML every $pollMs and swap in place
        // (the scan board polls much faster than the stock board — see
        // AndonKeseiController); a full reload only as a 30-min safety net.
        // Scroll position of each panel is preserved across swaps.
        (function () {
            let lastTimeline = document.getElementById('kesei-panel-timeline').innerHTML;
            let lastStock = document.getElementById('kesei-panel-stock').innerHTML;
            let lastClosing = document.getElementById('kesei-panel-closing').innerHTML;

            function replacePanel(id, html) {
                const panel = document.getElementById(id);
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
                    const res = await fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data.timeline !== lastTimeline) {
                        replacePanel('kesei-panel-timeline', data.timeline);
                        lastTimeline = data.timeline;
                        if (window.__keseiNowSync) window.__keseiNowSync();
                    }
                    if (data.closingTable !== lastClosing) { replacePanel('kesei-panel-closing', data.closingTable); lastClosing = data.closingTable; }
                    if (data.stockTimeline !== lastStock) { replacePanel('kesei-panel-stock', data.stockTimeline); lastStock = data.stockTimeline; }
                } catch (e) {
                    // Network hiccup — next tick retries.
                }
            }

            setInterval(refresh, {{ $pollMs ?? 60000 }});
            setTimeout(() => window.location.reload(), 30 * 60 * 1000);

            // Auto-scroll the stock table to the newest (bottom) row on load.
            const stockScroll = document.querySelector('#kesei-panel-stock .andon-scroll');
            if (stockScroll) stockScroll.scrollTop = stockScroll.scrollHeight;
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
                };
                baseAt = Date.now();
                place(el);

                const scroller = document.querySelector('#kesei-panel-timeline .andon-scroll');
                if (scroller) scroller.scrollLeft = Math.max(0, offsetPx() - 320);
            }

            function place(el) {
                el.style.left = (110 + offsetPx()) + 'px';
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
