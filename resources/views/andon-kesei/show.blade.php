<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Andon Kesei — {{ config('app.name', 'Laravel') }}</title>
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
            <div class="text-sm text-slate-500 font-medium">{{ $productionLabel }}</div>
            <h1 class="text-xl sm:text-2xl font-extrabold tracking-wide text-brand-900 whitespace-nowrap">ANDON KESEI PT 9</h1>
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
                <div id="andon-clock" class="font-mono text-brand-800 text-base font-semibold tabular-nums" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        <div class="flex-1 min-h-0 flex flex-col rounded-xl border border-slate-300 bg-white shadow-sm overflow-hidden">
            <div class="shrink-0 flex items-center px-4 py-2 border-b border-slate-300 bg-slate-50">
                <span class="font-bold text-slate-700 text-sm tracking-wide">KESEI</span>
            </div>
            <div class="flex-1 min-h-0 flex gap-3 p-3">
                <div id="andon-panel-timeline" class="h-full min-w-0" style="flex: 3 1 0%;">
                    @include('andon-kesei._timeline')
                </div>
                <div class="h-full min-w-0 flex flex-col gap-3" style="flex: 1 1 0%;">
                    <div id="andon-panel-closing" class="min-h-0 shrink-0 rounded-lg border border-slate-300 overflow-hidden" style="max-height: 45%;">
                        @include('andon-kesei._closing-table')
                    </div>
                    <div id="andon-panel-stock" class="flex-1 min-h-0 rounded-lg border border-slate-300 overflow-hidden">
                        @include('andon-kesei._stock-timeline')
                    </div>
                </div>
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

        // Live refresh: fetch fresh panel HTML every 60s and swap in place; a
        // full reload only as a 30-min safety net. Scroll position of each
        // panel is preserved across swaps.
        (function () {
            let lastTimeline = document.getElementById('andon-panel-timeline').innerHTML;
            let lastStock = document.getElementById('andon-panel-stock').innerHTML;
            let lastClosing = document.getElementById('andon-panel-closing').innerHTML;

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
                    if (data.timeline !== lastTimeline) { replacePanel('andon-panel-timeline', data.timeline); lastTimeline = data.timeline; }
                    if (data.closingTable !== lastClosing) { replacePanel('andon-panel-closing', data.closingTable); lastClosing = data.closingTable; }
                    if (data.stockTimeline !== lastStock) { replacePanel('andon-panel-stock', data.stockTimeline); lastStock = data.stockTimeline; }
                } catch (e) {
                    // Network hiccup — next tick retries.
                }
            }

            setInterval(refresh, 60000);
            setTimeout(() => window.location.reload(), 30 * 60 * 1000);

            // Auto-scroll the stock table to the newest (bottom) row on load.
            const stockScroll = document.querySelector('#andon-panel-stock .andon-scroll');
            if (stockScroll) stockScroll.scrollTop = stockScroll.scrollHeight;
        })();
    </script>
</body>
</html>
