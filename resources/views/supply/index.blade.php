@extends('layouts.app')

@section('title', 'Live Supply Report')

@section('content')
<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-ink">2026 Ammunition Supply Report</h1>
            <p class="text-[12px] text-ink-muted">
                Live wholesale pricing &amp; availability across {{ $distributorCount }} distributor feed{{ $distributorCount === 1 ? '' : 's' }}.
            </p>
        </div>
        <x-ui.button :href="route('supply.distributors')" variant="outline" size="sm">Feed health &rarr;</x-ui.button>
    </div>

    {{-- KPI row --}}
    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($kpis as $kpi)
            <x-ui.stat-card
                :title="$kpi['title']"
                :value="$kpi['value']"
                :subtext="$kpi['subtext'] ?? null"
                :trend="$kpi['trend'] ?? null"
            />
        @endforeach
    </div>

    {{-- Caliber filter tabs --}}
    <x-ui.filter-tabs :tabs="$tabs" name="caliber" :current="$filters['caliber']" />

    {{-- Search + refinement --}}
    <form method="GET" action="{{ route('supply.index') }}" class="flex flex-wrap items-center gap-2">
        @if ($filters['caliber'] !== 'All')
            <input type="hidden" name="caliber" value="{{ $filters['caliber'] }}">
        @endif

        <div class="w-full sm:w-72">
            <x-ui.form-input name="search" label="Search name, MPN, UPC" :value="$filters['search']" autocomplete="off" />
        </div>

        <select
            name="manufacturer"
            class="rounded-lg border border-line bg-surface px-2.5 py-2 text-[13px] text-ink shadow-sm outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/30 dark:bg-surface-2"
        >
            <option value="">All manufacturers</option>
            @foreach ($manufacturers as $option)
                <option value="{{ $option }}" @selected($filters['manufacturer'] === $option)>{{ $option }}</option>
            @endforeach
        </select>

        <label class="inline-flex select-none items-center gap-1.5 text-[12px] text-ink-muted">
            <input
                type="checkbox"
                name="in_stock_only"
                value="1"
                @checked($filters['in_stock_only'])
                class="rounded border-line text-accent focus:ring-accent/40"
            >
            In-stock only
        </label>

        <x-ui.button type="submit" variant="primary" size="sm">Apply</x-ui.button>

        @if ($filters['caliber'] !== 'All' || $filters['manufacturer'] || $filters['search'] !== '' || ! $filters['in_stock_only'])
            <x-ui.button :href="route('supply.index')" variant="ghost" size="sm">Reset</x-ui.button>
        @endif
    </form>

    {{-- High-density supply comparison table --}}
    <x-ui.table>
        <x-ui.table.thead>
            <x-ui.table.tr>
                <x-ui.table.th>Caliber &amp; Spec</x-ui.table.th>
                <x-ui.table.th>Brand / Product</x-ui.table.th>
                <x-ui.table.th numeric>Rnds / Box</x-ui.table.th>
                <x-ui.table.th numeric>Best $ / Box</x-ui.table.th>
                <x-ui.table.th numeric>Best $ / Round</x-ui.table.th>
                <x-ui.table.th>Best Distributor</x-ui.table.th>
                <x-ui.table.th numeric>Qty Available</x-ui.table.th>
                <x-ui.table.th>Status</x-ui.table.th>
            </x-ui.table.tr>
        </x-ui.table.thead>
        <x-ui.table.tbody>
            @forelse ($masters as $master)
                <x-ui.table.tr>
                    <x-ui.table.td>
                        <div class="font-medium text-ink">{{ $master->caliber }}</div>
                        <div class="text-[11px] text-ink-subtle">
                            {{ $master->bullet_weight_gr ? $master->bullet_weight_gr . ' gr' : '—' }}@if ($master->bullet_type) &middot; {{ $master->bullet_type }}@endif
                        </div>
                    </x-ui.table.td>

                    <x-ui.table.td>
                        <div class="font-medium text-ink">{{ $master->manufacturer }}</div>
                        <div class="text-[11px] text-ink-subtle">
                            {{ \Illuminate\Support\Str::limit($master->name, 46) }}
                            <span class="text-ink-subtle/70">&middot; {{ $master->mfr_part_number }}</span>
                        </div>
                    </x-ui.table.td>

                    <x-ui.table.td numeric>{{ number_format($master->rounds_per_box) }}</x-ui.table.td>

                    <x-ui.table.td numeric>
                        {{ $master->best_price_per_box !== null ? '$' . number_format($master->best_price_per_box, 2) : '—' }}
                    </x-ui.table.td>

                    <x-ui.table.td numeric>
                        <span class="font-semibold text-ink">
                            {{ $master->best_price_per_round !== null ? '$' . number_format($master->best_price_per_round, 3) : '—' }}
                        </span>
                    </x-ui.table.td>

                    <x-ui.table.td>
                        <span class="text-ink-muted">{{ $master->best_distributor_name ?? '—' }}</span>
                        @if ($master->listing_count > 1)
                            <span class="ml-1 text-[10px] text-ink-subtle">+{{ $master->listing_count - 1 }} more</span>
                        @endif
                    </x-ui.table.td>

                    <x-ui.table.td numeric>{{ number_format($master->total_quantity_available) }}</x-ui.table.td>

                    <x-ui.table.td>
                        @if ($master->total_quantity_available > 0)
                            <x-ui.badge variant="success" dot>In Stock</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger" dot>Out</x-ui.badge>
                        @endif
                    </x-ui.table.td>
                </x-ui.table.tr>
            @empty
                <x-ui.table.tr>
                    <x-ui.table.td colspan="8">
                        <div class="py-8 text-center text-[13px] text-ink-subtle">
                            No tracked ammunition matches these filters.
                        </div>
                    </x-ui.table.td>
                </x-ui.table.tr>
            @endforelse
        </x-ui.table.tbody>
    </x-ui.table>

    <div class="text-[12px] text-ink-muted">
        {{ $masters->links() }}
    </div>
</div>
@endsection
