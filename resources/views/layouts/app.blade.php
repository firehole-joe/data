<!DOCTYPE html>
<html lang="en" class="h-full" data-accent="teal">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Ammunition Supply Report') &middot; data.firehole.com</title>

    {{-- Apply persisted theme + accent before first paint (no flash). --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('cleopatra-theme');
                if (!t) {
                    t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.classList.toggle('dark', t === 'dark');
                var a = localStorage.getItem('cleopatra-accent');
                if (a) document.documentElement.setAttribute('data-accent', a);
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{--
        Cleopatra renders with the Tailwind Play CDN so the app needs no
        bundler. For production: `npm install && npm run build`, then swap
        the next two <script> blocks for `@vite(['resources/css/app.css'])`.
    --}}
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                    colors: {
                        bg: 'rgb(var(--clr-bg) / <alpha-value>)',
                        surface: {
                            DEFAULT: 'rgb(var(--clr-surface) / <alpha-value>)',
                            2: 'rgb(var(--clr-surface-2) / <alpha-value>)',
                            sunken: 'rgb(var(--clr-surface-sunken) / <alpha-value>)',
                        },
                        line: {
                            DEFAULT: 'rgb(var(--clr-border) / <alpha-value>)',
                            strong: 'rgb(var(--clr-border-strong) / <alpha-value>)',
                        },
                        ink: {
                            DEFAULT: 'rgb(var(--clr-text) / <alpha-value>)',
                            muted: 'rgb(var(--clr-text-muted) / <alpha-value>)',
                            subtle: 'rgb(var(--clr-text-subtle) / <alpha-value>)',
                        },
                        accent: {
                            DEFAULT: 'rgb(var(--clr-accent) / <alpha-value>)',
                            fg: 'rgb(var(--clr-accent-fg) / <alpha-value>)',
                            soft: 'rgb(var(--clr-accent-soft) / <alpha-value>)',
                        },
                    },
                },
            },
        };
    </script>

    <style>
        :root {
            --clr-bg: 250 250 249; --clr-surface: 255 255 255; --clr-surface-2: 250 250 249; --clr-surface-sunken: 245 245 244;
            --clr-border: 231 229 228; --clr-border-strong: 214 211 209;
            --clr-text: 28 25 23; --clr-text-muted: 78 74 70; --clr-text-subtle: 120 113 108;
            --clr-accent: 13 148 136; --clr-accent-fg: 255 255 255; --clr-accent-soft: 240 253 250;
        }
        .dark {
            --clr-bg: 12 10 9; --clr-surface: 28 25 23; --clr-surface-2: 23 20 18; --clr-surface-sunken: 18 16 15;
            --clr-border: 44 40 38; --clr-border-strong: 68 64 60;
            --clr-text: 245 245 244; --clr-text-muted: 168 162 158; --clr-text-subtle: 128 122 117;
            --clr-accent: 45 212 191; --clr-accent-fg: 6 20 18; --clr-accent-soft: 19 78 74;
        }
        [data-accent="blue"] { --clr-accent: 37 99 235; --clr-accent-soft: 239 246 255; }
        .dark[data-accent="blue"] { --clr-accent: 96 165 250; --clr-accent-soft: 30 58 138; --clr-accent-fg: 8 15 30; }
        [data-accent="violet"] { --clr-accent: 124 58 237; --clr-accent-soft: 245 243 255; }
        .dark[data-accent="violet"] { --clr-accent: 167 139 250; --clr-accent-soft: 46 16 101; --clr-accent-fg: 20 10 40; }
        [data-accent="amber"] { --clr-accent: 217 119 6; --clr-accent-soft: 255 251 235; }
        .dark[data-accent="amber"] { --clr-accent: 251 191 36; --clr-accent-soft: 69 46 5; --clr-accent-fg: 30 20 2; }
        [data-accent="rose"] { --clr-accent: 225 29 72; --clr-accent-soft: 255 241 242; }
        .dark[data-accent="rose"] { --clr-accent: 251 113 133; --clr-accent-soft: 76 5 25; --clr-accent-fg: 35 5 12; }

        html { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        body {
            background-color: rgb(var(--clr-bg));
            color: rgb(var(--clr-text));
            font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11', 'tnum';
        }
        ::selection { background-color: rgb(var(--clr-accent) / 0.25); }
    </style>

    @stack('head')
</head>
<body class="min-h-full antialiased text-[13px] leading-relaxed">
    <header class="sticky top-0 z-40 border-b border-line bg-surface/90 backdrop-blur">
        <div class="mx-auto flex h-12 max-w-7xl items-center gap-4 px-4">
            <a href="{{ route('supply.index') }}" class="flex shrink-0 items-center gap-2 font-semibold text-ink">
                <span class="grid h-6 w-6 place-items-center rounded-md bg-accent text-[11px] font-bold text-accent-fg">FH</span>
                <span>data.firehole.com</span>
            </a>

            @php
                $nav = [
                    ['label' => 'Live Supply Report', 'route' => 'supply.index'],
                    ['label' => 'Distributors & Feed Health', 'route' => 'supply.distributors'],
                ];
            @endphp
            <nav class="hidden items-center gap-1 sm:flex" aria-label="Primary">
                @foreach ($nav as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        @if ($active) aria-current="page" @endif
                        class="rounded-lg px-2.5 py-1 text-[12px] font-medium transition {{ $active ? 'bg-accent-soft text-accent' : 'text-ink-muted hover:bg-ink/5 hover:text-ink' }}"
                    >{{ $item['label'] }}</a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-1.5">
                <div class="hidden items-center gap-1 rounded-lg border border-line p-1 md:flex" role="group" aria-label="Accent color">
                    @foreach (['teal' => '#0d9488', 'blue' => '#2563eb', 'violet' => '#7c3aed', 'amber' => '#d97706', 'rose' => '#e11d48'] as $accent => $hex)
                        <button
                            type="button"
                            data-accent-swatch="{{ $accent }}"
                            style="background-color: {{ $hex }}"
                            class="h-4 w-4 rounded-full ring-2 ring-transparent transition hover:scale-110"
                            aria-label="{{ ucfirst($accent) }} accent"
                        ></button>
                    @endforeach
                </div>

                <button
                    type="button"
                    id="theme-toggle"
                    class="grid h-8 w-8 place-items-center rounded-lg border border-line text-ink-muted transition hover:bg-ink/5 hover:text-ink"
                    aria-label="Toggle light / dark theme"
                >
                    <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    <svg class="hidden h-4 w-4 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                </button>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6">
        @php
            $flashes = [
                'success' => 'success',
                'status' => 'info',
                'info' => 'info',
                'warning' => 'warning',
                'error' => 'danger',
            ];
        @endphp
        @if (collect(array_keys($flashes))->contains(fn ($k) => session()->has($k)))
            <div class="mb-5 space-y-2">
                @foreach ($flashes as $key => $variant)
                    @if (session()->has($key))
                        <x-ui.alert :variant="$variant" dismissible>{{ session($key) }}</x-ui.alert>
                    @endif
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="mx-auto max-w-7xl px-4 py-8 text-[11px] text-ink-subtle">
        Firehole Arms &mdash; Ammunition Supply Intelligence &mdash; {{ now()->format('Y') }}
    </footer>

    <script>
        (function () {
            var root = document.documentElement;

            function setTheme(mode) {
                root.classList.toggle('dark', mode === 'dark');
                try { localStorage.setItem('cleopatra-theme', mode); } catch (e) {}
            }

            var toggle = document.getElementById('theme-toggle');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    setTheme(root.classList.contains('dark') ? 'light' : 'dark');
                });
            }

            var swatches = document.querySelectorAll('[data-accent-swatch]');
            function paintSwatches() {
                var current = root.getAttribute('data-accent');
                swatches.forEach(function (b) {
                    var on = b.getAttribute('data-accent-swatch') === current;
                    b.classList.toggle('ring-accent', on);
                    b.classList.toggle('ring-transparent', !on);
                });
            }
            swatches.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    root.setAttribute('data-accent', btn.getAttribute('data-accent-swatch'));
                    try { localStorage.setItem('cleopatra-accent', btn.getAttribute('data-accent-swatch')); } catch (e) {}
                    paintSwatches();
                });
            });
            paintSwatches();
        })();
    </script>

    @stack('scripts')
</body>
</html>
