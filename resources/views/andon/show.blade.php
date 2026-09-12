<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Andon {{ $patternBoard?->name ?? 'Auto' }} — {{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: #000; }

        .andon-scroll::-webkit-scrollbar { height: 10px; }
        .andon-scroll::-webkit-scrollbar-track { background: #1e293b; }
        .andon-scroll::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }

        .block-loading {
            border: 1px solid #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }
        .block-dandori {
            background-color: #334155;
            border: 1px solid #ffffff;
        }
        .rest-band {
            background-image: repeating-linear-gradient(135deg, #1d4ed8 0 8px, #1e3a8a 8px 16px);
            border-left: 1px solid #3b82f6;
            border-right: 1px solid #3b82f6;
        }
        .grid-line {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1px;
            background: rgba(255,255,255,0.1);
        }
        .closing-time-marker {
            width: 0;
            border-left: 2px dashed #22c55e;
            filter: drop-shadow(0 0 3px rgba(34,197,94,0.7));
        }
        /* Closing time that really sits before 07:00 — pinned to the left edge
           so it's never lost. Solid + a small marker so it reads as "off to
           the left", not an exact-position tick. */
        .closing-time-marker--pinned {
            border-left-style: solid;
            box-shadow: 3px 0 4px -1px rgba(34, 197, 94, 0.7);
        }
        .closing-time-marker--pinned::after {
            content: '\00AB';
            position: absolute;
            left: 2px;
            top: 2px;
            font-size: 11px;
            line-height: 1;
            font-weight: 700;
            color: #22c55e;
        }
        .andon-cards-stack {
            display: flex;
            flex-direction: column;
        }
        .andon-card-drag-handle { cursor: grab; }
        .andon-card-drag-handle:active { cursor: grabbing; }
        .andon-card-drop-target { outline: 2px dashed #f59e0b; outline-offset: -2px; }
    </style>
</head>
<body class="h-screen w-screen overflow-hidden bg-black font-sans antialiased text-white">
    <div class="h-screen flex flex-col px-4 sm:px-6 py-5" x-data="andonPanels()">

        <!-- Header -->
        <div class="shrink-0 grid grid-cols-[1fr_auto_1fr] items-center gap-4 mb-5">
            <div class="flex flex-wrap items-center gap-2">
                <select onchange="if (this.value) window.location.href = this.value;"
                        class="px-3 py-2 text-sm font-bold border border-white bg-black text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    @foreach ($patternBoards as $board)
                        <option value="{{ route('andon.show', $board) }}" @selected($board->id === $patternBoard?->id)>
                            {{ $board->name }}
                        </option>
                    @endforeach
                </select>

                @unless (empty($rows))
                    <span class="w-px h-6 bg-white/30 mx-1"></span>

                    @foreach (['andon' => 'PATTERN', 'kosei' => 'KESEI', 'planning' => 'PLANNING'] as $key => $label)
                        <button type="button" @click="collapsed.{{ $key }} ? show('{{ $key }}') : hide('{{ $key }}')"
                                :class="collapsed.{{ $key }}
                                    ? 'bg-black text-slate-300 border-white hover:border-brand-400 hover:text-white'
                                    : 'bg-brand-800 text-white border-brand-800'"
                                class="px-4 py-2 text-sm font-bold border transition">
                            {{ $label }}
                        </button>
                    @endforeach
                @endunless
            </div>

            <div class="text-center">
                <h1 class="text-xl sm:text-2xl font-extrabold tracking-wide text-white whitespace-nowrap">ANDON MONITORING LINE 9</h1>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-4 text-sm">
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 border border-white bg-slate-700"></span><span class="text-slate-300">Dandori</span></div>
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 border border-blue-500" style="background-image:repeating-linear-gradient(135deg,#1d4ed8 0 3px,#1e3a8a 3px 6px)"></span><span class="text-slate-300">Rest</span></div>
                <div class="flex items-center gap-1.5"><span class="flex items-center gap-px h-3"><span class="block w-0.5 h-full rounded-sm" style="background-color:#ff3b3b;"></span><span class="block w-0.5 h-full rounded-sm" style="background-color:#ff3b3b;"></span></span><span class="text-slate-300">Kanban Pull</span></div>
                <div class="flex items-center gap-1.5"><span class="w-0 h-3 border-l-2 border-dashed" style="border-color:#22c55e;"></span><span class="text-slate-300">Closing Time</span></div>
                <div id="andon-clock" class="font-mono text-white text-base font-semibold tabular-nums ml-2" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        @if (empty($rows))
            <div class="flex-1 min-h-0 flex items-center justify-center border-2 border-white bg-black text-center text-slate-400 px-6">
                {{ $noBoardMessage ?? __('Belum ada pattern yang di-assign ke mesin untuk board ini.') }}
            </div>
        @else
            <div class="flex-1 min-h-0 flex flex-col gap-3"
                 id="andon-panels"
                 data-day-start="{{ $dayStart }}"
                 data-px-per-minute="{{ $pxPerMinute }}"
                 data-now-minute="{{ $nowMinute }}">

                <!-- Expanded cards: stacked top to bottom, order follows drag-and-drop -->
                <div class="andon-cards-stack flex-1 min-h-0 gap-3">

                    <!-- Card: Pattern -->
                    <div class="flex flex-col min-h-0 flex-1 border-2 border-white bg-black overflow-hidden"
                         x-show="!collapsed.andon" :class="cardClass('andon')" :style="cardStyle('andon')"
                         @dragover.prevent="dragOver = 'andon'" @dragleave="dragOver = null"
                         @drop.prevent="drop('andon')">
                        <div class="andon-card-drag-handle shrink-0 flex items-center justify-between px-4 py-2 border-b-2 border-white bg-black"
                             draggable="true" @dragstart="dragging = 'andon'" @dragend="dragging = null; dragOver = null">
                            <span class="font-bold text-white text-sm tracking-wide select-none">⠿ {{ __('PATTERN') }}</span>
                            <button type="button" @click="hide('andon')"
                                    class="w-6 h-6 flex items-center justify-center border border-white bg-black text-white hover:bg-white/10 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/>
                                </svg>
                            </button>
                        </div>
                        <div id="andon-panel-timeline" class="flex-1 min-h-0">
                            @include('andon._timeline')
                        </div>
                    </div>

                    <!-- Card: Kesei -->
                    <div class="flex flex-col min-h-0 flex-1 border-2 border-white bg-black overflow-hidden"
                         x-show="!collapsed.kosei" :class="cardClass('kosei')" :style="cardStyle('kosei')"
                         @dragover.prevent="dragOver = 'kosei'" @dragleave="dragOver = null"
                         @drop.prevent="drop('kosei')">
                        <div class="andon-card-drag-handle shrink-0 flex items-center justify-between px-4 py-2 border-b-2 border-white bg-black"
                             draggable="true" @dragstart="dragging = 'kosei'" @dragend="dragging = null; dragOver = null">
                            <span class="font-bold text-white text-sm tracking-wide select-none">⠿ {{ __('KESEI') }}</span>
                            <button type="button" @click="hide('kosei')"
                                    class="w-6 h-6 flex items-center justify-center border border-white bg-black text-white hover:bg-white/10 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/>
                                </svg>
                            </button>
                        </div>
                        <div id="andon-panel-kosei" class="flex-1 min-h-0">
                            {!! $keseiBoardHtml !!}
                        </div>
                    </div>

                    <!-- Card: Planning -->
                    <div class="flex flex-col min-h-0 flex-1 border-2 border-white bg-black overflow-hidden"
                         x-show="!collapsed.planning" :class="cardClass('planning')" :style="cardStyle('planning')"
                         @dragover.prevent="dragOver = 'planning'" @dragleave="dragOver = null"
                         @drop.prevent="drop('planning')">
                        <div class="andon-card-drag-handle shrink-0 flex items-center justify-between px-4 py-2 border-b-2 border-white bg-black"
                             draggable="true" @dragstart="dragging = 'planning'" @dragend="dragging = null; dragOver = null">
                            <div class="select-none">
                                <span class="font-bold text-white text-sm tracking-wide">⠿ {{ __('PLANNING') }}</span>
                                <p class="text-[11px] text-slate-400">{{ __('Kanban = demand Kesei pada closing time (H-4 jam dari mulai produksi)') }}</p>
                            </div>
                            <button type="button" @click="hide('planning')"
                                    class="w-6 h-6 flex items-center justify-center border border-white bg-black text-white hover:bg-white/10 transition shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/>
                                </svg>
                            </button>
                        </div>
                        <div id="andon-panel-planning" class="flex-1 min-h-0">
                            @include('andon._timeline', ['rows' => $planningRows, 'isPlanning' => true, 'editable' => false])
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        // The auto (Calendar-driven) /andon page polls itself and still
        // follows the Calendar (a rollover, or a board appearing for a
        // previously-empty day). A board picked from the dropdown is a
        // deliberate, lasting choice — it polls its OWN endpoint and never
        // gets redirected back to whatever the Calendar says today.
        window.__ANDON = {
            refreshUrl: @json($auto ?? true ? route('andon.index') : route('andon.show', $patternBoard)),
            boardId: @json($patternBoard?->id),
            auto: @json($auto ?? true),
        };

        // Drag-and-drop card arrangement: 3 cards, up to 2 per row. Order and
        // collapsed state persist per-browser (localStorage) so a rearranged
        // layout survives the page's own periodic refresh/reload.
        function safeStorage(key, fallback) {
            try {
                const raw = localStorage.getItem(key);
                return raw ? JSON.parse(raw) : fallback;
            } catch (e) {
                return fallback;
            }
        }

        function andonPanels() {
            return {
                collapsed: safeStorage('andon-card-collapsed', { andon: false, kosei: false, planning: true }),
                order: safeStorage('andon-card-order', ['andon', 'kosei', 'planning']),
                dragging: null,
                dragOver: null,

                cardClass(key) {
                    const classes = [];
                    if (this.dragging === key) classes.push('opacity-40');
                    if (this.dragOver === key && this.dragging !== key) classes.push('andon-card-drop-target');

                    return classes.join(' ');
                },

                // CSS `order` follows the drag-and-drop arrangement — cards
                // stack top to bottom in this order, not DOM position.
                cardStyle(key) {
                    return 'order: ' + this.order.indexOf(key) + ';';
                },

                show(key) {
                    this.collapsed[key] = false;
                    this.persist();
                },

                hide(key) {
                    this.collapsed[key] = true;
                    this.persist();
                },

                drop(targetKey) {
                    const fromKey = this.dragging;
                    this.dragOver = null;
                    if (!fromKey || fromKey === targetKey) return;

                    const fromIndex = this.order.indexOf(fromKey);
                    const toIndex = this.order.indexOf(targetKey);
                    if (fromIndex === -1 || toIndex === -1) return;

                    this.order.splice(fromIndex, 1);
                    this.order.splice(toIndex, 0, fromKey);
                    this.persist();
                },

                persist() {
                    try {
                        localStorage.setItem('andon-card-collapsed', JSON.stringify(this.collapsed));
                        localStorage.setItem('andon-card-order', JSON.stringify(this.order));
                    } catch (e) {
                        // Private browsing / storage disabled — arrangement just won't persist.
                    }
                },
            };
        }

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
        // fresh HTML for the panels in the background and swaps them in
        // place, so the browser tab never shows a reload/spinner. A full
        // reload only happens rarely (every 30 min) as a safety net against
        // long-running tab drift — the display normally never visibly reloads.
        //
        // The poll always hits the auto (Calendar) endpoint, not this page's
        // own URL: when the Calendar's board changes (06:30 rollover) — or the
        // page is a manual override that should snap back — the response's
        // boardId no longer matches, and only then does it navigate to the
        // auto URL for a clean full re-resolve.
        (function () {
            const refreshUrl = window.__ANDON?.refreshUrl;
            if (!refreshUrl) return;

            const currentBoardId = window.__ANDON.boardId ?? null;

            const panels = document.getElementById('andon-panels');
            const hasPanels = !!panels;

            const dayStart = hasPanels ? parseFloat(panels.dataset.dayStart) : 0;
            const pxPerMinute = hasPanels ? parseFloat(panels.dataset.pxPerMinute) : 1;
            let nowMinute = hasPanels ? parseFloat(panels.dataset.nowMinute) : 0;
            let lastTimelineHtml = hasPanels ? document.getElementById('andon-panel-timeline').innerHTML : '';
            let lastKoseiHtml = hasPanels ? document.getElementById('andon-panel-kosei').innerHTML : '';
            let lastPlanningHtml = hasPanels ? document.getElementById('andon-panel-planning').innerHTML : '';

            // Once the operator scrolls a panel by hand, that panel stops
            // auto-following "now" entirely — it stays exactly where it was
            // parked. Auto-follow only comes back on the next full page reload
            // (every 30 min). Our own programmatic scrolls (scrollToNow, the
            // post-swap restore) are flagged so they don't count as the user
            // taking control.
            let followDisabled = false;
            let programmaticScroll = false;

            if (hasPanels) {
                // Capture phase: a `scroll` event on any nested .andon-scroll
                // container reaches here even though scroll doesn't bubble, and
                // survives the innerHTML swaps that replace those containers.
                panels.addEventListener('scroll', () => {
                    if (!programmaticScroll) followDisabled = true;
                }, true);
                ['wheel', 'touchmove'].forEach((eventName) => {
                    panels.addEventListener(eventName, () => { followDisabled = true; }, { passive: true });
                });
            }

            function withProgrammaticScroll(fn) {
                programmaticScroll = true;
                fn();
                // Scroll events land async — keep the flag up briefly after.
                setTimeout(() => { programmaticScroll = false; }, 150);
            }

            // Pattern and Planning no longer auto-follow "now" — only Kesei
            // (horizontal) and Timeline Stok (vertical, jumps to the newest
            // row) still do, and only until the operator scrolls them. Always
            // instant, never animated: an animated scroll right after swapping
            // innerHTML is what caused a visible "blink".
            function scrollToNow() {
                if (followDisabled) return;

                withProgrammaticScroll(() => {
                    // Timeline Stok inside the Kesei board scrolls vertically
                    // (rows = time) — jump to the newest (bottom) row.
                    const stockScroll = document.querySelector('#kesei-panel-stock .andon-scroll');
                    if (stockScroll) stockScroll.scrollTop = stockScroll.scrollHeight;
                });
            }

            // Swapping innerHTML always resets that element's own scroll to 0
            // — so its previous position is always captured and restored right
            // after, so the refresh never yanks a hand-parked panel back to 0.
            function replacePanel(id, html) {
                const panel = document.getElementById(id);

                const scrollEl = document.querySelector(`#${id} .andon-scroll`);
                const prevLeft = scrollEl ? scrollEl.scrollLeft : null;
                const prevTop = scrollEl ? scrollEl.scrollTop : null;

                panel.innerHTML = html;

                withProgrammaticScroll(() => {
                    const freshScrollEl = document.querySelector(`#${id} .andon-scroll`);
                    if (freshScrollEl && prevLeft !== null) freshScrollEl.scrollLeft = prevLeft;
                    if (freshScrollEl && prevTop !== null) freshScrollEl.scrollTop = prevTop;
                });

                return true;
            }

            async function refresh() {
                try {
                    const res = await fetch(refreshUrl, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) return;
                    const data = await res.json();

                    // Only the auto page re-resolves itself on a Calendar
                    // rollover or a board just getting assigned to a
                    // previously-empty day — a dropdown-picked board polls
                    // its own endpoint (so boardId always matches itself) and
                    // is never redirected away from what was picked.
                    if (window.__ANDON.auto && (data.reload || (data.boardId ?? null) !== currentBoardId)) {
                        window.location.assign(refreshUrl);
                        return;
                    }

                    if (!hasPanels) return;

                    // Only touch the DOM for panels whose content actually
                    // changed — most 60s ticks have nothing new (patterns
                    // rarely change, stock only every 5min), so this avoids
                    // needless repaint/flicker on every single tick. If a
                    // panel is skipped (an actual-kanban input has focus),
                    // its "last" value is left as-is so the next tick retries.
                    if (data.timeline !== lastTimelineHtml && replacePanel('andon-panel-timeline', data.timeline)) {
                        lastTimelineHtml = data.timeline;
                    }
                    if (data.kosei !== lastKoseiHtml && replacePanel('andon-panel-kosei', data.kosei)) {
                        lastKoseiHtml = data.kosei;
                    }
                    if (data.planning !== lastPlanningHtml && replacePanel('andon-panel-planning', data.planning)) {
                        lastPlanningHtml = data.planning;
                    }

                    nowMinute = data.nowMinute;
                    scrollToNow();
                } catch (e) {
                    // Network hiccup — the next scheduled tick will retry.
                }
            }

            if (hasPanels) scrollToNow();
            setInterval(refresh, 60000);
            setTimeout(() => window.location.assign(refreshUrl), 30 * 60 * 1000);

            // Nudges the scroll position forward every second between data
            // refreshes, so "now" keeps creeping right in real time instead of
            // only jumping once a minute.
            if (hasPanels) {
                setInterval(() => {
                    nowMinute += 1 / 60;
                    scrollToNow();
                }, 1000);
            }
        })();
    </script>
</body>
</html>
