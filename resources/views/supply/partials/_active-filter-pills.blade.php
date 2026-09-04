@php
    /**
     * @var array<string,mixed> $filters
     * @var \Illuminate\Support\Collection $distributors  Facet distributor list (id, name).
     */
    $distributorNames = collect($distributors)->keyBy('id');

    $pills = [];

    foreach ($filters['distributor_ids'] as $id) {
        $label = optional($distributorNames->get($id))->name ?? "Distributor #{$id}";
        $prefix = $filters['distributor_mode'] === 'exclude' ? 'Exclude: ' : '';
        $pills[] = ['name' => 'distributors', 'value' => $id, 'label' => $prefix.$label];
    }
    foreach ($filters['calibers'] as $value) {
        $pills[] = ['name' => 'calibers', 'value' => $value, 'label' => $value];
    }
    foreach ($filters['projectile_types'] as $value) {
        $pills[] = ['name' => 'projectile_types', 'value' => $value, 'label' => $value];
    }
    foreach ($filters['grain_weights'] as $value) {
        $pills[] = ['name' => 'grain_weights', 'value' => $value, 'label' => $value.' gr'];
    }
    if ($filters['stock_status'] !== 'all') {
        $labels = ['in_stock' => 'In stock only', 'out_of_stock' => 'Out of stock'];
        $pills[] = ['name' => 'stock_status', 'value' => 'all', 'label' => $labels[$filters['stock_status']] ?? $filters['stock_status']];
    }
    if (($filters['review'] ?? 'all') !== 'all') {
        $labels = ['flagged' => 'Flagged for review', 'clean' => 'Passed review'];
        $pills[] = ['name' => 'review', 'value' => 'all', 'label' => $labels[$filters['review']] ?? $filters['review']];
    }
    if (($filters['packaging'] ?? 'all') !== 'all') {
        $labels = ['standard' => 'Standard boxes (≤ 50)', 'bulk' => 'Bulk / cases (≥ 100)'];
        $packLabel = $labels[$filters['packaging']] ?? (ctype_digit((string) $filters['packaging'])
            ? ((int) $filters['packaging'] >= 1000 ? '1000+ rounds' : $filters['packaging'].' rounds')
            : $filters['packaging']);
        $pills[] = ['name' => 'packaging', 'value' => 'all', 'label' => $packLabel];
    }
    if ($filters['min_qty'] > 0) {
        $pills[] = ['name' => 'min_qty', 'value' => '', 'label' => 'Qty ≥ '.number_format($filters['min_qty'])];
    }
    if ($filters['search'] !== '') {
        $pills[] = ['name' => 'search', 'value' => '', 'label' => 'Search: “'.\Illuminate\Support\Str::limit($filters['search'], 24).'”'];
    }
@endphp

@if ($pills)
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Active</span>

        @foreach ($pills as $pill)
            <button
                type="button"
                data-pill-remove
                data-pill-name="{{ $pill['name'] }}"
                data-pill-value="{{ $pill['value'] }}"
                class="inline-flex items-center gap-1 rounded-full border border-accent/30 bg-accent-soft px-2 py-0.5 text-[11px] font-medium text-accent transition hover:bg-accent hover:text-accent-fg"
            >
                {{ $pill['label'] }}
                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        @endforeach

        <a
            href="{{ route('supply.dashboard', ['reset' => 1]) }}"
            data-reset-filters
            class="ml-1 text-[11px] font-medium text-ink-muted underline decoration-dotted underline-offset-2 transition hover:text-ink"
        >
            Reset All Filters
        </a>
    </div>
@endif
