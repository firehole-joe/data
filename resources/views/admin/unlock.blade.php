@extends('layouts.app')

@section('title', 'Unlock Feed Admin')

@section('content')
<div class="mx-auto mt-6 max-w-sm">
    <div class="rounded-xl border border-line bg-surface p-6 shadow-sm">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-accent text-accent-fg">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </span>
            <div>
                <h1 class="text-sm font-semibold text-ink">Feed Admin</h1>
                <p class="text-[11px] text-ink-subtle">Enter the passphrase to manage distributor credentials.</p>
            </div>
        </div>

        @if ($errors->any())
            <x-ui.alert variant="danger" class="mb-3">{{ $errors->first('passphrase') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('admin.unlock.attempt') }}" class="space-y-3">
            @csrf
            <x-ui.form-input
                type="password"
                name="passphrase"
                label="Passphrase"
                autocomplete="current-password"
                autofocus
                required
            />
            <x-ui.button type="submit" variant="primary" size="md" class="w-full">Unlock</x-ui.button>
        </form>
    </div>

    <p class="mt-3 text-center text-[11px] text-ink-subtle">
        <a href="{{ route('supply.index') }}" class="hover:text-ink">&larr; Back to the supply report</a>
    </p>
</div>
@endsection
