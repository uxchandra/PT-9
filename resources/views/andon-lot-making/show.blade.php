<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LOT MAKING LINE 9</title>
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
                <h1 class="text-2xl sm:text-4xl font-extrabold uppercase tracking-wide text-white">{{ __('LOT MAKING LINE 9') }}</h1>
            </div>
            <div class="flex shrink-0 flex-col items-center justify-center border-l-2 border-white px-6 font-bold" style="width: 190px;">
                <div class="text-lg">{{ now()->format('d/m/Y') }}</div>
                <div id="lot-making-clock" class="text-lg tabular-nums" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        <div class="flex-1 min-h-0">
            @include('andon-lot-making._board')
        </div>
    </div>

    <script>
        (function () {
            const el = document.getElementById('lot-making-clock');
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

        // Scans are live operator actions — poll fast so a new tick or a
        // freshly-completed cycle shows up on the wall board almost
        // instantly (same cadence as Andon Kesei Scan).
        (function () {
            let lastGrid = document.getElementById('lot-making-panel-grid').innerHTML;
            let lastRoller = document.getElementById('lot-making-panel-roller').innerHTML;

            function scrollRollerToBottom() {
                const roller = document.getElementById('lot-making-roller');
                if (roller) roller.scrollTop = roller.scrollHeight;
            }

            async function refresh() {
                try {
                    const res = await fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) return;
                    const data = await res.json();

                    if (data.grid !== lastGrid) {
                        document.getElementById('lot-making-panel-grid').innerHTML = data.grid;
                        lastGrid = data.grid;
                    }
                    if (data.roller !== lastRoller) {
                        document.getElementById('lot-making-panel-roller').innerHTML = data.roller;
                        lastRoller = data.roller;
                        scrollRollerToBottom();
                    }
                } catch (e) {
                    // Network hiccup — next tick retries.
                }
            }

            scrollRollerToBottom();
            setInterval(refresh, 3000);
            setTimeout(() => window.location.reload(), 30 * 60 * 1000);
        })();
    </script>
</body>
</html>
