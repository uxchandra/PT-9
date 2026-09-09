<x-app-layout>
    <x-slot name="header">
        {{ __('Calendar') }}
    </x-slot>

    @php
        $weekdays = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
    @endphp

    <div class="p-4 sm:p-6 lg:p-8"
         x-data="calendarPage({
            boards: {{ Illuminate\Support\Js::from($patternBoards) }},
            hasErrors: @js($errors->any()),
            old: {
                date: @js(old('date')),
                pattern_board_id: @js(old('pattern_board_id')),
            },
         })">

        {{-- Hero --}}
        <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
            <h1 class="text-2xl font-bold text-gray-900">{{ __('Calendar') }}</h1>
        </div>

        @if (session('status'))
            <div class="mb-6 flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 font-medium text-sm text-green-700">
                <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Full-width calendar --}}
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-4 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <a href="{{ route('calendar.index', ['month' => $prevMonth]) }}"
                   class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h2 class="text-base font-bold text-gray-800">{{ $monthLabel }}</h2>
                <a href="{{ route('calendar.index', ['month' => $nextMonth]) }}"
                   class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            <div class="border-2 border-gray-900 rounded-lg overflow-hidden">
                <div class="grid grid-cols-7 text-center text-xs font-semibold uppercase tracking-wide text-gray-600 bg-gray-50 border-b-2 border-gray-900">
                    @foreach ($weekdays as $i => $wd)
                        <div class="py-2 {{ $i < 6 ? 'border-r border-gray-700' : '' }} {{ $i >= 5 ? 'text-red-500' : '' }}">{{ $wd }}</div>
                    @endforeach
                </div>

                <div class="grid grid-cols-7">
                    @foreach ($days as $day)
                        @php
                            if ($day['is_today']) {
                                $cellBg = 'bg-green-200';
                            } elseif ($day['is_weekend']) {
                                $cellBg = 'bg-red-100';
                            } else {
                                $cellBg = $day['day'] % 2 === 0 ? 'bg-amber-50' : 'bg-amber-100';
                            }
                            if (! $day['in_month']) {
                                $cellBg .= ' opacity-40';
                            }
                        @endphp
                        <div @click="openDay(@js($day))"
                             class="flex flex-col min-h-[110px] p-2 cursor-pointer transition hover:brightness-95 border-gray-700 {{ $cellBg }}
                                    {{ $loop->iteration % 7 !== 0 ? 'border-r' : '' }}
                                    {{ $loop->iteration <= count($days) - 7 ? 'border-b' : '' }}">
                            <div class="flex justify-start">
                                <span class="inline-flex items-center justify-center w-6 h-6 text-xs font-semibold rounded-full
                                    {{ $day['is_today'] ? 'bg-brand-800 text-white' : ($day['in_month'] ? ($day['is_weekend'] ? 'text-red-500' : 'text-gray-700') : 'text-gray-300') }}">
                                    {{ $day['day'] }}
                                </span>
                            </div>
                            @if ($day['entry'])
                                <div class="flex-1 flex items-center justify-center">
                                    <span class="text-3xl sm:text-4xl font-extrabold text-brand-800">
                                        {{ $day['entry']['board_name'] ?? '—' }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Assign modal --}}
        <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 overflow-y-auto" style="display:none;">
            <div class="fixed inset-0 bg-gray-500/75" @click="closeModal()"></div>
            <div class="relative min-h-full flex items-start justify-center p-4 sm:p-6">
                <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl mt-16">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-800" x-text="fmt(selected.date)"></h3>
                        <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="{{ route('calendar.store') }}" method="POST" class="px-6 py-5 space-y-4">
                        @csrf
                        <input type="hidden" name="date" :value="selected.date">

                        <div>
                            <x-input-label :value="__('Pattern yang jalan')" />
                            <select name="pattern_board_id" x-model="selected.boardId"
                                    class="block w-full mt-1 border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm text-sm">
                                <option value="">— pilih pattern —</option>
                                <template x-for="board in boards" :key="board.id">
                                    <option :value="board.id" x-text="'Pattern ' + board.name"></option>
                                </template>
                            </select>
                            <p x-show="boards.length === 0" class="mt-1 text-xs text-red-600">
                                Belum ada pattern board. Buat dulu di menu Pattern.
                            </p>
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <button type="button" x-show="selected.entryId" @click="destroyEntry()"
                                    class="text-sm font-medium text-red-600 hover:text-red-800">{{ __('Hapus') }}</button>
                            <div class="flex items-center gap-3 ml-auto">
                                <button type="button" @click="closeModal()" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</button>
                                <button type="submit"
                                        class="px-4 py-2 bg-brand-800 rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition shadow-sm">
                                    {{ __('Simpan') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Standalone delete form (submitted from the modal's Hapus button) --}}
        <form x-ref="deleteForm" method="POST" class="hidden">
            @csrf
            <input type="hidden" name="_method" value="DELETE">
            <input type="hidden" name="month" value="{{ $monthValue }}">
        </form>
    </div>

    <script>
        function calendarPage(config) {
            return {
                boards: config.boards,
                modalOpen: false,
                selected: { date: null, entryId: null, boardId: '' },

                init() {
                    // A rejected save redirects back here; reopen the modal on the
                    // same date so the pick isn't lost.
                    if (config.hasErrors && config.old.date) {
                        this.selected = {
                            date: config.old.date,
                            entryId: null,
                            boardId: config.old.pattern_board_id || '',
                        };
                        this.modalOpen = true;
                    }
                },

                fmt(dateStr) {
                    if (!dateStr) return '';
                    try {
                        return new Date(dateStr + 'T00:00:00').toLocaleDateString('id-ID', {
                            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
                        });
                    } catch (e) {
                        return dateStr;
                    }
                },

                openDay(day) {
                    this.selected = {
                        date: day.date,
                        entryId: day.entry ? day.entry.id : null,
                        boardId: day.entry
                            ? String(day.entry.pattern_board_id)
                            : (this.boards.length ? String(this.boards[0].id) : ''),
                    };
                    this.modalOpen = true;
                },

                closeModal() {
                    this.modalOpen = false;
                },

                destroyEntry() {
                    if (!this.selected.entryId || !confirm('Hapus pattern pada tanggal ini?')) return;
                    const f = this.$refs.deleteForm;
                    f.setAttribute('action', '{{ url('calendar') }}/' + this.selected.entryId);
                    f.submit();
                },
            };
        }
    </script>
</x-app-layout>
