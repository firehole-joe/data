@extends('layouts.app')

@section('title', 'Ammunition Supply Dashboard')

@php
    use Illuminate\Support\Str;
    use App\Services\Ammunition\SupplyReportQueryService;

    $money = fn ($v, $dp = 2) => $v === null ? '—' : '$' . number_format((float) $v, $dp);

    $selectedCalibers = $filters['calibers'];
    $selectedProjectiles = $filters['projectile_types'];
    $selectedGrains = $filters['grain_weights'];
    $selectedDistributors = $filters['distributor_ids'];

    $hasActiveFilters = $selectedCalibers || $selectedProjectiles || $selectedGrains || $selectedDistributors
        || $filters['stock_status'] !== 'all' || $filters['min_qty'] > 0 || $filters['search'] !== ''
        || $filters['per_page'] !== SupplyReportQueryService::DEFAULT_PER_PAGE
        || $filters['sort_by'] !== 'manufacturer' || $filters['sort_dir'] !== 'asc';

    // Compact "what's selected" summary for an accordion header badge.
    $selectionBadge = function (array $selected, string $unit = ''): ?string {
        if (! $selected) {
            return null;
        }
        if (count($selected) <= 2) {
            return implode(', ', array_map(fn ($v) => $v . $unit, $selected));
        }
        return count($selected) . ' selected';
    };

    $distributorBadge = $selectedDistributors
        ? count($selectedDistributors) . ' ' . ($filters['distributor_mode'] === 'exclude' ? 'excluded' : 'selected')
        : null;
@endphp

@push('head')
<style>
    [data-accordion-body] { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .22s ease; }
    [data-accordion-body].is-open { grid-template-rows: 1fr; }
    [data-accordion-body] > div { overflow: hidden; min-height: 0; }
</style>
@endpush

@section('content')
<form id="supply-filters" method="GET" action="{{ route('supply.dashboard') }}" class="space-y-5">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Scope header --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-ink">Ammunition Supply Dashboard</h1>
            <p class="text-[12px] text-ink-muted">
                Active scope:
                <span class="font-medium text-ink">{{ $stats['scope_label'] }}</span>
                &middot; {{ number_format($stats['pipeline_rounds']) }} rounds across
                {{ number_format($stats['offer_count']) }} offering{{ $stats['offer_count'] === 1 ? '' : 's' }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" size="sm">Apply filters</x-ui.button>
            @if ($hasActiveFilters)
                <x-ui.button :href="route('supply.dashboard', ['reset' => 1])" variant="ghost" size="sm">Reset All Filters</x-ui.button>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Dynamic stat cards --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">

        {{-- In-Stock Health --}}
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-3.5">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">In-Stock Health</span>
            <div class="flex items-baseline gap-2">
                <span class="text-xl font-semibold tabular-nums leading-none text-ink">
                    {{ number_format($stats['in_stock_skus']) }}
                </span>
                <span class="text-[12px] text-ink-muted">/ {{ number_format($stats['total_skus']) }} SKUs</span>
                <span class="ml-auto text-[12px] font-medium text-accent tabular-nums">{{ $stats['in_stock_pct'] }}%</span>
            </div>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-sunken">
                <div class="h-full rounded-full bg-accent" style="width: {{ min(100, $stats['in_stock_pct']) }}%"></div>
            </div>
            <span class="text-[11px] leading-tight text-ink-muted">
                {{ number_format($stats['out_of_stock_skus']) }} out of stock in the active selection
            </span>
        </div>

        {{-- Total Pipeline Rounds --}}
        <div class="flex flex-col gap-1.5 rounded-xl border border-line bg-surface p-3.5">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Total Pipeline Rounds</span>
            <span class="text-xl font-semibold tabular-nums leading-none text-ink">
                {{ number_format($stats['pipeline_rounds']) }}
            </span>
            <span class="text-[11px] leading-tight text-ink-muted">
                {{ number_format($stats['pipeline_boxes']) }} boxes &middot; qty &times; rounds/box across matched offerings
            </span>
        </div>

        {{-- Price Spread --}}
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-3.5" data-price-spread>
            <div class="flex items-center justify-between gap-2">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Price Spread</span>
                <div class="inline-flex rounded-lg border border-line p-0.5 text-[10px] font-medium" role="group" aria-label="Price basis">
                    <button type="button" data-spread-mode="cpr" class="rounded-md px-1.5 py-0.5 transition">$ / Round</button>
                    <button type="button" data-spread-mode="box" class="rounded-md px-1.5 py-0.5 transition">$ / Box</button>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2 text-center">
                @foreach (['min' => 'Low', 'avg' => 'Avg', 'max' => 'High'] as $key => $label)
                    <div class="rounded-lg bg-surface-sunken px-1.5 py-2">
                        <div class="text-[9px] font-semibold uppercase tracking-wider text-ink-subtle">{{ $label }}</div>
                        <div
                            class="mt-0.5 text-[13px] font-semibold tabular-nums text-ink"
                            data-spread-cpr="{{ $stats['cpr'][$key] !== null ? $money($stats['cpr'][$key], 3) : '—' }}"
                            data-spread-box="{{ $stats['box_price'][$key] !== null ? $money($stats['box_price'][$key], 2) : '—' }}"
                        >{{ $stats['cpr'][$key] !== null ? $money($stats['cpr'][$key], 3) : '—' }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Primary toolbar (always visible) --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="space-y-3 rounded-xl border border-line bg-surface p-3.5">
        <div class="flex flex-wrap items-end gap-2">
            <label class="flex flex-1 flex-col gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle sm:min-w-[18rem]">
                Search UPC / SKU / MPN / Brand / Name
                <input type="text" name="search" value="{{ $filters['search'] }}" autocomplete="off"
                    class="rounded-lg border border-line bg-surface px-2.5 py-1.5 text-[13px] text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent/30 dark:bg-surface-2">
            </label>

            <div class="flex flex-col gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">
                Stock
                <div class="inline-flex rounded-lg border border-line p-0.5" role="group" aria-label="Stock status">
                    @foreach (['all' => 'All', 'in_stock' => 'In Stock Only', 'out_of_stock' => 'Out of Stock'] as $value => $label)
                        @php $on = $filters['stock_status'] === $value; @endphp
                        <label class="cursor-pointer select-none">
                            <input type="radio" name="stock_status" value="{{ $value }}" @checked($on) class="peer sr-only" data-autosubmit>
                            <span class="inline-flex items-center rounded-md px-2.5 py-1 text-[11px] font-medium transition {{ $on ? 'bg-accent text-accent-fg shadow-sm' : 'text-ink-muted hover:text-ink' }}">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <label class="flex flex-col gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">
                Min qty
                <input type="number" name="min_qty" min="0" value="{{ $filters['min_qty'] ?: '' }}" placeholder="0"
                    class="w-20 rounded-lg border border-line bg-surface px-2 py-1.5 text-[13px] tabular-nums text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent/30 dark:bg-surface-2">
            </label>

            <label class="flex flex-col gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">
                Per page
                <select name="per_page" class="rounded-lg border border-line bg-surface px-2 py-1.5 text-[13px] text-ink outline-none focus:border-accent dark:bg-surface-2" data-autosubmit>
                    @foreach ($perPageOptions as $option)
                        <option value="{{ $option }}" @selected($filters['per_page'] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">
                Sort by
                <select name="sort_by" class="rounded-lg border border-line bg-surface px-2 py-1.5 text-[13px] text-ink outline-none focus:border-accent dark:bg-surface-2" data-autosubmit>
                    @foreach (['manufacturer' => 'Brand', 'caliber' => 'Caliber', 'name' => 'Product', 'best_price' => 'Best $/Box', 'best_cpr' => 'Best $/Round', 'total_qty' => 'Total Qty'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters['sort_by'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">
                Dir
                <select name="sort_dir" class="rounded-lg border border-line bg-surface px-2 py-1.5 text-[13px] text-ink outline-none focus:border-accent dark:bg-surface-2" data-autosubmit>
                    <option value="asc" @selected($filters['sort_dir'] === 'asc')>Asc</option>
                    <option value="desc" @selected($filters['sort_dir'] === 'desc')>Desc</option>
                </select>
            </label>

            <x-ui.button
                :href="route('supply.dashboard', ['reset' => 1])"
                variant="secondary"
                size="sm"
                class="ml-auto self-end"
                data-reset-filters
            >Reset All Filters</x-ui.button>
        </div>

        @include('supply.partials._active-filter-pills', [
            'filters' => $filters,
            'distributors' => $facets['distributors'],
        ])
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter accordions --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="space-y-2">

        {{-- Accordion 1: Distributors --}}
        <x-ui.accordion title="Distributors" :badge="$distributorBadge" :open="(bool) $selectedDistributors">
            <div class="space-y-2.5">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" data-dist-all class="rounded-lg border border-line px-2 py-1 text-[11px] font-medium text-ink-muted transition hover:bg-ink/5 hover:text-ink">Select all</button>
                    <button type="button" data-dist-none class="rounded-lg border border-line px-2 py-1 text-[11px] font-medium text-ink-muted transition hover:bg-ink/5 hover:text-ink">Select none</button>

                    <select name="distributor_mode" class="ml-auto rounded-lg border border-line bg-surface px-2 py-1 text-[11px] text-ink outline-none focus:border-accent dark:bg-surface-2" data-autosubmit>
                        <option value="include" @selected($filters['distributor_mode'] === 'include')>Include selected</option>
                        <option value="exclude" @selected($filters['distributor_mode'] === 'exclude')>Exclude selected</option>
                    </select>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    @foreach ($facets['distributors'] as $distributor)
                        @php $on = in_array($distributor->id, $selectedDistributors, true); @endphp
                        <label class="cursor-pointer select-none">
                            <input type="checkbox" name="distributors[]" value="{{ $distributor->id }}" @checked($on) class="peer sr-only" data-dist-toggle data-autosubmit>
                            <span class="inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-[11px] font-medium transition peer-checked:border-accent peer-checked:bg-accent peer-checked:text-accent-fg {{ $on ? '' : 'border-line text-ink-muted hover:bg-ink/5 hover:text-ink' }}">
                                {{ $distributor->name }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        </x-ui.accordion>

        {{-- Accordion 2: Caliber --}}
        <x-ui.accordion title="Caliber" :badge="$selectionBadge($selectedCalibers)" :open="(bool) $selectedCalibers">
            @include('supply.partials._facet-group', [
                'name' => 'calibers',
                'available' => $facets['calibers'],
                'selected' => $selectedCalibers,
                'unit' => '',
            ])
        </x-ui.accordion>

        {{-- Accordion 3: Projectile Type --}}
        <x-ui.accordion title="Projectile Type" :badge="$selectionBadge($selectedProjectiles)" :open="(bool) $selectedProjectiles">
            @include('supply.partials._facet-group', [
                'name' => 'projectile_types',
                'available' => $facets['projectile_types'],
                'selected' => $selectedProjectiles,
                'unit' => '',
            ])
        </x-ui.accordion>

        {{-- Accordion 4: Grain Weight --}}
        <x-ui.accordion title="Grain Weight" :badge="$selectionBadge($selectedGrains, ' gr')" :open="(bool) $selectedGrains">
            @include('supply.partials._facet-group', [
                'name' => 'grain_weights',
                'available' => $facets['grain_weights'],
                'selected' => $selectedGrains,
                'unit' => ' gr',
            ])
        </x-ui.accordion>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Presentation table --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="w-full overflow-x-auto rounded-xl border border-line bg-surface">
        <table class="w-full min-w-full border-collapse text-left text-[13px] text-ink [&_td]:py-1.5 [&_th]:py-2">
            <thead class="bg-surface-sunken">
                <tr>
                    <th class="w-8 border-b border-line px-3"></th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-left text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">UPC</th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-left text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Brand / Product</th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-left text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Caliber</th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-left text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Bullet</th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-right text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Weight</th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-right text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Best $/Box</th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-right text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Best $/Rd</th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-right text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Total Qty</th>
                    <th class="border-b border-line bg-surface-sunken px-3 text-left text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Distributors</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line/60">
                @forelse ($masters as $master)
                    @php $rowId = 'ammo-row-' . $master->id; @endphp
                    <tr class="transition-colors hover:bg-accent-soft/40">
                        <td class="border-b border-line/70 px-3 align-middle">
                            <button type="button"
                                class="grid h-6 w-6 place-items-center rounded-md border border-line text-ink-muted transition hover:bg-ink/5 hover:text-ink"
                                data-accordion-toggle="{{ $rowId }}" aria-expanded="false" aria-controls="{{ $rowId }}"
                                aria-label="Toggle distributor offerings">
                                <svg class="h-3.5 w-3.5 transition-transform" data-accordion-caret viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                            </button>
                        </td>
                        <td class="border-b border-line/70 px-3 align-middle tabular-nums text-ink-muted">{{ $master->upc ?: '—' }}</td>
                        <td class="border-b border-line/70 px-3 align-middle">
                            <div class="font-medium text-ink">{{ $master->manufacturer }}</div>
                            <div class="text-[11px] text-ink-subtle">
                                {{ Str::limit($master->name, 44) }}
                                <span class="text-ink-subtle/70">&middot; {{ $master->mfr_part_number }}</span>
                            </div>
                        </td>
                        <td class="border-b border-line/70 px-3 align-middle font-medium text-ink">{{ $master->caliber }}</td>
                        <td class="border-b border-line/70 px-3 align-middle text-ink-muted">{{ $master->bullet_type ?: '—' }}</td>
                        <td class="border-b border-line/70 px-3 align-middle text-right tabular-nums">{{ $master->bullet_weight_gr ? $master->bullet_weight_gr . ' gr' : '—' }}</td>
                        <td class="border-b border-line/70 px-3 align-middle text-right tabular-nums">{{ $money($master->best_price_per_box, 2) }}</td>
                        <td class="border-b border-line/70 px-3 align-middle text-right font-semibold tabular-nums text-ink">{{ $money($master->best_price_per_round, 3) }}</td>
                        <td class="border-b border-line/70 px-3 align-middle text-right tabular-nums">{{ number_format($master->total_quantity_available) }}</td>
                        <td class="border-b border-line/70 px-3 align-middle">
                            <div class="flex flex-wrap items-center gap-1">
                                @foreach ($master->distributor_badges as $badge)
                                    <x-ui.badge :variant="$badge['in_stock'] ? 'success' : 'neutral'" size="sm" :dot="$badge['in_stock']">
                                        {{ $badge['name'] }}
                                    </x-ui.badge>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                    <tr id="{{ $rowId }}" hidden data-accordion-panel>
                        <td class="border-b border-line/70 bg-surface-sunken/60 px-3 py-2" colspan="10">
                            <div class="px-6">
                                <div class="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">
                                    {{ $master->listing_count }} distributor offering{{ $master->listing_count === 1 ? '' : 's' }}
                                </div>
                                <table class="w-full border-collapse text-left text-[12px]">
                                    <thead>
                                        <tr class="text-[9px] uppercase tracking-wider text-ink-subtle">
                                            <th class="py-1 pr-3 font-semibold">Distributor</th>
                                            <th class="py-1 pr-3 font-semibold">Distributor SKU</th>
                                            <th class="py-1 pr-3 text-right font-semibold">Dealer Cost</th>
                                            <th class="py-1 pr-3 text-right font-semibold">$ / Round</th>
                                            <th class="py-1 pr-3 text-right font-semibold">Stock Qty</th>
                                            <th class="py-1 pr-3 font-semibold">Last Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($master->offerings as $offering)
                                            <tr class="border-t border-line/60">
                                                <td class="py-1 pr-3 text-ink">{{ $offering['distributor'] ?? '—' }}</td>
                                                <td class="py-1 pr-3 tabular-nums text-ink-muted">{{ $offering['sku'] }}</td>
                                                <td class="py-1 pr-3 text-right tabular-nums">{{ $money($offering['dealer_cost'], 2) }}</td>
                                                <td class="py-1 pr-3 text-right tabular-nums">{{ $offering['cpr'] !== null ? $money($offering['cpr'], 4) : '—' }}</td>
                                                <td class="py-1 pr-3 text-right tabular-nums">
                                                    {{ number_format($offering['qty']) }}
                                                    @unless ($offering['in_stock'])
                                                        <span class="ml-1 text-[9px] uppercase text-red-500">out</span>
                                                    @endunless
                                                </td>
                                                <td class="py-1 pr-3 text-ink-muted">
                                                    {{ $offering['updated_at'] ? \Illuminate\Support\Carbon::parse($offering['updated_at'])->diffForHumans() : '—' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <div class="py-10 text-center text-[13px] text-ink-subtle">
                                No tracked ammunition matches the active filters.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="text-[12px] text-ink-muted">
        {{ $masters->links() }}
    </div>
</form>
@endsection

@push('scripts')
<script>
    (function () {
        var form = document.getElementById('supply-filters');
        if (!form) return;

        // Auto-submit on chip / select / radio change.
        form.querySelectorAll('[data-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () { form.requestSubmit(); });
        });

        // Distributor Select all / none.
        var toggles = form.querySelectorAll('[data-dist-toggle]');
        function bulkDistributors(state) {
            toggles.forEach(function (t) { t.checked = state; });
            form.requestSubmit();
        }
        var allBtn = form.querySelector('[data-dist-all]');
        var noneBtn = form.querySelector('[data-dist-none]');
        if (allBtn) allBtn.addEventListener('click', function () { bulkDistributors(true); });
        if (noneBtn) noneBtn.addEventListener('click', function () { bulkDistributors(false); });

        // Active filter pills — remove one value and re-query.
        form.querySelectorAll('[data-pill-remove]').forEach(function (pill) {
            pill.addEventListener('click', function () {
                var name = pill.getAttribute('data-pill-name');
                var value = pill.getAttribute('data-pill-value');

                if (name === 'search' || name === 'min_qty') {
                    var input = form.querySelector('[name="' + name + '"]');
                    if (input) input.value = '';
                } else if (name === 'stock_status') {
                    var radio = form.querySelector('[name="stock_status"][value="all"]');
                    if (radio) radio.checked = true;
                } else {
                    var box = form.querySelector('[name="' + name + '[]"][value="' + value + '"]');
                    if (box) box.checked = false;
                }
                form.requestSubmit();
            });
        });

        // Collapsible filter accordions (CSS grid-rows transition).
        form.querySelectorAll('[data-accordion]').forEach(function (section) {
            var trigger = section.querySelector('[data-accordion-trigger]');
            var body = section.querySelector('[data-accordion-body]');
            var chevron = section.querySelector('[data-accordion-chevron]');
            if (!trigger || !body) return;

            trigger.addEventListener('click', function () {
                var open = body.classList.toggle('is-open');
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (chevron) chevron.classList.toggle('rotate-180', open);
            });
        });

        // Expandable table rows (per-master distributor offerings).
        form.querySelectorAll('[data-accordion-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var panel = document.getElementById(btn.getAttribute('data-accordion-toggle'));
                if (!panel) return;
                var open = panel.hasAttribute('hidden');
                panel.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                var caret = btn.querySelector('[data-accordion-caret]');
                if (caret) caret.style.transform = open ? 'rotate(90deg)' : '';
            });
        });

        // Price-spread basis toggle.
        var spread = document.querySelector('[data-price-spread]');
        if (spread) {
            var values = spread.querySelectorAll('[data-spread-cpr]');
            var modeBtns = spread.querySelectorAll('[data-spread-mode]');
            function setMode(mode) {
                values.forEach(function (v) { v.textContent = v.getAttribute('data-spread-' + mode); });
                modeBtns.forEach(function (b) {
                    var on = b.getAttribute('data-spread-mode') === mode;
                    b.classList.toggle('bg-accent', on);
                    b.classList.toggle('text-accent-fg', on);
                    b.classList.toggle('text-ink-muted', !on);
                });
            }
            modeBtns.forEach(function (b) {
                b.addEventListener('click', function () { setMode(b.getAttribute('data-spread-mode')); });
            });
            setMode('cpr');
        }
    })();
</script>
@endpush
