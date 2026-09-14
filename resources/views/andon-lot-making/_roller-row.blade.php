@php
    $planning = $cycle->planning;
    // Not assigned yet — list every machine this part is registered against
    // (Assignment Machine) instead of leaving it blank, so the floor can see
    // where it's likely headed.
    $machineNames = $planning?->machine?->name
        ?? (($assignmentMachinesByPartNo ?? collect())->get($cycle->part_no) ?? collect())->implode(', ');
@endphp
<div class="border-t border-white py-2">
    <div class="flex items-center justify-between gap-2">
        <span class="text-sm font-bold text-white">{{ $cycle->part_no }}</span>
        <span class="text-sm text-slate-300">{{ __('Lot') }} {{ $cycle->lot_produksi }}</span>
    </div>
    {{-- Status itself is no longer shown here — which section (Open vs In
         Progress) this row is in already says that. --}}
    <div class="flex items-center justify-between gap-2 mt-0.5">
        <p class="text-xs text-slate-400">{{ $cycle->completed_at->format('d/m/Y H:i') }}</p>
        <p class="text-xs font-semibold text-slate-200">{{ $machineNames }}</p>
    </div>
</div>
