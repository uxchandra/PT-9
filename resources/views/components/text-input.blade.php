@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition']) }}>
