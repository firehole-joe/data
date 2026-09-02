@props([
    'variant' => 'info',
    'fill' => 'soft',
    'dismissible' => false,
    'title' => null,
])

@php
    $palettes = [
        'info' => ['soft' => 'bg-sky-500/10 border-sky-500/30 text-sky-800 dark:text-sky-200', 'solid' => 'bg-sky-600 border-sky-600 text-white'],
        'success' => ['soft' => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-800 dark:text-emerald-200', 'solid' => 'bg-emerald-600 border-emerald-600 text-white'],
        'warning' => ['soft' => 'bg-amber-500/10 border-amber-500/30 text-amber-900 dark:text-amber-200', 'solid' => 'bg-amber-600 border-amber-600 text-white'],
        'danger' => ['soft' => 'bg-red-500/10 border-red-500/30 text-red-800 dark:text-red-200', 'solid' => 'bg-red-600 border-red-600 text-white'],
    ];
    $palette = $palettes[$variant] ?? $palettes['info'];
    $variantClass = $palette[$fill] ?? $palette['soft'];
@endphp

<div
    data-alert
    role="alert"
    {{ $attributes->class(['relative rounded-lg border px-3.5 py-2.5 text-[13px] leading-relaxed', $variantClass]) }}
>
    @if ($title)
        <p class="mb-0.5 font-semibold">{{ $title }}</p>
    @endif

    <div class="[&_a]:font-medium [&_a]:underline {{ $dismissible ? 'pr-6' : '' }}">
        {{ $slot }}
    </div>

    @if ($dismissible)
        <button
            type="button"
            aria-label="Dismiss"
            onclick="this.closest('[data-alert]').remove()"
            class="absolute right-2 top-2 grid h-5 w-5 place-items-center rounded text-base leading-none opacity-60 transition hover:opacity-100"
        >&times;</button>
    @endif
</div>
