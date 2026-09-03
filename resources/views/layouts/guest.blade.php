<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>@yield('title', 'Sign In') &middot; data.firehole.com</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] } } } };
    </script>
    <style>
        html { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        body { background-color: #0c0a09; color: #f5f5f4; }
    </style>
</head>
<body class="min-h-full antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <a href="{{ route('home') }}" class="mb-8 flex items-center gap-2.5 text-sm font-semibold text-stone-200">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-teal-500 text-[13px] font-bold text-teal-950">FH</span>
            <span>data.firehole.com</span>
        </a>

        <div class="w-full max-w-sm rounded-2xl border border-stone-800 bg-stone-950/80 p-6 shadow-xl">
            <h1 class="text-base font-semibold text-stone-100">@yield('heading', 'Sign In')</h1>
            <p class="mt-1 text-[12px] text-stone-400">@yield('subheading', 'Firehole Industry Data Operations')</p>

            @if (session('status'))
                <div class="mt-4 rounded-lg border border-teal-800 bg-teal-950/60 px-3 py-2 text-[12px] text-teal-200">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-4 rounded-lg border border-rose-900 bg-rose-950/50 px-3 py-2 text-[12px] text-rose-200">
                    <ul class="list-disc space-y-0.5 pl-4">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-5">
                @yield('form')
            </div>
        </div>

        <p class="mt-6 text-[11px] text-stone-500">
            Firehole Arms &mdash; Ammunition Supply Intelligence
        </p>
    </div>
</body>
</html>
