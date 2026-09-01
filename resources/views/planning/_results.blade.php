<p class="px-6 pt-4 text-sm text-gray-500">
    {{ $planningRows->total() }} {{ __('assignment ditemukan') }}
</p>

<div class="mx-6 my-6 overflow-x-auto border-2 border-gray-300 rounded-lg">
    <table class="min-w-full text-sm whitespace-nowrap border-separate border-spacing-0">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="px-6 py-3 border-b-2 border-gray-300">{{ __('Machine') }}</th>
                <th class="px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Part / Proses') }}</th>
                <th class="px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Shift') }}</th>
                <th class="px-4 py-3 border-b-2 border-l border-gray-300 text-right">{{ __('Kanban Auto (Kesei)') }}</th>
                <th class="px-4 py-3 border-b-2 border-l border-gray-300 text-right">{{ __('Kanban Override') }}</th>
                <th class="px-4 py-3 border-b-2 border-l border-gray-300 text-right">{{ __('Kanban Efektif') }}</th>
                <th class="px-6 py-3 border-b-2 border-l border-gray-300 text-right">{{ __('Actual Kanban') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($planningRows as $item)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 border-b border-gray-300 text-gray-800 font-medium">{{ $item->machine }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-600">{{ $item->label }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300">
                        @if ($item->shift)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold
                                         {{ $item->shift === 2 ? 'bg-indigo-50 text-indigo-700' : 'bg-amber-50 text-amber-700' }}">
                                {{ __('Shift') }} {{ $item->shift }}
                            </span>
                        @else
                            <span class="text-gray-400">-</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-right text-gray-500">{{ $item->kanban_auto }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-right">
                        <input type="number" min="0" inputmode="numeric"
                               class="planning-override-input w-20 rounded-lg border-gray-300 text-sm text-right focus:ring-brand-700 focus:border-brand-700"
                               placeholder="{{ __('Auto') }}"
                               value="{{ $item->kanban_override ?? '' }}"
                               data-pattern-id="{{ $item->pattern_id }}"
                               title="{{ __('Kosongkan untuk pakai hitungan otomatis dari Kesei') }}">
                    </td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-right font-semibold text-gray-800">
                        {{ $item->kanban }}{{ $item->kanban_override !== null ? '*' : '' }}
                    </td>
                    <td class="px-6 py-3 border-b border-l border-gray-300 text-right">
                        <input type="number" min="0" inputmode="numeric"
                               class="planning-actual-input w-20 rounded-lg border-gray-300 text-sm text-right focus:ring-brand-700 focus:border-brand-700"
                               placeholder="-"
                               value="{{ $item->actual_kanban ?? '' }}"
                               data-pattern-id="{{ $item->pattern_id }}">
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada assignment untuk board ini.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<p class="px-6 pb-2 text-xs text-gray-400">* {{ __('kanban override manual, menggantikan hitungan otomatis Kesei.') }}</p>

@if ($planningRows->hasPages())
    <div class="px-6 py-4 border-t border-gray-100">
        {{ $planningRows->links() }}
    </div>
@endif
