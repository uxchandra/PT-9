@extends('layouts.scanner')

@section('title', $label)
@section('heading', strtoupper($label))

@section('content')
    <a href="{{ route('scanner.dashboard') }}"
       class="tap mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" />
        </svg>
        {{ __('Kembali') }}
    </a>

    <form id="scan-form" autocomplete="off" class="mb-4">
        <label for="scan-input" class="mb-1 block text-xs font-semibold uppercase tracking-widest text-slate-400">
            {{ __('Scan barcode') }}
        </label>
        <input id="scan-input" name="code" type="text" inputmode="text"
               autocapitalize="off" autocorrect="off" spellcheck="false" autofocus
               placeholder="{{ __('Arahkan scanner…') }}"
               class="w-full rounded-xl border-2 border-slate-300 bg-white px-4 py-4 text-xl font-mono tracking-wider focus:border-slate-900 focus:ring-0">
    </form>

    <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-slate-500">
            {{ __('Ter-scan') }}: <span id="scan-count" class="font-bold text-slate-900">0</span>
        </p>
        <button id="scan-clear" type="button"
                class="tap rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
            {{ __('Hapus') }}
        </button>
    </div>

    <ul id="scan-list" class="mt-3 space-y-2"></ul>
@endsection

@push('scripts')
<script>
    (function () {
        var slug = @json($slug);
        var key = 'scanner:' + slug;
        var input = document.getElementById('scan-input');
        var form = document.getElementById('scan-form');
        var list = document.getElementById('scan-list');
        var count = document.getElementById('scan-count');

        function load() {
            try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { return []; }
        }
        function save(rows) {
            try { localStorage.setItem(key, JSON.stringify(rows)); } catch (e) {}
        }
        function render(rows) {
            count.textContent = rows.length;
            list.innerHTML = rows.map(function (r) {
                return '<li class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2.5">' +
                    '<span class="font-mono text-sm tracking-wider text-slate-800">' + r.code + '</span>' +
                    '<span class="text-[11px] text-slate-400">' + r.at + '</span></li>';
            }).join('');
        }

        var rows = load();
        render(rows);

        function add(raw) {
            var code = (raw || '').trim();
            if (!code) return;
            rows.unshift({ code: code, at: new Date().toTimeString().slice(0, 8) });
            save(rows);
            render(rows);
        }

        // Hardware scanner acts as a keyboard wedge: it types the code then Enter.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            add(input.value);
            input.value = '';
            input.focus();
        });

        // Keep focus on the input so every scan is captured.
        input.addEventListener('blur', function () {
            setTimeout(function () { input.focus(); }, 50);
        });

        document.getElementById('scan-clear').addEventListener('click', function () {
            if (!rows.length || !confirm(@json(__('Hapus semua hasil scan?')))) return;
            rows = [];
            save(rows);
            render(rows);
            input.focus();
        });
    })();
</script>
@endpush
