# Design Spec: Mini S3 UI/UX Enhancement (Minimalist & Warm)

This document outlines the visual and interactive improvements for the Mini S3 admin interface (including installer, login, dashboard, and config screens) following a clean, editorial minimalist aesthetic.

## 1. Aesthetic Identity & Typography

We will replace the default browser fonts and stark colors with a warm, high-contrast monochrome design system.

- **Primary Typeface**: Sans-Serif font hierarchy:
  `'SF Pro Display', 'Geist Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`
- **Monospace Typeface** (for configuration variables, paths, and code snippets):
  `'Geist Mono', 'SF Mono', 'JetBrains Mono', Menlo, Monaco, Consolas, monospace`
- **Typography Sizing & Weights**:
  - Main titles (`h1`): Large, bold, tight tracking (`letter-spacing: -0.02em`).
  - Section headings (`h2`, `h3`): Monospace labels or crisp, weighted sans-serif.
  - Labels and meta-information: Smaller font size, slightly lighter gray.

## 2. Color Palette & Visual Elements

Color will be used selectively as a semantic indicator, avoiding heavy gradients or shadows.

| Variable / Element | Hex Color | Description / Styling |
| --- | --- | --- |
| Canvas (Background) | `#FBFBFA` | Off-white / warm bone tone for background body |
| Card / Panel Surface | `#FFFFFF` | Pure white background for content containers |
| Primary Borders | `#EAEAEA` | Thin, crisp boundaries (`1px solid #EAEAEA`) |
| Primary Text | `#111111` | Soft off-black for body copy |
| Secondary Text | `#787774` | Muted warm gray for descriptions & labels |
| Accent Color (Pastel Blue) | `#E1F3FE` (Text: `#1F6C9F`) | Subtle tag, notice, or active indicator background |
| Success Color (Pastel Green) | `#EDF3EC` (Text: `#346538`) | OK status, successful flash message |
| Warning/Error Color (Pastel Red) | `#FDEBEC` (Text: `#9F2F2D`) | Failed status, warning banners, form errors |

- **Borders & Shadows**: Crisp corners (`border-radius: 8px`). We will drop default SaaS card shadow styles and rely strictly on borders (`1px solid #EAEAEA`) for depth.
- **Header**: Replace the heavy dark `#101827` header background with a clean white `#FFFFFF` surface matching the minimal document aesthetic, separated by a thin bottom border (`border-bottom: 1px solid #EAEAEA`).

## 3. Interactive Enhancements

Adding transition durations and scale changes on click to simulate a physical feel:

- **Transitions**: Smooth transition time (`200ms ease`) on all interactive buttons, links, and input states.
- **Buttons**:
  - Primary button: Background `#111111`, text `#FFFFFF`, subtle hover color `#333333`.
  - Secondary/Action buttons (like snippet toggle/copy): Background `#F7F6F3`, border `#EAEAEA`, text `#111111`, hover background `#EAEAEA`.
  - Active State: Click behavior includes a subtle scale `transform: scale(0.98)` or `translateY(1px)`.
- **Forms**: Clean input borders (`#CBD5E1` transitioning to `#111111` on focus), with a clean `outline: none` replaced by a tight focus ring/border color shift.
- **Details (Advanced section)**: Styled to expand smoothly with a clean indicator and clear horizontal rules.

## 4. Layout Improvements

- **Dashboard Grid**: Make use of clean CSS Grid margins and alignment. The stat cards will have a modern, spacious feel.
- **Snippet Container**: The connection configurations (`pre`) will feature the monospace typography, set against a light background (`#F7F6F3`) instead of the dark contrast block, ensuring it integrates beautifully with the monochrome workspace theme.

## 5. Scope of Changes

All changes will be limited to:
- `src/Admin/AdminRenderer.php` (CSS styles inside the `<style>` tag, HTML structure of forms, headers, panels, code snippet copy section, and stat cards).
- Testing and verifying that form submission, updates check, upgrade capabilities, and S3 functionality remain completely unaffected.
