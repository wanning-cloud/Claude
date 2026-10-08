# Design: Podcast-Cockpit („Plakat“, Werkzeug-Fassung)

The cockpit inherits the Monteur-Podcast design system „Plakat“ (Drive: „Monteur-Podcast Designsystem Plakat.md“) and tones it down for daily work: mostly white with ink, poster elements used on purpose. Mode: Operate.

## Tokens (`apps/web/src/styles.css`, `@theme`)

| Token | Value | Use |
| --- | --- | --- |
| `ink` | #0A0F16 | text, borders, shadows, dark fields |
| `blue` | #2E75B5 | active navigation, focus ring, chart series 1, info fields (white text) |
| `pink` | #E48B9B | primary button, „NEU“ sticker, chart series 2; only as a surface with ink text, never as text on white |
| `white` | #FFFFFF | page and cards |
| `muted` | #8B98A8 | disabled only |
| `paper` | #F3F5F8 | second neutral layer (table heads, panels); derived from ink, not a new accent |

Contrast: white on blue ≈ 4.9:1, ink on blue only for large text, ink on pink ≈ 8:1.

## Type

- Geist variable (400–900), Geist Mono 600, both self-hosted woff2 latin, `font-display: swap`.
- Page title: Geist 900, uppercase, 32–48 px, tracking −0.04 em, line-height 0.95.
- Body 16–17 px Geist 400/500. Labels: Geist Mono 600, 12–13 px, uppercase, tracking 0.12 em.
- Numbers: tabular figures everywhere (`font-variant-numeric: tabular-nums`).

## Shape and depth

- Borders 3 px (controls, rows) or 4 px (cards, page frames), always ink.
- Hard offset shadows, no blur: 4 px for controls, 6 px for cards, 8 px for the reach panel. This is the brand's poster language, explicitly pinned by the brief.
- No radius anywhere.

## Components

- **Button**: square, 3 px ink border, Geist 800 uppercase 14 px, 4 px shadow. Hover/active: moves 3 px right-down, shadow shrinks to 1 px. Primary pink, secondary white, send button ink with blue shadow. Disabled: muted border and text, no shadow.
- **Sticker**: pink, 3 px border, Geist 900 uppercase, rotated −3°, 3 px shadow. Used for „NEU“ and counts only.
- **Tag**: small, 2 px border, Geist Mono. Status and platform chips.
- **Card**: white, 4 px border, 6 px ink shadow. Never nested.
- **Stamp**: Geist Mono line under every value: „Stand 07.10.2026, 06:00 · API“ or the reason when missing.

## Charts

Series in blue, pink, ink and hatched ink; labels directly at bar or line end; maximum four series; every chart has a table view. Own SVG, loaded lazily.

## Layout

- Desktop: top bar (logo text, Übersicht · Folgen · Portale · Kommentare + count · Automatik, back link to podcast-admin).
- Phone: bottom bar with four items (Übersicht, Folgen, Kommentare, Automatik); Portale in the menu.
- Content max width 1240 px, 16 px gutter on phones, 32 px from 768 px.

## Motion

150–200 ms, transform and box-shadow only, honors `prefers-reduced-motion`.
