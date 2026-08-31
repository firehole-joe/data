@props([
    'align' => 'left',
    'numeric' => false,
])

@php
    $align = $numeric ? 'right' : $align;
    $alignClass = [
        'left' => 'text-left',
        'right' => 'text-right',
        'center' => 'text-center',
    ][$align] ?? 'text-left';
@endphp

<th {{ $attributes->class([
    'whitespace-nowrap border-b border-line bg-surface-sunken px-3 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle',
    $alignClass,
]) }}>
    {{ $slot }}
</th>
