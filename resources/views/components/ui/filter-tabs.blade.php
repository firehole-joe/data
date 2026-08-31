@props([
    'tabs' => [],
    'name' => 'caliber',
    'current' => null,
])

@php
    // Keep every other query parameter intact when a tab is switched;
    // only the tab param itself and pagination are replaced/reset.
    $baseQuery = collect(request()->query())->except([$name, 'page'])->all();
@endphp

<nav {{ $attributes->class('flex flex-wrap items-center gap-1 rounded-xl border border-line bg-surface p-1') }} aria-label="Filter by {{ $name }}">
    @foreach ($tabs as $tab)
        @php
            $value = $tab['value'] ?? $tab['label'];
            $isActive = (string) $current === (string) $value;
            $url = request()->url() . '?' . http_build_query(array_merge($baseQuery, [$name => $value]));
        @endphp

        <a
            href="{{ $url }}"
            @if ($isActive) aria-current="page" @endif
            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[12px] font-medium transition {{ $isActive ? 'bg-accent text-accent-fg shadow-sm' : 'text-ink-muted hover:bg-ink/5 hover:text-ink' }}"
        >
            <span>{{ $tab['label'] }}</span>

            @isset($tab['count'])
                <span class="rounded-full px-1.5 text-[10px] tabular-nums {{ $isActive ? 'bg-black/15 text-accent-fg' : 'bg-ink/10 text-ink-subtle' }}">
                    {{ $tab['count'] }}
                </span>
            @endisset
        </a>
    @endforeach
</nav>
