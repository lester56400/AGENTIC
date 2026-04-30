---
name: ui-ux-pro-max
description: AI-powered design intelligence with 50+ styles, 95+ color palettes, and automated design system generation.
---

# UI/UX Pro Max Skill

This skill provides access to a comprehensive database of design patterns, styles, and color palettes for web and mobile applications.

## Core Capabilities

- **50+ UI Styles**: From Minimalism to Brutalism.
- **97 Color Palettes**: Tailored for various industries (Fintech, Healthcare, etc.).
- **57 Font Pairings**: Optimized for readability and aesthetic.
- **Design System Generation**: Automated creation of `MASTER.md` files.

## Usage Instructions

### Searching the Database

To find the best design system for a product:

```bash
# On Windows (PowerShell), use $env:PYTHONIOENCODING='utf-8' if you see encoding errors
python .agent/.shared/ui-ux-pro-max/scripts/search.py "<product_type> <industry> <keywords>" --design-system
```

### Domain-Specific Search

- **Styles**: `python .agent/.shared/ui-ux-pro-max/scripts/search.py "<query>" --domain style`
- **Charts**: `python .agent/.shared/ui-ux-pro-max/scripts/search.py "<query>" --domain chart`
- **UX Rules**: `python .agent/.shared/ui-ux-pro-max/scripts/search.py "<query>" --domain ux`

## Design Principles

- **No Emojis as Icons**: Use SVG icons from consistent sets (Lucide, Heroicons).
- **Stability**: Avoid scale transforms that cause layout shifts on hover.
- **Contrast**: Ensure WCAG 2.1 compliance for light and dark modes.
- **Topological Betrayal**: Break the "Standard Split" and "Bento Grid" clichés.
