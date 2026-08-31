@props([
    'title',
    'value',
    'subtext' => null,
    'trend' => null,
    'icon' => null,
])

@php
    $trends = [
        'up' => ['text-emerald-600 dark:text-emerald-400', '▲'],
        'down' => ['text-red-600 dark:text-red-400', '▼'],
        'neutral' => ['text-ink-subtle', '■'],
    ];
    [$trendClass, $trendGlyph] = $trends[$trend] ?? [null, null];
@endphp

<div {{ $attributes->class('flex flex-col gap-1.5 rounded-xl border border-line bg-surface p-3.5') }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">{{ $title }}</span>
        @if ($icon)
            <span class="text-ink-subtle">{!! $icon !!}</span>
        @endif
    </div>

    <div class="flex items-baseline gap-2">
        <span class="text-xl font-semibold tabular-nums leading-none text-ink">{{ $value }}</span>
        @if ($trendClass)
            <span class="text-[11px] font-medium {{ $trendClass }}">{{ $trendGlyph }}</span>
        @endif
    </div>

    @if ($subtext)
        <span class="text-[11px] leading-tight text-ink-muted">{{ $subtext }}</span>
    @endif
</div>
