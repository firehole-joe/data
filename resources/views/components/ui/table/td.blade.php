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

<td {{ $attributes->class([
    'border-b border-line/70 px-3 align-middle text-ink',
    $alignClass,
    'tabular-nums' => $numeric,
]) }}>
    {{ $slot }}
</td>
