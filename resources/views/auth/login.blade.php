@extends('layouts.guest')

@section('title', 'Sign In')
@section('heading', 'Secure Access')
@section('subheading', 'Sign in to Firehole Industry Data Operations.')

@section('form')
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-[12px] font-medium text-stone-300">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                class="mt-1 w-full rounded-lg border border-stone-700 bg-stone-900 px-3 py-2 text-[13px] text-stone-100 outline-none focus:border-teal-500">
        </div>

        <div>
            <label for="password" class="block text-[12px] font-medium text-stone-300">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                class="mt-1 w-full rounded-lg border border-stone-700 bg-stone-900 px-3 py-2 text-[13px] text-stone-100 outline-none focus:border-teal-500">
        </div>

        <label class="flex items-center gap-2 text-[12px] text-stone-400">
            <input type="checkbox" name="remember" class="rounded border-stone-600 bg-stone-900 text-teal-500">
            Remember this device
        </label>

        <button type="submit"
            class="w-full rounded-lg bg-teal-500 px-3 py-2 text-[13px] font-semibold text-teal-950 transition hover:bg-teal-400">
            Sign In
        </button>

        <div class="text-center">
            <a href="{{ route('password.request') }}" class="text-[12px] text-stone-400 underline hover:text-stone-200">
                Forgot your password?
            </a>
        </div>
    </form>
@endsection
