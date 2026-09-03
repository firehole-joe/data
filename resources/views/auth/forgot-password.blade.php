@extends('layouts.guest')

@section('title', 'Reset Password')
@section('heading', 'Forgot Password')
@section('subheading', 'We will email you a secure reset link.')

@section('form')
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-[12px] font-medium text-stone-300">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                class="mt-1 w-full rounded-lg border border-stone-700 bg-stone-900 px-3 py-2 text-[13px] text-stone-100 outline-none focus:border-teal-500">
        </div>

        <button type="submit"
            class="w-full rounded-lg bg-teal-500 px-3 py-2 text-[13px] font-semibold text-teal-950 transition hover:bg-teal-400">
            Email Reset Link
        </button>

        <div class="text-center">
            <a href="{{ route('login') }}" class="text-[12px] text-stone-400 underline hover:text-stone-200">
                Back to sign in
            </a>
        </div>
    </form>
@endsection
