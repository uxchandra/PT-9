<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
        .closing-time-marker {
            width: 0;
            border-left: 2px dashed #16a34a;
        }
        .andon-cards-stack {
            display: flex;
            flex-direction: column;
        }
        .andon-card-drag-handle { cursor: grab; }
        .andon-card-drag-handle:active { cursor: grabbing; }
        .andon-card-drop-target { outline: 2px dashed #b45309; outline-offset: -2px; }
    </style>
</head>
<body class="h-screen overflow-hidden font-sans antialiased text-slate-800">
    <div class="h-screen flex flex-col px-4 sm:px-6 py-5" x-data="andonPanels()">

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

                @unless (empty($rows))
                    <span class="w-px h-6 bg-slate-300 mx-1"></span>

                    @foreach (['andon' => 'PATTERN', 'kosei' => 'KESEI', 'planning' => 'PLANNING'] as $key => $label)
                        <button type="button" @click="collapsed.{{ $key }} ? show('{{ $key }}') : hide('{{ $key }}')"
                                :class="collapsed.{{ $key }}
                                    ? 'bg-white text-slate-500 border-slate-300 hover:border-brand-400 hover:text-brand-800'
                                    : 'bg-brand-800 text-white border-brand-800'"
                                class="px-4 py-2 rounded-lg text-sm font-bold border transition shadow-sm">
                            {{ $label }}
                        </button>
                    @endforeach
                @endunless
            </div>

            <h1 class="text-center text-xl sm:text-2xl font-extrabold tracking-wide text-brand-900 whitespace-nowrap">ANDON MONITORING PT 9</h1>

            <div class="flex flex-wrap items-center justify-end gap-4 text-sm">
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm border border-white bg-slate-900"></span><span class="text-slate-500">Dandori</span></div>
                <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm border border-blue-400" style="background-image:repeating-linear-gradient(135deg,#93c5fd 0 3px,#bfdbfe 3px 6px)"></span><span class="text-slate-500">Rest</span></div>
                <div class="flex items-center gap-1.5"><span class="flex items-center gap-px h-3"><span class="block w-0.5 h-full bg-red-500 rounded-sm"></span><span class="block w-0.5 h-full bg-red-500 rounded-sm"></span></span><span class="text-slate-500">Stok Turun (kanban)</span></div>
                <div class="flex items-center gap-1.5"><span class="w-0 h-3 border-l-2 border-dashed" style="border-color:#16a34a;"></span><span class="text-slate-500">Closing Time</span></div>
                <div id="andon-clock" class="font-mono text-brand-800 text-base font-semibold tabular-nums ml-2" data-server-time="{{ now()->format('H:i:s') }}"></div>
            </div>
        </div>

        @if (empty($rows))
            <div class="flex-1 min-h-0 flex items-center justify-center rounded-xl border border-slate-200 bg-white text-center text-slate-400 shadow-sm">
                {{ __('Belum ada pattern yang di-assign ke mesin untuk board ini.') }}
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
                    <div class="flex flex-col min-h-0 flex-1 rounded-xl border border-slate-300 bg-white shadow-sm overflow-hidden"
                         x-show="!collapsed.andon" :class="cardClass('andon')" :style="cardStyle('andon')"
                         @dragover.prevent="dragOver = 'andon'" @dragleave="dragOver = null"
                         @drop.prevent="drop('andon')">
                        <div class="andon-card-drag-handle shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-300 bg-slate-50"
                             draggable="true" @dragstart="dragging = 'andon'" @dragend="dragging = null; dragOver = null">
                            <span class="font-bold text-slate-700 text-sm tracking-wide select-none">⠿ {{ __('PATTERN') }}</span>
                            <button type="button" @click="hide('andon')"
                                    class="w-6 h-6 flex items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-100 transition">
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
                    <div class="flex flex-col min-h-0 flex-1 rounded-xl border border-slate-300 bg-white shadow-sm overflow-hidden"
                         x-show="!collapsed.kosei" :class="cardClass('kosei')" :style="cardStyle('kosei')"
                         @dragover.prevent="dragOver = 'kosei'" @dragleave="dragOver = null"
                         @drop.prevent="drop('kosei')">
                        <div class="andon-card-drag-handle shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-300 bg-slate-50"
                             draggable="true" @dragstart="dragging = 'kosei'" @dragend="dragging = null; dragOver = null">
                            <span class="font-bold text-slate-700 text-sm tracking-wide select-none">⠿ {{ __('KESEI') }}</span>
                            <button type="button" @click="hide('kosei')"
                                    class="w-6 h-6 flex items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-100 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

                    <!-- Card: Planning -->
                    <div class="flex flex-col min-h-0 flex-1 rounded-xl border border-slate-300 bg-white shadow-sm overflow-hidden"
                         x-show="!collapsed.planning" :class="cardClass('planning')" :style="cardStyle('planning')"
                         @dragover.prevent="dragOver = 'planning'" @dragleave="dragOver = null"
                         @drop.prevent="drop('planning')">
                        <div class="andon-card-drag-handle shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-300 bg-slate-50"
                             draggable="true" @dragstart="dragging = 'planning'" @dragend="dragging = null; dragOver = null">
                            <div class="select-none">
                                <span class="font-bold text-slate-700 text-sm tracking-wide">⠿ {{ __('PLANNING') }}</span>
                                <p class="text-[11px] text-slate-400">{{ __('Kanban = demand Kesei pada closing time (H-4 jam dari mulai produksi)') }}</p>
                            </div>
                            <button type="button" @click="hide('planning')"
                                    class="w-6 h-6 flex items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-100 transition shrink-0">
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
        (function () {
            const panels = document.getElementById('andon-panels');
            if (!panels) return;

            const dayStart = parseFloat(panels.dataset.dayStart);
            const pxPerMinute = parseFloat(panels.dataset.pxPerMinute);
            let nowMinute = parseFloat(panels.dataset.nowMinute);
            let lastTimelineHtml = document.getElementById('andon-panel-timeline').innerHTML;
            let lastKoseiHtml = document.getElementById('andon-panel-kosei').innerHTML;
            let lastStockHtml = document.getElementById('andon-panel-stock').innerHTML;
            let lastPlanningHtml = document.getElementById('andon-panel-planning').innerHTML;

            // Auto-follow pauses for a while after a manual scroll/touch/drag,
            // otherwise the per-second nudge below would fight the user and
            // snap straight back — it resumes on its own once they stop.
            const FOLLOW_PAUSE_MS = 15000;
            let userInteractedAt = 0;
            const isFollowPaused = () => Date.now() - userInteractedAt < FOLLOW_PAUSE_MS;

            ['wheel', 'touchstart', 'pointerdown'].forEach((eventName) => {
                panels.addEventListener(eventName, () => { userInteractedAt = Date.now(); }, { passive: true });
            });

            // Pattern and Planning no longer auto-follow "now" — only Kesei
            // (horizontal) and Timeline Stok (vertical, jumps to the newest
            // row) still do. Always instant, never animated: an animated
            // scroll right after swapping innerHTML is what caused a visible
            // "blink" — the fresh element's scroll position resets to 0, and
            // animating back to target every 60s looked like the board twitching.
            function scrollToNow() {
                if (isFollowPaused()) return;

                const koseiScroll = document.querySelector('#andon-panel-kosei .andon-scroll');
                if (koseiScroll) koseiScroll.scrollLeft = Math.max(0, (nowMinute - dayStart) * pxPerMinute - 500);

                // Timeline Stok scrolls vertically (rows = time) — the latest
                // capture is always the newest row, so just jump to the bottom.
                const stockScroll = document.querySelector('#andon-panel-stock .andon-scroll');
                if (stockScroll) stockScroll.scrollTop = stockScroll.scrollHeight;
            }

            // Swapping innerHTML always resets that element's own scroll to 0
            // — so its previous position is always captured and restored
            // right after, regardless of auto-follow, instead of letting the
            // refresh yank it back to 0 out of nowhere. (Kesei/Stok still get
            // repositioned afterwards by scrollToNow() when not paused.)
            function replacePanel(id, html) {
                const panel = document.getElementById(id);

                const scrollEl = document.querySelector(`#${id} .andon-scroll`);
                const prevLeft = scrollEl ? scrollEl.scrollLeft : null;
                const prevTop = scrollEl ? scrollEl.scrollTop : null;

                panel.innerHTML = html;

                const freshScrollEl = document.querySelector(`#${id} .andon-scroll`);
                if (freshScrollEl && prevLeft !== null) freshScrollEl.scrollLeft = prevLeft;
                if (freshScrollEl && prevTop !== null) freshScrollEl.scrollTop = prevTop;

                return true;
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
                    // needless repaint/flicker on every single tick. If a
                    // panel is skipped (an actual-kanban input has focus),
                    // its "last" value is left as-is so the next tick retries.
                    if (data.timeline !== lastTimelineHtml && replacePanel('andon-panel-timeline', data.timeline)) {
                        lastTimelineHtml = data.timeline;
                    }
                    if (data.kosei !== lastKoseiHtml && replacePanel('andon-panel-kosei', data.kosei)) {
                        lastKoseiHtml = data.kosei;
                    }
                    if (data.stockTimeline !== lastStockHtml && replacePanel('andon-panel-stock', data.stockTimeline)) {
                        lastStockHtml = data.stockTimeline;
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
