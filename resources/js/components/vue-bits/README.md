# Vue Bits components

Source: https://github.com/DavidHDev/vue-bits
Documentation: https://vue-bits.dev
Retrieved: 2026-10-07. Copyright David Haz; see LICENSE.md.

- SpotlightCard is adapted from src/content/Components/SpotlightCard/SpotlightCard.vue. Changes: olive/gold defaults, semantic content layering, keyboard focus within, reduced-motion support, and configurable padding via classes.
- ShinyText is adapted from src/content/TextAnimations/ShinyText/ShinyText.vue (retrieved 2026-11-03, motion-v). All the original props are kept. Changes:
  - The shine is its own layer over the text color, so `baseGradient` can give the text a gradient (the brand yellow-to-olive) with the shine sweeping over it.
  - Renders with motion-v's `as` (the original passed `tag`, which motion-v ignores, so it rendered a div).
  - `baseClassName` replaces the fixed `inline-block`.
  - Reduced-motion support (no sweep, the colored text stays).
  - Used on "Industrial Future" in the Welcome hero: disabled until the heading's word reveal fires `word-reveal-complete`, then it loops.

Text on the public pages no longer uses Vue Bits reveal components (ScrollReveal, SplitText and FadeContent were removed on 2026-11-03). It uses the `v-word-reveal` directive in `resources/js/directives/wordReveal.ts` (Motion + Motion+ `splitText`): words fade in and slide up when the page mounts, never on scroll.

Keep motion on presentation cards and section introductions, not on data tables, dialogs, or critical form controls.
