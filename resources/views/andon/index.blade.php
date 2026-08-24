<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Andon — {{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased bg-slate-100">
    <div class="min-h-screen flex flex-col items-center justify-center px-6 py-16">
        <h1 class="text-3xl sm:text-4xl font-bold text-slate-800 tracking-wide mb-2">ANDON BOARD</h1>
        <p class="text-slate-500 mb-10">{{ __('Pilih pattern board untuk melihat status produksi live') }}</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 w-full max-w-4xl">
            @forelse ($patternBoards as $board)
                <a href="{{ route('andon.show', $board) }}"
                   class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 hover:border-brand-400 hover:shadow-md transition-all duration-200 shadow-sm">
                    <p class="text-xs uppercase tracking-widest text-brand-700 font-semibold mb-2">Pattern Board</p>
                    <h2 class="text-2xl font-bold text-slate-800 mb-3">{{ $board->name }}</h2>
                    <p class="text-sm text-slate-500">{{ $board->patterns_count }} {{ __('assignment mesin') }}</p>
                </a>
            @empty
                <div class="col-span-full text-center text-slate-400 py-12">
                    {{ __('Belum ada pattern board yang bisa ditampilkan.') }}
                </div>
            @endforelse
        </div>
    </div>
</body>
</html>
