@props([
    'title',
    'badge' => null,
    'open' => false,
])

{{--
    A single Cleopatra collapsible section. Collapse is a pure CSS
    grid-rows 0fr → 1fr transition; the vanilla-JS handler on the page
    only toggles the `is-open` class, the `aria-expanded` state and the
    chevron. Inputs inside stay in the DOM (and submit) while collapsed.
--}}
<div {{ $attributes->class('overflow-hidden rounded-xl border border-line bg-surface') }} data-accordion>
    <button
        type="button"
        data-accordion-trigger
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        class="flex w-full items-center justify-between gap-3 px-3.5 py-2.5 text-left transition hover:bg-ink/5"
    >
        <span class="flex items-center gap-2">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-ink">{{ $title }}</span>
            @if ($badge !== null && $badge !== '')
                <span class="inline-flex items-center rounded-full bg-accent-soft px-1.5 py-0.5 text-[10px] font-medium text-accent">
                    {{ $badge }}
                </span>
            @endif
        </span>
        <svg
            data-accordion-chevron
            class="h-4 w-4 shrink-0 text-ink-subtle transition-transform duration-200 {{ $open ? 'rotate-180' : '' }}"
            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
        >
            <path d="m6 9 6 6 6-6"/>
        </svg>
    </button>

    <div data-accordion-body class="{{ $open ? 'is-open' : '' }}">
        <div>
            <div class="border-t border-line px-3.5 py-3">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
