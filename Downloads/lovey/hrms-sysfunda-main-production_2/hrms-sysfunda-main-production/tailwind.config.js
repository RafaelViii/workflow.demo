/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './*.php',
    './includes/**/*.php',
    './modules/**/*.php',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
      },
      colors: {
        sidebar: { DEFAULT: '#0f172a', light: '#1e293b' },
        // Brand identity color (#F26E30, already used for the active
        // sidebar nav-group highlight) is the app's "accent" hue. Rather
        // than hand-edit every bg-indigo-*/text-purple-*/etc class scattered
        // across the codebase (the app's own documented convention uses
        // indigo-600 for "primary actions, active states"), redefine what
        // those two color *names* resolve to at the Tailwind level - every
        // existing indigo/purple usage automatically becomes an orange
        // shade at the same lightness/role it already had, everywhere,
        // with one rebuild instead of hundreds of individual file edits.
        indigo: {
          50: '#fff5ef', 100: '#fee8da', 200: '#fdd0b4', 300: '#fbae81',
          400: '#f78f5c', 500: '#f47f45', 600: '#f26e30', 700: '#c6551f',
          800: '#9c4319', 900: '#7a3515', 950: '#4a1f0c',
        },
        purple: {
          50: '#fff5ef', 100: '#fee8da', 200: '#fdd0b4', 300: '#fbae81',
          400: '#f78f5c', 500: '#f47f45', 600: '#f26e30', 700: '#c6551f',
          800: '#9c4319', 900: '#7a3515', 950: '#4a1f0c',
        },
      },
    },
  },
  plugins: [],
}
