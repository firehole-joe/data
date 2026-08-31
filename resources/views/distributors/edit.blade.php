@extends('layouts.app')

@section('title', 'Edit ' . $distributor->name)

@section('content')
<div class="mx-auto max-w-2xl space-y-5">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-lg font-semibold tracking-tight text-ink">{{ $distributor->name }}</h1>
                <x-ui.badge variant="neutral">{{ $distributor->transport_type }}</x-ui.badge>
                @if ($distributor->is_active)
                    <x-ui.badge variant="success" dot>Active</x-ui.badge>
                @else
                    <x-ui.badge variant="warning" dot>Paused</x-ui.badge>
                @endif
            </div>
            <p class="mt-0.5 text-[12px] text-ink-muted">
                {{ $distributor->slug }} &middot; {{ class_basename($distributor->driver_class) }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button :href="route('supply.distributors')" variant="ghost" size="sm">&larr; All distributors</x-ui.button>
            <form method="POST" action="{{ route('admin.lock') }}">
                @csrf
                <x-ui.button type="submit" variant="outline" size="sm">Lock</x-ui.button>
            </form>
        </div>
    </div>

    @if ($errors->any())
        <x-ui.alert variant="danger" title="Please fix the following">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('distributors.update', $distributor) }}" class="space-y-5">
        @csrf
        @method('PUT')

        {{-- Credentials --}}
        <section class="rounded-xl border border-line bg-surface p-4">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">Credentials</h2>
            <p class="mb-3 mt-0.5 text-[11px] text-ink-subtle">
                Stored encrypted at rest. Leave a
                <span class="font-medium text-ink-muted">password / token / API key</span>
                field blank to keep the value already on file.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($fields as $field)
                    @php
                        $isSecret = in_array($field, $secretFields, true);
                        $hasStored = $isSecret && ! empty($settings[$field]);
                        $label = $fieldLabels[$field] ?? \Illuminate\Support\Str::headline($field);
                        $type = $isSecret ? 'password' : ($field === 'port' ? 'number' : 'text');
                    @endphp
                    <div>
                        <x-ui.form-input
                            :type="$type"
                            :name="'settings[' . $field . ']'"
                            :id="'settings_' . $field"
                            :label="$label . ($hasStored ? ' · stored' : '')"
                            :value="$isSecret ? '' : ($settings[$field] ?? '')"
                            autocomplete="off"
                        />
                        @if ($hasStored)
                            <p class="mt-1 text-[10px] text-ink-subtle">A value is stored. Type to replace it.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Feed Settings --}}
        <section class="rounded-xl border border-line bg-surface p-4">
            <h2 class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">Feed Settings</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-[13px] text-ink">
                    <input type="hidden" name="is_active" value="0">
                    <input
                        type="checkbox"
                        name="is_active"
                        value="1"
                        @checked($distributor->is_active)
                        class="rounded border-line text-accent focus:ring-accent/40"
                    >
                    Feed is active
                </label>

                <div>
                    <label for="sync_frequency" class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-ink-subtle">
                        Sync frequency
                    </label>
                    <select
                        id="sync_frequency"
                        name="sync_frequency"
                        class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-[13px] text-ink shadow-sm outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/30 dark:bg-surface-2"
                    >
                        @foreach ($frequencies as $freq)
                            <option value="{{ $freq }}" @selected($distributor->sync_frequency === $freq)>
                                {{ \Illuminate\Support\Str::of($freq)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-2">
                <x-ui.button type="submit" variant="primary" size="sm">Save changes</x-ui.button>
                <x-ui.button :href="route('distributors.edit', $distributor)" variant="ghost" size="sm">Cancel</x-ui.button>
            </div>
        </section>
    </form>

    {{-- Danger Zone / Actions --}}
    <section class="rounded-xl border border-line bg-surface-sunken p-4">
        <h2 class="text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">Danger Zone &amp; Actions</h2>
        <p class="mb-3 mt-0.5 text-[11px] text-ink-subtle">
            These run immediately against the live feed using the saved credentials.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('distributors.test', $distributor) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm">Test Connection</x-ui.button>
            </form>

            <form
                method="POST"
                action="{{ route('distributors.sync', $distributor) }}"
                onsubmit="return confirm('Run a full sync for {{ $distributor->name }} now?');"
            >
                @csrf
                <x-ui.button type="submit" variant="danger" size="sm">Run Sync Now</x-ui.button>
            </form>

            @if ($distributor->last_synced_at)
                <span class="text-[11px] text-ink-subtle">
                    Last synced {{ $distributor->last_synced_at->diffForHumans() }}
                </span>
            @endif
        </div>
    </section>
</div>
@endsection
