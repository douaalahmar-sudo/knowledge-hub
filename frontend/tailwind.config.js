/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./src/**/*.{html,ts}",
  ],
  theme: {
    extend: {
      colors: {
        // Align Tailwind's indigo accent with the app's design-token accent
        // (--accent in layout-shell.component.scss, Aziza orange #D9541E —
        // the exact value from the brand review, not the #E8692A used in an
        // earlier pass). Every `indigo-*` utility class in a template —
        // buttons, links, focus rings — repaints from here. Two spots use
        // `indigo` as a *category* colour rather than the brand accent
        // (PROCEDURE in search-result.model.ts, hr_request in
        // notification.model.ts); both were moved to `violet` instead so they
        // don't collide with the brand colour now sitting on `indigo`.
        indigo: {
          50: '#FBE9E0',
          500: '#D9541E',
          600: '#B8471A',
          700: '#8F3714',
        },
      },
    },
  },
  plugins: [],
};
