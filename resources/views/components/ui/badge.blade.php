@props([
    'variant' => 'default',
    'dot' => false,
    'size' => 'md',
])

@php
    $variants = [
        'default' => 'bg-accent-soft text-accent border-accent/25',
        'success' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/25',
        'warning' => 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/25',
        'danger' => 'bg-red-500/10 text-red-700 dark:text-red-300 border-red-500/25',
        'neutral' => 'bg-ink/5 text-ink-muted border-line',
    ];

    $dots = [
        'default' => 'bg-accent',
        'success' => 'bg-emerald-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-red-500',
        'neutral' => 'bg-ink-subtle',
    ];

    $sizes = [
        'sm' => 'text-[10px] px-1.5 py-[1px] gap-1',
        'md' => 'text-[11px] px-2 py-0.5 gap-1.5',
    ];

    $variantClass = $variants[$variant] ?? $variants['default'];
    $dotClass = $dots[$variant] ?? $dots['default'];
    $sizeClass = $sizes[$size] ?? $sizes['md'];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center whitespace-nowrap rounded-full border font-medium uppercase tracking-wide',
    $variantClass,
    $sizeClass,
]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotClass }}"></span>
    @endif
    {{ $slot }}
</span>
