@extends('layouts.app')

@section('title', 'User Management')

@section('content')
<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-ink">User Management</h1>
            <p class="text-[12px] text-ink-muted">
                Accounts that can sign in to data.firehole.com, and who holds administrator privileges.
            </p>
        </div>
        <span class="text-[12px] text-ink-subtle tabular-nums">{{ $users->count() }} account{{ $users->count() === 1 ? '' : 's' }}</span>
    </div>

    {{-- Add New User --------------------------------------------------- --}}
    <details class="group rounded-xl border border-line bg-surface" {{ $errors->any() ? 'open' : '' }}>
        <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-[13px] font-medium text-ink">
            <span>Add New User</span>
            <span class="text-[11px] text-ink-subtle group-open:hidden">Expand</span>
            <span class="hidden text-[11px] text-ink-subtle group-open:inline">Collapse</span>
        </summary>

        <div class="border-t border-line px-4 py-4">
            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-red-500/25 bg-red-500/10 px-3 py-2 text-[12px] text-red-700 dark:text-red-300">
                    <ul class="list-disc space-y-0.5 pl-4">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.store') }}" class="grid gap-3 sm:grid-cols-2">
                @csrf

                <x-ui.form-input label="Name" name="name" :value="old('name')" required />
                <x-ui.form-input label="Email" name="email" type="email" :value="old('email')" required />
                <x-ui.form-input label="Password (min 8 characters)" name="password" type="password" required />

                <label class="flex items-center gap-2 self-center text-[13px] text-ink">
                    <input type="checkbox" name="is_admin" value="1" @checked(old('is_admin'))
                        class="h-4 w-4 rounded border-line-strong bg-surface text-accent focus:ring-accent/40">
                    Administrator Privileges
                </label>

                <div class="sm:col-span-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Create User</x-ui.button>
                </div>
            </form>
        </div>
    </details>

    {{-- Roster -------------------------------------------------------- --}}
    <x-ui.table>
        <x-ui.table.thead>
            <x-ui.table.tr>
                <x-ui.table.th>Name</x-ui.table.th>
                <x-ui.table.th>Email</x-ui.table.th>
                <x-ui.table.th>Role</x-ui.table.th>
                <x-ui.table.th>Date Joined</x-ui.table.th>
                <x-ui.table.th>Actions</x-ui.table.th>
            </x-ui.table.tr>
        </x-ui.table.thead>
        <x-ui.table.tbody>
            @forelse ($users as $user)
                @php $isSelf = $user->is(auth()->user()); @endphp
                <x-ui.table.tr>
                    <x-ui.table.td>
                        <span class="font-medium text-ink">{{ $user->name }}</span>
                        @if ($isSelf)
                            <span class="ml-1 text-[11px] text-ink-subtle">(you)</span>
                        @endif
                    </x-ui.table.td>

                    <x-ui.table.td>
                        <span class="text-ink-muted">{{ $user->email }}</span>
                    </x-ui.table.td>

                    <x-ui.table.td>
                        @if ($user->isAdmin())
                            <x-ui.badge variant="default" dot>Admin</x-ui.badge>
                        @else
                            <x-ui.badge variant="neutral" dot>Standard</x-ui.badge>
                        @endif
                    </x-ui.table.td>

                    <x-ui.table.td>
                        <span title="{{ $user->created_at }}">{{ $user->created_at?->format('M j, Y') ?? '—' }}</span>
                    </x-ui.table.td>

                    <x-ui.table.td>
                        <div class="flex flex-wrap items-start gap-2">
                            <details class="group/edit">
                                <summary class="inline-flex cursor-pointer list-none items-center rounded-md border border-line-strong bg-transparent px-2 py-1 text-[11px] font-medium text-ink transition hover:bg-ink/5">
                                    Edit
                                </summary>

                                <form method="POST" action="{{ route('admin.users.update', $user) }}"
                                    class="mt-2 w-72 space-y-2 rounded-lg border border-line bg-surface-2 p-3">
                                    @csrf
                                    @method('PUT')

                                    <x-ui.form-input label="Name" name="name" :value="$user->name" required />
                                    <x-ui.form-input label="Email" name="email" type="email" :value="$user->email" required />
                                    <x-ui.form-input label="New password (optional)" name="password" type="password" />

                                    <label class="flex items-center gap-2 text-[12px] text-ink {{ $isSelf ? 'opacity-50' : '' }}">
                                        <input type="checkbox" name="is_admin" value="1" @checked($user->isAdmin()) @disabled($isSelf)
                                            class="h-4 w-4 rounded border-line-strong bg-surface text-accent focus:ring-accent/40">
                                        Administrator Privileges
                                    </label>
                                    @if ($isSelf)
                                        <input type="hidden" name="is_admin" value="1">
                                        <p class="text-[10px] text-ink-subtle">You cannot change your own role.</p>
                                    @endif

                                    <x-ui.button type="submit" variant="secondary" size="xs">Save Changes</x-ui.button>
                                </form>
                            </details>

                            @unless ($isSelf)
                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                    onsubmit="return confirm('Delete {{ $user->email }}? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger" size="xs">Delete</x-ui.button>
                                </form>
                            @endunless
                        </div>
                    </x-ui.table.td>
                </x-ui.table.tr>
            @empty
                <x-ui.table.tr>
                    <x-ui.table.td colspan="5">
                        <div class="py-8 text-center text-[13px] text-ink-subtle">No user accounts yet.</div>
                    </x-ui.table.td>
                </x-ui.table.tr>
            @endforelse
        </x-ui.table.tbody>
    </x-ui.table>
</div>
@endsection
