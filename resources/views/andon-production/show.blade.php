<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $boardTitle ?? 'ANDON PRODUCTION LINE 9' }}</title>
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

        {{-- Draggable divider between resizable panels — a wide invisible hit
             zone (comfortable to grab, including by touch) with a thin white
             line centered inside it, matching the old static border look. --}}
        .andon-production-divider {
            flex: 0 0 10px;
            position: relative;
            cursor: col-resize;
            touch-action: none;
        }
        .andon-production-divider::after {
            content: '';
            position: absolute;
            top: 0; bottom: 0; left: 4px;
            width: 2px;
            background: #ffffff;
        }
        .andon-production-divider:hover::after,
        .andon-production-divider.is-dragging::after {
            left: 3px;
            width: 4px;
            background: #22d3ee;
        }
    </style>
</head>
<body class="h-screen w-screen overflow-hidden bg-black font-sans antialiased text-white">
    <div class="h-screen flex flex-col">

        <div class="shrink-0 flex items-stretch border-b-2 border-white">
            <div class="flex shrink-0 items-center justify-center border-r-2 border-white px-4" style="width: 170px;">
                <img src="{{ asset('images/logo_step.png') }}" alt="STEP" class="h-14 w-auto object-contain">
            </div>
            <div class="flex flex-1 items-center justify-center py-3">
                <h1 class="text-2xl sm:text-4xl font-extrabold uppercase tracking-wide text-white">{{ $boardTitle ?? __('ANDON PRODUCTION LINE 9') }}</h1>
            </div>
            <div class="flex shrink-0 flex-col items-center justify-center gap-0.5 border-l-2 border-white px-6" style="width: 240px;">
                <span class="text-lg font-extrabold uppercase tracking-wide text-white">{{ __('Pattern') }}</span>
                <span class="text-2xl sm:text-4xl font-extrabold uppercase tracking-wide text-white truncate">{{ $currentPattern ?? '—' }}</span>
            </div>
            <div class="flex shrink-0 flex-col items-center justify-center border-l-2 border-white px-6 font-bold" style="width: 190px;">
                <div class="text-lg">{{ now()->format('d/m/Y') }}</div>
                <div id="andon-production-clock" class="text-lg tabular-nums" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        <div class="shrink-0 flex flex-wrap items-center gap-x-4 gap-y-1 border-b-2 border-white px-4 py-2 text-base text-slate-300">
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
        </div>

        <div id="andon-production-columns" class="flex-1 min-h-0 flex bg-black">
            <div id="andon-production-panel-kesei" class="h-full min-w-0" style="flex: 38 38 0%;">
                @include('andon-production._kesei-timeline')
            </div>

            <div class="andon-production-divider" data-divider-index="0" title="{{ __('Geser untuk mengubah lebar panel') }}"></div>

            <div id="andon-production-column-lotmaking" class="h-full min-w-0 flex flex-col" style="flex: 37 37 0%;">
                <div class="shrink-0 px-3 py-1.5 border-b-2 border-white">
                    <span class="text-xs font-bold text-white tracking-wide">{{ __('LOT MAKING') }}</span>
                </div>
                <div id="andon-production-panel-lotmaking" class="flex-1 min-h-0 overflow-hidden">
                    @include('andon-lot-making._grid', ['rows' => $lotMakingRows])
                </div>
            </div>

            <div class="andon-production-divider" data-divider-index="1" title="{{ __('Geser untuk mengubah lebar panel') }}"></div>

            <div id="andon-production-column-sidebar" class="h-full min-w-0 flex flex-col" style="flex: 20 20 0%;">
                <div id="andon-production-panel-closing" class="min-h-0 shrink-0 border-b-2 border-white overflow-hidden" style="max-height: 45%;">
                    @include('andon-kesei._closing-table-dark')
                </div>
                <div id="andon-production-panel-antrian" class="flex-1 min-h-0 overflow-hidden">
                    @include('andon-kesei._antrian-fix-volume-dark')
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const el = document.getElementById('andon-production-clock');
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

        // Live refresh of all three panels — never served from a browser/proxy
        // cache (see AndonProductionController), so every poll hits the DB.
        (function () {
            let lastKesei = document.getElementById('andon-production-panel-kesei').innerHTML;
            let lastLotMaking = document.getElementById('andon-production-panel-lotmaking').innerHTML;
            let lastClosing = document.getElementById('andon-production-panel-closing').innerHTML;
            let lastAntrian = document.getElementById('andon-production-panel-antrian').innerHTML;

            async function refresh() {
                try {
                    const res = await fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
                    if (!res.ok) return;
                    const data = await res.json();

                    if (data.keseiTimeline !== lastKesei) {
                        document.getElementById('andon-production-panel-kesei').innerHTML = data.keseiTimeline;
                        lastKesei = data.keseiTimeline;
                        if (window.__keseiProductionSync) window.__keseiProductionSync();
                    }
                    if (data.lotMakingGrid !== lastLotMaking) {
                        document.getElementById('andon-production-panel-lotmaking').innerHTML = data.lotMakingGrid;
                        lastLotMaking = data.lotMakingGrid;
                    }
                    if (data.closingTable !== lastClosing) {
                        document.getElementById('andon-production-panel-closing').innerHTML = data.closingTable;
                        lastClosing = data.closingTable;
                    }
                    if (data.antrianFixVolume !== lastAntrian) {
                        document.getElementById('andon-production-panel-antrian').innerHTML = data.antrianFixVolume;
                        lastAntrian = data.antrianFixVolume;
                    }
                } catch (e) {
                    // Network hiccup — next tick retries.
                }
            }

            setInterval(refresh, {{ $pollMs ?? 15000 }});
            setTimeout(() => window.location.reload(), 30 * 60 * 1000);
        })();

        // Keeps the Kesei "now" line fixed on screen by continuously
        // scrolling the timeline underneath it instead — the exact opposite
        // of the standalone Andon Kesei board's moving line. Same base+baseAt
        // ticking pattern as that board's own now-line, just applied to
        // scrollLeft. Re-queries the scroller live every tick (never caches
        // the element reference) since a panel refresh replaces it wholesale
        // via innerHTML.
        (function () {
            let base = null;
            let baseAt = 0;

            function currentNow() {
                return base.now + (Date.now() - baseAt) / 60000;
            }

            function offsetPx() {
                const total = base.total || (24 * 60);
                let m = (currentNow() - base.dayStart) % total;
                if (m < 0) m += total;
                return m * base.px;
            }

            function place(el) {
                el.scrollLeft = Math.max(0, offsetPx() - base.earlyWindow * base.px);
            }

            function sync(el) {
                base = {
                    now: parseFloat(el.dataset.now),
                    dayStart: parseFloat(el.dataset.dayStart),
                    px: parseFloat(el.dataset.px),
                    total: parseFloat(el.dataset.total),
                    earlyWindow: parseFloat(el.dataset.earlyWindow),
                };
                baseAt = Date.now();
                place(el);
            }

            window.__keseiProductionSync = function () {
                const el = document.getElementById('kesei-production-scroll');
                if (el) sync(el);
            };

            window.__keseiProductionSync();

            setInterval(function () {
                const el = document.getElementById('kesei-production-scroll');
                if (!el) { base = null; return; }
                if (!base || parseFloat(el.dataset.now) !== base.now) { sync(el); return; }
                place(el);
            }, 1000);
        })();

        // Drag-to-resize the three panels — live-session only, on purpose: a
        // poll's innerHTML swap only touches each panel's CONTENTS (never
        // these outer elements), so a drag survives every poll, but nothing
        // is saved anywhere — a plain refresh (or the 30-min auto-reload)
        // always comes back to the default 45/30/20 split.
        //
        // Widths are applied as flex-grow RATIOS (flex: n n 0%), not frozen
        // pixel widths — with a 0% basis, the three panels always divide up
        // exactly whatever space is actually left after the two fixed-width
        // dividers, so nothing can go visibly out of sync with the real
        // container width (a frozen px snapshot would, e.g. across a browser
        // zoom change after the snapshot was taken).
        (function () {
            const MIN_WIDTH = 220;

            const panels = [
                document.getElementById('andon-production-panel-kesei'),
                document.getElementById('andon-production-column-lotmaking'),
                document.getElementById('andon-production-column-sidebar'),
            ];
            const dividers = Array.from(document.querySelectorAll('.andon-production-divider'));
            if (panels.some((p) => !p) || dividers.length !== panels.length - 1) return;

            function currentWidths() {
                return panels.map((p) => p.getBoundingClientRect().width);
            }

            function applyRatios(widths) {
                panels.forEach((p, i) => {
                    if (typeof widths[i] === 'number' && widths[i] > 0) {
                        p.style.flex = widths[i] + ' ' + widths[i] + ' 0%';
                    }
                });
            }

            let dragging = null; // { index, startX, startWidths }

            function beginDrag(index, clientX) {
                dragging = { index, startX: clientX, startWidths: currentWidths() };
                dividers[index].classList.add('is-dragging');
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
            }

            function updateDrag(clientX) {
                if (!dragging) return;
                const dx = clientX - dragging.startX;
                const widths = dragging.startWidths.slice();
                const left = dragging.index;
                const right = dragging.index + 1;
                let newLeft = widths[left] + dx;
                let newRight = widths[right] - dx;

                // Both sides must stay usable — clamp without letting the
                // total width the drag controls silently change.
                if (newLeft < MIN_WIDTH) { newRight -= (MIN_WIDTH - newLeft); newLeft = MIN_WIDTH; }
                if (newRight < MIN_WIDTH) { newLeft -= (MIN_WIDTH - newRight); newRight = MIN_WIDTH; }
                widths[left] = Math.max(MIN_WIDTH, newLeft);
                widths[right] = Math.max(MIN_WIDTH, newRight);

                applyRatios(widths);
            }

            function endDrag() {
                if (!dragging) return;
                dividers[dragging.index].classList.remove('is-dragging');
                dragging = null;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
            }

            dividers.forEach((divider, index) => {
                divider.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    beginDrag(index, e.clientX);
                });
                divider.addEventListener('touchstart', (e) => {
                    if (e.touches[0]) beginDrag(index, e.touches[0].clientX);
                }, { passive: true });
            });

            window.addEventListener('mousemove', (e) => updateDrag(e.clientX));
            window.addEventListener('touchmove', (e) => {
                if (dragging && e.touches[0]) updateDrag(e.touches[0].clientX);
            }, { passive: true });
            window.addEventListener('mouseup', endDrag);
            window.addEventListener('touchend', endDrag);
        })();
    </script>
</body>
</html>
