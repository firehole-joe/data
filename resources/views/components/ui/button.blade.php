@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
])

@php
    $variants = [
        'primary' => 'bg-accent text-accent-fg border border-transparent shadow-sm hover:opacity-90',
        'secondary' => 'bg-surface-2 text-ink border border-line hover:bg-surface-sunken',
        'outline' => 'bg-transparent text-ink border border-line-strong hover:bg-ink/5',
        'ghost' => 'bg-transparent text-ink-muted border border-transparent hover:bg-ink/5 hover:text-ink',
        'danger' => 'bg-red-600 text-white border border-transparent shadow-sm hover:bg-red-500',
    ];

    $sizes = [
        'xs' => 'text-[11px] px-2 py-1 gap-1 rounded-md',
        'sm' => 'text-xs px-2.5 py-1.5 gap-1.5 rounded-md',
        'md' => 'text-[13px] px-3 py-1.5 gap-2 rounded-lg',
        'lg' => 'text-sm px-4 py-2.5 gap-2 rounded-lg',
    ];

    $classes = [
        'inline-flex items-center justify-center font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50 disabled:pointer-events-none disabled:opacity-50',
        $variants[$variant] ?? $variants['primary'],
        $sizes[$size] ?? $sizes['md'],
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
