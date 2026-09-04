<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>Firehole Industry Data Operations</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] } } } };
    </script>
    <style>
        html { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        body {
            background-color: #0c0a09;
            color: #f5f5f4;
            background-image:
                radial-gradient(60rem 30rem at 50% -10%, rgba(13, 148, 136, 0.18), transparent 70%),
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 100% 100%, 44px 44px, 44px 44px;
        }
    </style>
</head>
<body class="min-h-full antialiased">
    <div class="flex min-h-screen flex-col">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6">
            <div class="flex items-center gap-2.5 text-sm font-semibold text-stone-200">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-teal-500 text-[13px] font-bold text-teal-950">FH</span>
                <span>data.firehole.com</span>
            </div>
            <a href="{{ route('login') }}"
                class="rounded-lg border border-stone-700 px-3 py-1.5 text-[12px] font-medium text-stone-300 transition hover:border-stone-500 hover:text-stone-100">
                Sign In
            </a>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 py-16">
            <p class="text-[12px] font-semibold uppercase tracking-[0.2em] text-teal-400">Firehole Arms &mdash; Ordnance Operations</p>

            <h1 class="mt-4 max-w-3xl text-4xl font-extrabold leading-tight tracking-tight text-stone-50 sm:text-5xl">
                Firehole Industry Data Operations
            </h1>

            <p class="mt-4 max-w-2xl text-base text-stone-300">
                Tactical Ammunition Supply Intelligence &amp; Distributor Analytics.
            </p>

            <p class="mt-3 max-w-2xl text-[13px] leading-relaxed text-stone-400">
                Real-time wholesale pricing, availability and feed-health telemetry consolidated
                across every distributor pipeline &mdash; normalized to canonical calibers,
                projectile types and cost-per-round for decision-grade comparison.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="{{ route('login') }}"
                    class="rounded-lg bg-teal-500 px-5 py-2.5 text-[13px] font-semibold text-teal-950 transition hover:bg-teal-400">
                    Secure Access
                </a>
                <a href="https://firehole.com" target="_blank" rel="noopener noreferrer"
                    class="rounded-lg border border-zinc-700 px-5 py-2.5 text-[13px] font-semibold text-stone-300 transition hover:border-zinc-500 hover:text-stone-100">
                    Visit Firehole.com
                </a>
                <span class="text-[12px] text-stone-500">Authorized personnel only.</span>
            </div>

            <dl class="mt-16 grid max-w-3xl grid-cols-1 gap-px overflow-hidden rounded-xl border border-stone-800 bg-stone-800 sm:grid-cols-3">
                @foreach ([
                    ['Distributor Feeds', 'Unified ingestion'],
                    ['Pricing Guardrails', 'Out-of-band review'],
                    ['Supply Analytics', 'Caliber-level rollups'],
                ] as [$term, $detail])
                    <div class="bg-stone-950 px-4 py-4">
                        <dt class="text-[13px] font-semibold text-stone-100">{{ $term }}</dt>
                        <dd class="mt-0.5 text-[12px] text-stone-400">{{ $detail }}</dd>
                    </div>
                @endforeach
            </dl>
        </main>

        <footer class="mx-auto w-full max-w-5xl px-6 py-8 text-[11px] text-stone-600">
            Firehole Arms &mdash; Ammunition Supply Intelligence &mdash; {{ now()->format('Y') }}
        </footer>
    </div>
</body>
</html>
