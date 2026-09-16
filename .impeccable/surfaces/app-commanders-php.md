---
version: 1
slug: "app-commanders-php"
primary_target: "app/commanders.php"
related_targets: ["app/assets/commanders.css"]
---

# Comandantes — Deckarium

Mode: Operate. Preserve the established ivory/forest palette and Spectral headings.

## Direction

An artwork-led collection index, not a promotional landing page. Start with a compact title and commander-name search. Recent additions occupy the main gallery; popularity is a distinct, narrower ordered list with thumbnails and an expandable explanation of the cached, general EDHREC card rank. No hero illustration, decorative panels, or scoring.

Desktop: three to four gallery columns with a supporting ranking rail. Intermediate desktop: two gallery columns. Mobile: two gallery columns, then the ranking list; navigation scrolls horizontally. Keep names and set names under artwork, with one keyboard stop per card. Preserve the full card ratio with automatic image height and prefer cached images.

## Content and behavior

- Search is server-rendered, restricted to commanders, limited to 12 matches, with explicit guidance to refine and a clear-search link.
- Recent means latest local import, not latest set release. Reprints and reversible alternate-art faces are deduplicated by their Oracle identity.
- Popularity uses locally imported EDHREC ranks, not live data or a commander-specific rank.
- Empty results explain recovery; missing artwork reserves the same card space.
- All artwork comes from the existing Scryfall catalog/cache; no generated or newly downloaded assets.

## Verification

Reviewed in the local browser at desktop and mobile sizes, including loaded art, search results and no-result recovery. Impeccable detector's image warning was caused by parsing a PHP conditional attribute; rendered images had valid sources and loaded successfully. Unrelated shell and relationship changes are preserved.
