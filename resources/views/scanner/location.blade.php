@extends('layouts.scanner')

@section('title', $label)
@section('heading', strtoupper($label))

@section('content')
    <a href="{{ route('scanner.dashboard') }}"
       class="tap mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" />
        </svg>
        {{ __('Kembali') }}
    </a>

    <form id="scan-form" autocomplete="off" class="mb-2">
        <label for="scan-input" class="mb-1 block text-xs font-semibold uppercase tracking-widest text-slate-400">
            {{ __('Scan label SOS') }}
        </label>
        <input id="scan-input" name="code" type="text" inputmode="text"
               autocapitalize="off" autocorrect="off" spellcheck="false" autofocus
               placeholder="{{ __('Arahkan scanner…') }}"
               class="w-full rounded-xl border-2 border-slate-300 bg-white px-4 py-4 text-xl font-mono tracking-wider focus:border-slate-900 focus:ring-0">
    </form>

    <div id="scan-flash" class="mb-3 hidden rounded-xl px-4 py-3 text-sm font-semibold"></div>

    <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-400">
        {{ $free ? __('Part Store 3') : __('Perintah pulling') }}
    </p>

    <ul id="pull-list" class="space-y-2">
        @forelse ($rows as $row)
            <li class="rounded-xl border border-slate-200 bg-white p-3" data-part="{{ $row['part_no'] }}">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="font-mono text-base font-bold tracking-wide text-slate-900">{{ $row['part_no'] }}</span>
                    <span class="text-sm">
                        <span class="js-scanned font-extrabold {{ $row['done'] ? 'text-green-600' : 'text-slate-900' }}">{{ $row['scanned'] }}</span>
                        @if (! $free)
                            <span class="text-slate-400">/ {{ $row['needed'] }}</span>
                        @else
                            <span class="text-slate-400">{{ __('scan') }}</span>
                        @endif
                    </span>
                </div>
                @unless ($free)
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="js-bar h-full rounded-full {{ $row['done'] ? 'bg-green-500' : 'bg-slate-800' }}"
                             style="width: {{ $row['needed'] ? min(100, round($row['scanned'] / $row['needed'] * 100)) : 0 }}%"></div>
                    </div>
                @endunless
            </li>
        @empty
            <li class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-400">
                {{ $free ? __('Belum ada part untuk lokasi ini.') : __('Belum ada demand untuk lokasi ini.') }}
            </li>
        @endforelse
    </ul>
@endsection

@push('scripts')
<script>
    (function () {
        var url = @json(route('scanner.scan', $slug));
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        var input = document.getElementById('scan-input');
        var form = document.getElementById('scan-form');
        var flash = document.getElementById('scan-flash');
        var list = document.getElementById('pull-list');
        var busy = false;

        function showFlash(ok, msg) {
            flash.textContent = msg;
            flash.className = 'mb-3 rounded-xl px-4 py-3 text-sm font-semibold ' +
                (ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
            if (navigator.vibrate) navigator.vibrate(ok ? 40 : [60, 40, 60]);
        }

        function updateRow(partNo, scanned, needed) {
            var li = list.querySelector('li[data-part="' + (window.CSS && CSS.escape ? CSS.escape(partNo) : partNo) + '"]');
            if (!li) return;
            var done = needed != null && scanned >= needed;
            var el = li.querySelector('.js-scanned');
            el.textContent = scanned;
            el.className = 'js-scanned font-extrabold ' + (done ? 'text-green-600' : 'text-slate-900');
            var bar = li.querySelector('.js-bar');
            if (bar && needed != null) {
                bar.style.width = (needed ? Math.min(100, Math.round(scanned / needed * 100)) : 0) + '%';
                bar.className = 'js-bar h-full rounded-full ' + (done ? 'bg-green-500' : 'bg-slate-800');
            }
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var code = input.value.trim();
            input.value = '';
            input.focus();
            if (!code || busy) return;
            busy = true;

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ code: code }),
            })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
                .then(function (res) {
                    if (res.d.ok) {
                        var label = res.d.needed == null
                            ? res.d.part_no + '  ✓ ' + res.d.scanned
                            : res.d.part_no + '  ' + res.d.scanned + ' / ' + res.d.needed;
                        showFlash(true, label);
                        updateRow(res.d.part_no, res.d.scanned, res.d.needed);
                    } else {
                        showFlash(false, res.d.reason || 'Scan ditolak.');
                    }
                })
                .catch(function () { showFlash(false, 'Gagal — koneksi.'); })
                .finally(function () { busy = false; input.focus(); });
        });

        // Keep focus so every scan is captured by the wedge.
        input.addEventListener('blur', function () {
            setTimeout(function () { input.focus(); }, 50);
        });
    })();
</script>
@endpush
