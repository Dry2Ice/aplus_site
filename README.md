# Public page asset contract

## Goal
Shared visual tokens, shared UI component styles, and shared runtime theme synchronization for public pages must come from a single source of truth.

## Contract
- `assets/common.css` is the only place for shared public-page tokens and reusable component/system styles.
- `assets/common.js` is the only place for shared runtime theme synchronization, persisted UI settings, and shared Tailwind CDN configuration.
- Public HTML entry points (`index.html`, `404.html`, `batteries/index.html`, `copters/index.html`, `water/index.html`) must not duplicate base design-system CSS or shared Tailwind config inline.
- Local `<style>` blocks are allowed only for page-exclusive composition that is not reused by other public pages.
- Shared components must consume semantic tokens such as `--site-border`, `--site-text-muted`, and `--site-bg-elevated` instead of raw hex values.

## Practical rule
If a selector, token, animation, focus rule, button style, glass surface, mesh background, or theme behavior can be reused by another public page, move it to the shared asset layer instead of redefining it in an HTML file.
