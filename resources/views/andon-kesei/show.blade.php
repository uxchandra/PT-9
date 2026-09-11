<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $boardTitle ?? "KESEI KANBAN LINE 9" }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: #f1f5f9; }
        .andon-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
        .andon-scroll::-webkit-scrollbar-track { background: #e2e8f0; }
        .andon-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .grid-line { position: absolute; top: 0; bottom: 0; width: 1px; background: #e2e8f0; }
        .closing-time-marker { width: 0; border-left: 2px dashed #16a34a; }
    </style>
</head>
<body class="h-screen overflow-hidden font-sans antialiased text-slate-800">
    <div class="h-screen flex flex-col px-4 sm:px-6 py-5">

        <div class="shrink-0 flex items-center justify-between gap-4 mb-5">
            <div class="flex items-center gap-3 text-sm text-slate-500 font-medium">
                <div class="inline-flex items-baseline gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 shadow-sm">
                    <span class="text-lg font-extrabold uppercase tracking-wide text-brand-500">{{ __('Pattern') }}</span>
                    <span class="text-lg font-extrabold uppercase text-brand-700">{{ $currentPattern ?? '—' }}</span>
                </div>
            </div>
            <h1 class="text-xl sm:text-2xl font-extrabold tracking-wide text-brand-900 whitespace-nowrap">{{ $boardTitle ?? "KESEI KANBAN LINE 9" }}</h1>
            <div class="flex items-center gap-4 text-sm">
                <div class="flex items-center gap-1.5">
                    <span class="flex items-center gap-px h-3">
                        <span class="block w-0.5 h-full bg-red-500 rounded-sm"></span>
                        <span class="block w-0.5 h-full bg-red-500 rounded-sm"></span>
                    </span>
                    <span class="text-slate-500">Stok Turun (kanban)</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-0 h-3 border-l-2 border-dashed" style="border-color:#16a34a;"></span>
                    <span class="text-slate-500">Closing Time</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-0 h-3 border-l-2" style="border-color:#2563eb;"></span>
                    <span class="text-slate-500">Now</span>
                </div>
                <div id="andon-clock" class="font-mono text-brand-800 text-base font-semibold tabular-nums" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        <div class="flex-1 min-h-0 flex flex-col rounded-xl border border-slate-300 bg-white shadow-sm overflow-hidden">
            <!-- <div class="shrink-0 flex items-center px-4 py-2 border-b border-slate-300 bg-slate-50">
                <span class="font-bold text-slate-700 text-sm tracking-wide">KESEI</span>
            </div> -->
            <div class="flex-1 min-h-0 p-3">
                @include('andon-kesei._board')
            </div>
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
