@extends('layouts.app')

@section('title', 'Distributors & Feed Health')

@section('content')
<div class="space-y-5">
    <div>
        <h1 class="text-lg font-semibold tracking-tight text-ink">Distributors &amp; Feed Health</h1>
        <p class="text-[12px] text-ink-muted">
            Connection profile and last ingestion outcome for every wholesale feed.
        </p>
    </div>

    <x-ui.table>
        <x-ui.table.thead>
            <x-ui.table.tr>
                <x-ui.table.th>Distributor</x-ui.table.th>
                <x-ui.table.th>Transport</x-ui.table.th>
                <x-ui.table.th>Active</x-ui.table.th>
                <x-ui.table.th>Last Synced</x-ui.table.th>
                <x-ui.table.th>Latest Feed Run</x-ui.table.th>
                <x-ui.table.th numeric>Products Tracked</x-ui.table.th>
                <x-ui.table.th numeric>Needs Review</x-ui.table.th>
                <x-ui.table.th>Actions</x-ui.table.th>
            </x-ui.table.tr>
        </x-ui.table.thead>
        <x-ui.table.tbody>
            @forelse ($distributors as $distributor)
                <x-ui.table.tr>
                    <x-ui.table.td>
                        <div class="font-medium text-ink">{{ $distributor['name'] }}</div>
                        <div class="text-[11px] text-ink-subtle">{{ $distributor['slug'] }}</div>
                    </x-ui.table.td>

                    <x-ui.table.td>
                        <x-ui.badge variant="neutral">{{ $distributor['transport_type'] }}</x-ui.badge>
                    </x-ui.table.td>

                    <x-ui.table.td>
                        @if ($distributor['is_active'])
                            <x-ui.badge variant="success" dot>Active</x-ui.badge>
                        @else
                            <x-ui.badge variant="neutral" dot>Paused</x-ui.badge>
                        @endif
                    </x-ui.table.td>

                    <x-ui.table.td>
                        @if ($distributor['last_synced_at'])
                            <span title="{{ $distributor['last_synced_at'] }}">{{ $distributor['last_synced_at']->diffForHumans() }}</span>
                        @else
                            <span class="text-ink-subtle">never</span>
                        @endif
                    </x-ui.table.td>

                    <x-ui.table.td>
                        @php $status = $distributor['latest_status']; @endphp
                        @switch($status)
                            @case('completed')
                                <x-ui.badge variant="success">completed</x-ui.badge>
                                @break
                            @case('failed')
                                <x-ui.badge variant="danger">failed</x-ui.badge>
                                @break
                            @case('running')
                                <x-ui.badge variant="warning">running</x-ui.badge>
                                @break
                            @case('pending')
                                <x-ui.badge variant="neutral">pending</x-ui.badge>
                                @break
                            @default
                                <span class="text-ink-subtle">no runs</span>
                        @endswitch

                        @if ($distributor['latest_run_at'])
                            <span class="ml-1 text-[11px] text-ink-subtle">{{ $distributor['latest_run_at']->diffForHumans() }}</span>
                        @endif
                    </x-ui.table.td>

                    <x-ui.table.td numeric>{{ number_format($distributor['products_tracked']) }}</x-ui.table.td>

                    <x-ui.table.td numeric>
                        @if (($distributor['needs_review_count'] ?? 0) > 0)
                            <a href="{{ route('supply.dashboard', ['review' => 'flagged']) }}"
                                title="Inspect flagged offerings on the dashboard"
                                class="inline-flex items-center rounded-full border border-amber-500/30 bg-amber-500/10 px-1.5 py-[1px] text-[10px] font-semibold tabular-nums text-amber-700 dark:text-amber-300">
                                {{ number_format($distributor['needs_review_count']) }}
                            </a>
                        @else
                            <span class="text-ink-subtle">0</span>
                        @endif
                    </x-ui.table.td>

                    <x-ui.table.td>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-ui.button
                                :href="route('distributors.edit', $distributor['slug'])"
                                variant="outline"
                                size="xs"
                            >Edit Credentials</x-ui.button>

                            <form method="POST" action="{{ route('distributors.test', $distributor['slug']) }}">
                                @csrf
                                <x-ui.button type="submit" variant="ghost" size="xs">Test Connection</x-ui.button>
                            </form>

                            <form
                                method="POST"
                                action="{{ route('distributors.sync', $distributor['slug']) }}"
                                onsubmit="return confirm('Run a full sync for {{ $distributor['name'] }} now?');"
                            >
                                @csrf
                                <x-ui.button type="submit" variant="ghost" size="xs">Sync Now</x-ui.button>
                            </form>
                        </div>
                    </x-ui.table.td>
                </x-ui.table.tr>
            @empty
                <x-ui.table.tr>
                    <x-ui.table.td colspan="8">
                        <div class="py-8 text-center text-[13px] text-ink-subtle">No distributors configured yet.</div>
                    </x-ui.table.td>
                </x-ui.table.tr>
            @endforelse
        </x-ui.table.tbody>
    </x-ui.table>
</div>
@endsection
