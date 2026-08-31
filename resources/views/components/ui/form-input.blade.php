@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
    'id' => null,
])

@php
    $id = $id ?? $name ?? 'input-' . \Illuminate\Support\Str::random(6);
@endphp

<div class="relative">
    <input
        id="{{ $id }}"
        @if ($name) name="{{ $name }}" @endif
        type="{{ $type }}"
        value="{{ $value }}"
        placeholder=" "
        {{ $attributes->class('peer w-full rounded-lg border border-line bg-surface px-3 pb-1.5 pt-4 text-[13px] text-ink shadow-sm outline-none transition placeholder:text-transparent focus:border-accent focus:ring-2 focus:ring-accent/30 dark:bg-surface-2') }}
    />

    @if ($label)
        <label
            for="{{ $id }}"
            class="pointer-events-none absolute left-3 top-1.5 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle transition-all
                   peer-placeholder-shown:top-2.5 peer-placeholder-shown:text-[13px] peer-placeholder-shown:font-normal peer-placeholder-shown:normal-case peer-placeholder-shown:tracking-normal
                   peer-focus:top-1.5 peer-focus:text-[10px] peer-focus:font-semibold peer-focus:uppercase peer-focus:tracking-wider peer-focus:text-accent"
        >
            {{ $label }}
        </label>
    @endif
</div>
