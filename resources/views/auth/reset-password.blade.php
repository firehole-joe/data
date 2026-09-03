@extends('layouts.guest')

@section('title', 'Set New Password')
@section('heading', 'Set a New Password')
@section('subheading', 'Choose a new password for your account.')

@section('form')
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="block text-[12px] font-medium text-stone-300">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username"
                class="mt-1 w-full rounded-lg border border-stone-700 bg-stone-900 px-3 py-2 text-[13px] text-stone-100 outline-none focus:border-teal-500">
        </div>

        <div>
            <label for="password" class="block text-[12px] font-medium text-stone-300">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                class="mt-1 w-full rounded-lg border border-stone-700 bg-stone-900 px-3 py-2 text-[13px] text-stone-100 outline-none focus:border-teal-500">
        </div>

        <div>
            <label for="password_confirmation" class="block text-[12px] font-medium text-stone-300">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                class="mt-1 w-full rounded-lg border border-stone-700 bg-stone-900 px-3 py-2 text-[13px] text-stone-100 outline-none focus:border-teal-500">
        </div>

        <button type="submit"
            class="w-full rounded-lg bg-teal-500 px-3 py-2 text-[13px] font-semibold text-teal-950 transition hover:bg-teal-400">
            Reset Password
        </button>
    </form>
@endsection
