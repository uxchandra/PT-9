@php
    $columns = [
        'part_no' => 'Part No',
        'part_no_fg' => 'Part No FG',
        'level' => 'Level',
        'customer_code' => 'Customer Code',
        'model' => 'Model',
        'job_no' => 'Job No',
        'part_name' => 'Part Name',
        'type_box' => 'Type Box',
        'qty_kbn' => 'Qty Kbn',
        'process' => 'Process',
        'line' => 'Line',
        'line_code' => 'Line Code',
        'rack_no' => 'Rack No',
        'cap_rack' => 'Cap Rack',
        'jig_no' => 'Jig No',
        'qty_lot' => 'Qty Lot',
        'stock_min' => 'Stock Min',
        'stock_max' => 'Stock Max',
        'image_name' => 'Image Name',
        'last_routing' => 'Last Routing',
        'remark' => 'Remark',
        'lt_pull' => 'Lt Pull',
        'lt_prod' => 'Lt Prod',
        'code_partset' => 'Code Partset',
        'set_label' => 'Set Label',
        'prod_point' => 'Prod Point',
        'cat_machine' => 'Cat Machine',
        'spm' => 'Spm',
        'dandory' => 'Dandory',
        'cek_startfinish' => 'Cek Start/Finish',
        'update_by' => 'Update By',
        'update_time' => 'Update Time',
    ];
@endphp

<p class="px-6 pt-4 text-sm text-gray-500">
    {{ $parts->total() }} {{ __('part terdaftar') }}
</p>

<div class="parts-table-scroll mx-6 my-6 overflow-x-auto overflow-y-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(100vh - 340px);">
    <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                @foreach ($columns as $label)
                    <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 {{ $loop->first ? '' : 'border-l' }} border-gray-300">{{ __($label) }}</th>
                @endforeach
                <th class="sticky top-0 right-0 z-20 bg-gray-50 px-4 py-3 border-b-2 border-l-2 border-gray-300 text-right">{{ __('Aksi') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($parts as $part)
                <tr class="group hover:bg-gray-50">
                    @foreach ($columns as $field => $label)
                        <td class="px-4 py-2 border-b {{ $loop->first ? '' : 'border-l' }} border-gray-200 {{ $loop->first ? 'font-semibold text-gray-800' : 'text-gray-600' }}">
                            {{ $part->{$field} ?? '-' }}
                        </td>
                    @endforeach
                    <td class="sticky right-0 z-10 bg-white group-hover:bg-gray-50 px-4 py-2 border-b border-l-2 border-gray-300 text-right">
                        <a href="{{ route('parts.edit', $part) }}" class="text-brand-700 hover:text-brand-900 font-medium">{{ __('Edit') }}</a>
                        <form action="{{ route('parts.destroy', $part) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus part ini?') }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ml-3 text-red-600 hover:text-red-800 font-medium">{{ __('Hapus') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) + 1 }}" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada data part.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($parts->hasPages())
    <div class="px-6 py-4 border-t border-gray-100">
        {{ $parts->links() }}
    </div>
@endif
