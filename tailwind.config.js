import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    // The Floor follows the OS colour scheme and html[data-theme="light"|"dark"] (set by the theme toggle).
    // Tailwind's dark: variant is bound to the same two conditions, so Jetstream's stock dark: classes
    // flip with the rest of the interface.
    darkMode: [
        'variant',
        [
            '@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) & }',
            ':root[data-theme="dark"] &',
        ],
    ],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Archivo', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // Jetstream's stock components use indigo-*. Point that scale at the Floor's cobalt so the
                // profile, team and API-token screens carry the same field colour as the rest of the app.
                indigo: {
                    50: '#eef0ff',
                    100: '#dfe4ff',
                    200: '#bcc6ff',
                    300: '#8d9eff',
                    400: '#5a70ff',
                    500: '#2b45ff',
                    600: '#1f3cff',
                    700: '#1328b8',
                    800: '#0e1d8a',
                    900: '#0a1460',
                },
            },
        },
    },

    plugins: [forms, typography],
};
