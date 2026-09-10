<ul id="pull-list" class="space-y-2" data-last-update="{{ $lastUpdate ?? '' }}">
    @forelse ($rows as $row)
        <li class="rounded-xl border border-slate-200 bg-white p-3" data-part="{{ $row['part_no'] }}">
            <div class="flex items-baseline justify-between gap-2">
                <span class="font-mono text-base font-bold tracking-wide text-slate-900">{{ $row['part_no'] }}</span>
                @if ($free)
                    <span class="text-sm">
                        <span class="js-scanned font-extrabold text-slate-900">{{ $row['scanned'] }}</span>
                        <span class="text-slate-400">{{ __('scan') }}</span>
                    </span>
                @endif
            </div>

            @unless ($free)
                <div class="mt-2 flex items-center gap-2">
                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full {{ $row['done'] ? 'bg-green-500' : 'bg-slate-800' }}"
                             style="width: {{ $row['needed'] ? min(100, round($row['scanned'] / $row['needed'] * 100)) : 0 }}%"></div>
                    </div>
                    <span class="whitespace-nowrap text-sm">
                        <span class="js-scanned font-extrabold {{ $row['done'] ? 'text-green-600' : 'text-slate-900' }}">{{ $row['scanned'] }}</span>
                        <span class="text-slate-400">/ {{ $row['needed'] }}</span>
                    </span>
                </div>
            @endunless
        </li>
    @empty
        <li class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-400">
            {{ $free ? __('Belum ada part untuk lokasi ini.') : __('Belum ada demand untuk lokasi ini.') }}
        </li>
    @endforelse
</ul>
