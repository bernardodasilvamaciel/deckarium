---
name: MTG Local
description: Catálogo pessoal de Magic com busca rápida e arte real das cartas.
colors:
  paper: "#f5f3ee"
  surface: "#fffefa"
  ink: "#171e26"
  muted: "#59625f"
  accent: "#3b594d"
  accent-hover: "#294437"
  line: "#d8d9d1"
  soft: "#e4ebe5"
typography:
  display:
    fontFamily: "Spectral, Georgia, serif"
    fontSize: "3.25rem"
    fontWeight: 700
    lineHeight: 1.2
  body:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "1rem"
    lineHeight: 1.5
rounded:
  sm: "6px"
  md: "10px"
spacing:
  sm: "8px"
  md: "24px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "#fffefa"
    rounded: "{rounded.sm}"
    padding: "12px 22px"
## Overview

Arquivo de colecionador: a interface funciona como um índice editorial pessoal, com a busca e as cartas reais no centro.

## Colors

Warm ivory is the reading canvas. Graphite anchors navigation. Forest green is reserved for primary actions, selected views, links and active navigation.

## Typography

Spectral is used for editorial headings. Segoe UI carries controls, metadata and body copy. Card names and set metadata remain compact and scannable.

## Layout

Desktop uses a fixed 224px navigation rail and a responsive content grid. Mobile collapses the rail into a compact top navigation and keeps two card columns.

## Elevation & Depth

Surfaces rely on fine borders and restrained shadows on real card art. Images are served at normal resolution and cached locally.

## Shapes

Controls use 6px corners; card art uses 9–10px corners; pills are reserved for compact status or set labels.

## Components

Primary search controls, active view toggles, edition icon rows, card tiles, paired upgrade images, empty states and download progress share the same paper, border and forest accent vocabulary.

## Do's and Don'ts

- Keep the search visible before exploratory content.
- Use real Scryfall card art and set icon SVGs; never emoji as content or controls.
- Preserve labels and recovery actions for empty, failed and loading states.
- Avoid decorative gradients, oversized marketing panels and repeated card art where a collection icon communicates the edition.
