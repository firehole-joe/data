@props([
    'variant' => 'info',
    'dismissible' => false,
    'title' => null,
])

@php
    $variants = [
        'info' => 'bg-sky-500/10 border-sky-500/30 text-sky-800 dark:text-sky-200',
        'success' => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-800 dark:text-emerald-200',
        'warning' => 'bg-amber-500/10 border-amber-500/30 text-amber-900 dark:text-amber-200',
        'danger' => 'bg-red-500/10 border-red-500/30 text-red-800 dark:text-red-200',
    ];
    $variantClass = $variants[$variant] ?? $variants['info'];
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
