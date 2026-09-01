@php
    /**
     * Cascading facet chip group.
     *
     * @var string             $name       Form field name (without the [] suffix).
     * @var array<array-key,int> $available Value => distinct-SKU count from the cascade.
     * @var array<int,mixed>    $selected   Currently selected values.
     * @var string              $unit       Suffix appended to each chip label (e.g. " gr").
     */
    $unit = $unit ?? '';
    $selectedKeys = array_map('strval', $selected);

    // Merge in any selected value the cascade no longer offers so the user
    // can still see (and clear) it; unavailable values sort to the end.
    $rows = $available;
    foreach ($selected as $value) {
        if (! array_key_exists($value, $rows) && ! array_key_exists((string) $value, $rows)) {
            $rows[$value] = 0;
        }
    }
@endphp

<div class="flex flex-wrap gap-1.5">
    @forelse ($rows as $value => $count)
        @php $on = in_array((string) $value, $selectedKeys, true); @endphp
        <label class="cursor-pointer select-none">
            <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" @checked($on) class="peer sr-only" data-autosubmit>
            <span @class([
                'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-medium tabular-nums transition',
                'peer-checked:border-accent peer-checked:bg-accent peer-checked:text-accent-fg',
                'border-line text-ink-muted hover:bg-ink/5 hover:text-ink' => ! $on && $count > 0,
                'border-line/50 text-ink-subtle/60' => ! $on && $count === 0,
            ])>
                {{ $value }}{{ $unit }}
                <span class="rounded-full bg-black/10 px-1 text-[9px] tabular-nums dark:bg-white/15">{{ $count }}</span>
            </span>
        </label>
    @empty
        <span class="text-[11px] text-ink-subtle">No options match the current selection.</span>
    @endforelse
</div>
