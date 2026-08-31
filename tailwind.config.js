/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
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
    plugins: [],
};
