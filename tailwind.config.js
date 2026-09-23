/** @type {import('tailwindcss').Config} */
export default {
  // Scoped/prefixed so wp-admin + theme styles never clash.
  prefix: 'wpsd-',
  content: ['./assets/admin-src/**/*.{js,jsx}', './assets/public-src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eef6ff',
          500: '#2271b1',
          700: '#135e96',
        },
      },
      borderRadius: {
        DEFAULT: '6px',
      },
      fontFamily: {
        sans: ['-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
