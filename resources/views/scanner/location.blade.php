@extends('layouts.scanner')

@section('title', $label)
@section('heading', strtoupper($label))

@section('content')
    <div class="mb-3 flex items-center justify-between gap-2">
        <a href="{{ route('scanner.dashboard') }}"
           class="tap inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" />
            </svg>
            {{ __('Kembali') }}
        </a>
        @unless ($free)
            <span class="text-[11px] whitespace-nowrap text-slate-400">
                {{ __('last update') }} : <span id="last-update-val" class="font-semibold text-slate-500">{{ $lastUpdate ?? '—' }}</span>
            </span>
        @endunless
    </div>

    <form id="scan-form" autocomplete="off" class="mb-2">
        <label for="scan-input" class="mb-1 block text-xs font-semibold uppercase tracking-widest text-slate-400">
            {{ __('Scan label SOS') }}
        </label>
        {{-- inputmode="none": the hardware wedge still types + Enter, but Android
             keeps its on-screen keyboard hidden. --}}
        <input id="scan-input" name="code" type="text" inputmode="none"
               autocapitalize="off" autocorrect="off" spellcheck="false" autofocus
               placeholder="{{ __('Arahkan scanner…') }}"
               class="w-full rounded-xl border-2 border-slate-300 bg-white px-4 py-4 text-xl font-mono tracking-wider focus:border-slate-900 focus:ring-0">
    </form>

    <div id="scan-flash" class="mb-3 hidden rounded-xl px-4 py-3 text-sm font-semibold"></div>

    <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-400">
        {{ $free ? __('Part Store 3') : __('Perintah pulling') }}
    </p>

    @include('scanner._pull-list')
@endsection

@push('scripts')
<script>
    (function () {
        var scanUrl = @json(route('scanner.scan', $slug));
        var listUrl = @json(route('scanner.location', $slug));
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        var input = document.getElementById('scan-input');
        var form = document.getElementById('scan-form');
        var flash = document.getElementById('scan-flash');
        var busy = false;

        function showFlash(ok, msg) {
            flash.textContent = msg;
            flash.className = 'mb-3 rounded-xl px-4 py-3 text-sm font-semibold ' +
                (ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
            if (navigator.vibrate) navigator.vibrate(ok ? 40 : [60, 40, 60]);
        }

        // Optimistic: bump the just-scanned row until the next poll rebuilds it.
        function bumpRow(partNo, scanned, needed) {
            var list = document.getElementById('pull-list');
            var li = list && list.querySelector('li[data-part="' + (window.CSS && CSS.escape ? CSS.escape(partNo) : partNo) + '"]');
            if (!li) return;
            var done = needed != null && scanned >= needed;
            li.querySelectorAll('.js-scanned').forEach(function (el) {
                el.textContent = scanned;
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var code = input.value.trim();
            input.value = '';
            input.focus();
            if (!code || busy) return;
            busy = true;

            fetch(scanUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ code: code }),
            })
                .then(function (r) {
                    // Session died mid-shift — go straight to the login screen,
                    // never leave a stale "ditolak" flash on a dead page.
                    if (r.status === 419 || r.status === 401 || (r.redirected && /\/login/.test(r.url))) {
                        window.location.href = @json(route('login'));
                        return null;
                    }
                    return r.json();
                })
                .then(function (d) {
                    if (!d) return;
                    if (d.ok) {
                        var label = d.needed == null
                            ? d.part_no + '  ✓ ' + d.scanned
                            : d.part_no + '  ' + d.scanned + ' / ' + d.needed;
                        showFlash(true, label);
                        bumpRow(d.part_no, d.scanned, d.needed);
                    } else {
                        showFlash(false, d.reason || 'Scan ditolak.');
                    }
                })
                .catch(function () { showFlash(false, 'Gagal — koneksi.'); })
                .finally(function () { busy = false; input.focus(); });
        });

        // Keep the field focused so every scan is captured by the wedge.
        input.addEventListener('blur', function () {
            setTimeout(function () { input.focus(); }, 50);
        });

        // Re-pull the list every 20s so the 15-minute reset shows up live.
        setInterval(function () {
            if (busy) return;
            fetch(listUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (r.status === 419 || r.status === 401 || (r.redirected && /\/login/.test(r.url))) {
                        window.location.href = @json(route('login'));
                        return null;
                    }
                    return r.text();
                })
                .then(function (html) {
                    if (html == null) return;
                    var cur = document.getElementById('pull-list');
                    if (cur && html.trim()) cur.outerHTML = html;
                    var fresh = document.getElementById('pull-list');
                    var lu = document.getElementById('last-update-val');
                    if (fresh && lu) lu.textContent = fresh.dataset.lastUpdate || '—';
                })
                .catch(function () {});
        }, 20000);
    })();
</script>
@endpush
