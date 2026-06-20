---
name: Serene Professional
colors:
  surface: '#f6f9ff'
  surface-dim: '#c9dcf0'
  surface-bright: '#f6f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#ecf5ff'
  surface-container: '#e1f0ff'
  surface-container-high: '#d7ebfe'
  surface-container-highest: '#d1e5f8'
  on-surface: '#091d2b'
  on-surface-variant: '#41484a'
  inverse-surface: '#203241'
  inverse-on-surface: '#e6f2ff'
  outline: '#71787a'
  outline-variant: '#c1c8ca'
  surface-tint: '#00677f'
  primary: '#00677f'
  on-primary: '#ffffff'
  primary-container: '#13a0c3'
  on-primary-container: '#00313e'
  inverse-primary: '#62d4f9'
  secondary: '#356575'
  on-secondary: '#ffffff'
  secondary-container: '#b7e7fa'
  on-secondary-container: '#396979'
  tertiary: '#46636a'
  on-tertiary: '#ffffff'
  tertiary-container: '#7b99a0'
  on-tertiary-container: '#123137'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#b6ebff'
  primary-fixed-dim: '#62d4f9'
  on-primary-fixed: '#001f28'
  on-primary-fixed-variant: '#004e60'
  secondary-fixed: '#baeafd'
  secondary-fixed-dim: '#9ecee1'
  on-secondary-fixed: '#001f28'
  on-secondary-fixed-variant: '#194d5c'
  tertiary-fixed: '#c9e8f0'
  tertiary-fixed-dim: '#adccd4'
  on-tertiary-fixed: '#001f25'
  on-tertiary-fixed-variant: '#2e4b52'
  background: '#f6f9ff'
  on-background: '#091d2b'
  surface-variant: '#d1e5f8'
typography:
  headline-xl:
    fontFamily: Manrope
    fontSize: 40px
    fontWeight: '700'
    lineHeight: 48px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Manrope
    fontSize: 32px
    fontWeight: '600'
    lineHeight: 40px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Manrope
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
  body-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  label-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 20px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  unit: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  gutter: 24px
  margin-mobile: 16px
  margin-desktop: 48px
---

## Brand & Style

This design system embodies a calm, professional, and sophisticated personality. It is tailored for high-trust environments such as SaaS, Finance, or Health platforms where clarity and reliability are paramount. The aesthetic is rooted in **Corporate / Modern** principles with a leaning toward atmospheric tonal layering. 

The emotional response should be one of stability and ease. By utilizing a monochromatic teal spectrum with a vibrant primary accent, the UI minimizes cognitive load and creates a cohesive, immersive environment that feels intentional and premium.

## Colors

The palette is derived from a sophisticated teal and blue-gray scale, now enhanced with a more luminous primary action color to improve interactive clarity.

- **Primary:** A vibrant cyan-teal (#2BABCE) serves as the core action color, used for primary buttons, active states, and critical brand touchpoints.
- **Deep Shades:** #1F5161 (Secondary) and #0B1F2D (Neutral) are reserved for headers, sidebars, and high-contrast typography to provide a strong structural anchor.
- **Surface Tints:** #B8D7DF (Tertiary) and associated lighter variants function as background and container colors, creating a "breathable" interface.
- **Functional Neutrals:** The deepest shade (#0B1F2D) serves as the primary text color to maintain high legibility against the pale teal backgrounds.

## Typography

The typography system utilizes **Manrope** for headlines to provide a modern, slightly geometric character that feels premium. **Inter** is used for body copy and labels due to its exceptional legibility and systematic, utilitarian nature.

Hierarchy is established through weight and color:
- **Headlines:** Use the deep #1F5161 to command attention.
- **Body:** Uses the neutral #0B1F2D for maximum readability.
- **Captions/Labels:** Use the vibrant primary #2BABCE to differentiate secondary information without losing the brand's color presence.

## Layout & Spacing

This design system employs a **Fluid Grid** model based on a 4px baseline unit. 

- **Desktop:** 12-column grid with 24px gutters. Content is typically centered with a maximum container width of 1280px.
- **Tablet:** 8-column grid with 24px gutters.
- **Mobile:** 4-column grid with 16px gutters and 16px side margins.

Spacing between related elements (like an icon and text) should use `sm` (8px). Spacing between distinct sections within a card should use `md` (16px), while padding for top-level containers should use `lg` (24px) or `xl` (32px).

## Elevation & Depth

Depth is achieved through **Tonal Layering** rather than heavy shadows. This reinforces the clean, professional aesthetic.

- **Level 0 (Background):** Uses the softest surface tints derived from the tertiary palette.
- **Level 1 (Cards/Containers):** Uses white (#FFFFFF) with a very subtle, diffused shadow (0px 4px 20px rgba(11, 31, 45, 0.05)).
- **Level 2 (Popovers/Modals):** Pure white backgrounds with a more defined shadow to suggest proximity to the user.
- **Interactions:** Hover states on interactive elements should shift the background color or subtly increase the shadow spread.

## Shapes

The design system uses a **Rounded** shape language to soften the professional tone and make the UI feel approachable. 

- Standard components (Buttons, Inputs) use a 0.5rem (8px) corner radius.
- Larger containers (Cards, Modals) use a 1rem (16px) corner radius.
- Small decorative elements (Chips, Tags) may use a pill-shape (full rounding) to contrast against the more structured rectangular components.

## Components

### Buttons
- **Primary:** Background #2BABCE, Text #FFFFFF. 8px corner radius.
- **Secondary:** Border 1px #2BABCE, Text #2BABCE, Background transparent.
- **Ghost:** Text #1F5161, Background transparent.

### Input Fields
- Background: #FFFFFF or light tertiary tint.
- Border: 1px #B8D7DF. On focus: 2px #2BABCE.
- Text: #0B1F2D. Placeholder: #8BAFB7.

### Cards
- Background: #FFFFFF.
- Border: None, or a very light 1px #B8D7DF for definition on light backgrounds.
- Shadow: Subtle ambient shadow as defined in the Elevation section.

### Chips & Tags
- Background: #B8D7DF.
- Text: #1F5161 (High contrast for accessibility).
- Shape: Fully rounded (pill).

### Lists
- Use subtle horizontal dividers in #B8D7DF. 
- Active list items should use a soft background tint with a 4px #2BABCE left-accent border.