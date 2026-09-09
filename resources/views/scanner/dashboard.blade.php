@extends('layouts.scanner')

@section('title', 'KESEI KANBAN')
@section('heading', 'KESEI KANBAN')

@section('content')
    <p class="mb-4 text-center text-sm font-medium text-slate-500">{{ __('Pilih lokasi scan') }}</p>

    <div class="space-y-4">
        @foreach ($locations as $slug => $name)
            <a href="{{ route('scanner.location', $slug) }}"
               class="tap flex min-h-[128px] items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm active:shadow-none">
                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-slate-900 text-white">
                    <svg class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.5v15m3-15v15m3.75-15v15m3-15v15m3.75-15v15M18.75 4.5v15" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-2xl font-extrabold uppercase tracking-wide text-slate-900">{{ $name }}</span>
                    <span class="mt-0.5 block text-sm text-slate-400">{{ __('Ketuk untuk mulai scan') }}</span>
                </span>
                <svg class="ml-auto h-6 w-6 shrink-0 text-slate-300" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                </svg>
            </a>
        @endforeach
    </div>
@endsection
