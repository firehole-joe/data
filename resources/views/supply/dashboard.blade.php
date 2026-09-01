@extends('layouts.app')

@section('title', 'Ammunition Supply Dashboard')

@php
    use Illuminate\Support\Str;

    $money = fn ($v, $dp = 2) => $v === null ? '—' : '$' . number_format((float) $v, $dp);
    $selectedCalibers = $filters['calibers'];
    $selectedProjectiles = $filters['projectile_types'];
    $selectedGrains = $filters['grain_weights'];
    $selectedDistributors = $filters['distributor_ids'];

    $hasActiveFilters = $selectedCalibers || $selectedProjectiles || $selectedGrains || $selectedDistributors
        || $filters['stock_status'] !== 'all' || $filters['min_qty'] > 0 || $filters['search'] !== ''
        || $filters['per_page'] !== \App\Services\Ammunition\SupplyReportQueryService::DEFAULT_PER_PAGE
        || $filters['sort_by'] !== 'manufacturer' || $filters['sort_dir'] !== 'asc';
@endphp

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
                <x-ui.button :href="route('supply.dashboard')" variant="ghost" size="sm">Reset</x-ui.button>
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
    {{-- Filter toolbar --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="space-y-3 rounded-xl border border-line bg-surface p-3.5">

        {{-- Distributor selector --}}
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Distributors</span>
            <button type="button" data-dist-all class="rounded-lg border border-line px-2 py-1 text-[11px] font-medium text-ink-muted transition hover:bg-ink/5 hover:text-ink">All</button>
            <button type="button" data-dist-none class="rounded-lg border border-line px-2 py-1 text-[11px] font-medium text-ink-muted transition hover:bg-ink/5 hover:text-ink">None</button>

            @foreach ($options['distributors'] as $distributor)
                @php $on = in_array($distributor->id, $selectedDistributors, true); @endphp
                <label class="cursor-pointer select-none">
                    <input type="checkbox" name="distributors[]" value="{{ $distributor->id }}" @checked($on) class="peer sr-only" data-dist-toggle>
                    <span class="inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-[11px] font-medium transition peer-checked:border-accent peer-checked:bg-accent peer-checked:text-accent-fg {{ $on ? '' : 'border-line text-ink-muted hover:bg-ink/5 hover:text-ink' }}">
                        {{ $distributor->name }}
                    </span>
                </label>
            @endforeach

            <select name="distributor_mode" class="ml-1 rounded-lg border border-line bg-surface px-2 py-1 text-[11px] text-ink outline-none focus:border-accent dark:bg-surface-2" data-autosubmit>
                <option value="include" @selected($filters['distributor_mode'] === 'include')>Include selected</option>
                <option value="exclude" @selected($filters['distributor_mode'] === 'exclude')>Exclude selected</option>
            </select>
        </div>

        {{-- Quick caliber chips --}}
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Caliber</span>
            @foreach ($options['featured_calibers'] as $caliber)
                @php $on = in_array($caliber, $selectedCalibers, true); @endphp
                <label class="cursor-pointer select-none">
                    <input type="checkbox" name="calibers[]" value="{{ $caliber }}" @checked($on) class="peer sr-only" data-autosubmit>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-medium transition peer-checked:border-accent peer-checked:bg-accent peer-checked:text-accent-fg {{ $on ? '' : 'border-line text-ink-muted hover:bg-ink/5 hover:text-ink' }}">
                        {{ $caliber }}
                    </span>
                </label>
            @endforeach
        </div>

        {{-- Quick projectile chips --}}
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Projectile</span>
            @foreach ($options['featured_projectiles'] as $projectile)
                @php $on = in_array($projectile, $selectedProjectiles, true); @endphp
                <label class="cursor-pointer select-none">
                    <input type="checkbox" name="projectile_types[]" value="{{ $projectile }}" @checked($on) class="peer sr-only" data-autosubmit>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-medium transition peer-checked:border-accent peer-checked:bg-accent peer-checked:text-accent-fg {{ $on ? '' : 'border-line text-ink-muted hover:bg-ink/5 hover:text-ink' }}">
                        {{ $projectile }}
                    </span>
                </label>
            @endforeach
        </div>

        {{-- Grain weight chips --}}
        @if ($options['grain_weights'])
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">Grain</span>
                @foreach ($options['grain_weights'] as $grain)
                    @php $on = in_array($grain, $selectedGrains, true); @endphp
                    <label class="cursor-pointer select-none">
                        <input type="checkbox" name="grain_weights[]" value="{{ $grain }}" @checked($on) class="peer sr-only" data-autosubmit>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium tabular-nums transition peer-checked:border-accent peer-checked:bg-accent peer-checked:text-accent-fg {{ $on ? '' : 'border-line text-ink-muted hover:bg-ink/5 hover:text-ink' }}">
                            {{ $grain }} gr
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        {{-- Row: stock segmented + min qty + search + per page + sort --}}
        <div class="flex flex-wrap items-end gap-2 border-t border-line pt-3">
            <div class="inline-flex rounded-lg border border-line p-0.5" role="group" aria-label="Stock status">
                @foreach (['all' => 'All', 'in_stock' => 'In Stock Only', 'out_of_stock' => 'Out of Stock'] as $value => $label)
                    @php $on = $filters['stock_status'] === $value; @endphp
                    <label class="cursor-pointer select-none">
                        <input type="radio" name="stock_status" value="{{ $value }}" @checked($on) class="peer sr-only" data-autosubmit>
                        <span class="inline-flex items-center rounded-md px-2.5 py-1 text-[11px] font-medium transition {{ $on ? 'bg-accent text-accent-fg shadow-sm' : 'text-ink-muted hover:text-ink' }}">{{ $label }}</span>
                    </label>
                @endforeach
            </div>

            <label class="flex flex-col gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">
                Min qty
                <input type="number" name="min_qty" min="0" value="{{ $filters['min_qty'] ?: '' }}" placeholder="0"
                    class="w-20 rounded-lg border border-line bg-surface px-2 py-1.5 text-[13px] tabular-nums text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent/30 dark:bg-surface-2">
            </label>

            <label class="flex flex-1 flex-col gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-subtle sm:min-w-[16rem]">
                Search UPC / SKU / MPN / Brand / Name
                <input type="text" name="search" value="{{ $filters['search'] }}" autocomplete="off"
                    class="rounded-lg border border-line bg-surface px-2.5 py-1.5 text-[13px] text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent/30 dark:bg-surface-2">
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
        </div>
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

        // Distributor All / None.
        var toggles = form.querySelectorAll('[data-dist-toggle]');
        function bulkDistributors(state) {
            toggles.forEach(function (t) { t.checked = state; });
            form.requestSubmit();
        }
        var allBtn = form.querySelector('[data-dist-all]');
        var noneBtn = form.querySelector('[data-dist-none]');
        if (allBtn) allBtn.addEventListener('click', function () { bulkDistributors(true); });
        if (noneBtn) noneBtn.addEventListener('click', function () { bulkDistributors(false); });
        toggles.forEach(function (t) {
            t.addEventListener('change', function () { form.requestSubmit(); });
        });

        // Accordion rows.
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
