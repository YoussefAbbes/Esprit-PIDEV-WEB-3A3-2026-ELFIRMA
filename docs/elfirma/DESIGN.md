# Design System Document: Organic Brutalism for Tunisian Agriculture

## 1. Overview & Creative North Star: "The Digital Terroir"
The vision for this design system is to reconcile the raw, uncompromising utility of Brutalism with the tactile, rhythmic beauty of the Tunisian landscape. We move away from "software as a tool" and toward "software as an extension of the land."

**Creative North Star: The Digital Terroir.**
This system rejects the sterile, "perfect" geometry of Silicon Valley. Instead, it embraces intentional asymmetry, expansive negative space, and tonal layering. It feels rooted in the earth (Organic) but possesses a bold, structural authority (Brutalism). We replace rigid lines with soft, expansive surfaces, mimicking the way sunlight hits a desert dune or an olive grove.

---

## 2. Colors: Tonal Earth & Atmospheric Depth
Our palette is a tribute to the Tunisian soil and the lush greenery of the Medjerda Valley. 

### The "No-Line" Rule
**Explicit Instruction:** 1px solid borders are strictly prohibited for sectioning. Boundaries must be defined solely through background color shifts or subtle tonal transitions.
*   **Surface Hierarchy:** Use `surface` (#fbfbe2) for the base canvas.
*   **Nesting:** Place a `surface-container-low` (#f5f5dc) section over a `surface` background to define a sidebar or secondary panel. To highlight a specific card, use `surface-container-lowest` (#ffffff) to create a crisp, white "lift."

### The "Glass & Gradient" Rule
To elevate the dashboard beyond a standard grid, use Glassmorphism for overlays and navigation bars.
*   **Glass Specs:** Use `surface` at 70% opacity with a `20px` backdrop-blur. 
*   **Signature Textures:** For high-impact areas (e.g., "Récolte Prévue" or "Santé du Sol"), use a subtle linear gradient transitioning from `primary` (#0a2200) to `primary_container` (#173901). This provides a deep, "velvet" texture that flat colors cannot achieve.

| Token | Hex | Role |
| :--- | :--- | :--- |
| `primary` | #0a2200 | Brand essence, high-authority CTAs |
| `primary_container` | #173901 | Deep forest headers, organic structure |
| `secondary` | #934b19 | Terracotta accents, soil-related data, alerts |
| `surface` | #fbfbe2 | The primary canvas (Cream) |
| `surface_container_low` | #f5f5dc | Secondary layout sections (Beige) |

---

## 3. Typography: Editorial Authority
We use a high-contrast scale to mirror the "Brutalist" aspect of the system—bold, oversized headings paired with utilitarian body text.

*   **Display & Headlines (Inter):** These are our "structural beams." They should be tight-tracked (-2%) and bold. Use `display-lg` for hero metrics like "Rendement de l'Oliveraie."
*   **Body & Labels (Open Sans):** Our "organic" element. Open Sans provides high legibility for complex agricultural data and French syntax.
*   **Editorial Hierarchy:** Use `headline-sm` in `primary` color for section titles to create a strong anchor. Use `label-md` in `on_surface_variant` (#43493d) for metadata, ensuring it feels like a footnote in a premium journal.

---

## 4. Elevation & Depth: Tonal Stacking
We reject traditional drop shadows in favor of "Tonal Layering."

*   **The Layering Principle:** Depth is achieved by "stacking" the surface tiers. A `surface-container-highest` (#e4e4cc) card floating on a `surface` (#fbfbe2) background creates a soft, natural recession.
*   **Ambient Shadows:** If a card requires a "floating" state (e.g., a modal or a primary action card), use a shadow with a blur of `40px` and an opacity of `6%`. The shadow color must be `on_surface` (#1b1d0e), never pure black.
*   **The "Ghost Border" Fallback:** If accessibility requires a container boundary, use the `outline_variant` (#c3c9b9) at **15% opacity**. This creates a "suggestion" of a border without breaking the organic flow.

---

## 5. Components: Organic Primitives

### Cards (The Core Container)
*   **Style:** No borders. `border-radius: 2rem` (xl) for main containers; `1rem` (default) for nested elements.
*   **Spacing:** Aggressive internal padding (32px+) to allow the content to breathe. Use vertical white space instead of divider lines.

### Buttons (Tactile Action)
*   **Primary:** `primary` background, `on_primary` text. Large radius (`full`).
*   **Secondary:** `secondary_fixed` (#ffdbc9) background with `on_secondary_container` (#753502) text.
*   **Interaction:** On hover, shift the background to a subtle gradient rather than a solid color change.

### Data Inputs (The Field Entry)
*   **Text Fields:** Use `surface_container_highest` as the background. No bottom line. Use `1rem` corner radius. 
*   **Error State:** Use `error` (#ba1a1a) for the label text, but the input container should only receive a subtle `error_container` tint.

### Agricultural Custom Components
*   **The "Harvest Gauge":** A wide, horizontal progress bar using a gradient from `secondary` (Terracotta) to `primary` (Forest Green) to visualize crop maturity.
*   **Contextual Weather Chips:** Glassmorphic chips that float over the dashboard header, providing real-time Tunisian climate data (e.g., "Sidi Bouzid: 32°C, Vent du Sud").

---

## 6. Do’s and Don'ts

### Do:
*   **Do** embrace asymmetry. It is okay if the left column is significantly wider or taller than the right.
*   **Do** use "French Editorial" spacing—generous margins and clear, bold typography.
*   **Do** use images of Tunisian agriculture (olive trees, irrigation systems) with a subtle desaturated filter to match the `surface` tones.

### Don't:
*   **Don't** use 1px solid lines or dividers. Use white space or `surface-variant` color shifts to separate content.
*   **Don't** use pure #000000 for text. Use `on_surface` (#1b1d0e) for a softer, premium feel.
*   **Don't** use sharp corners. Everything must feel "eroded" and soft, like stones in a riverbed, with a minimum radius of 16px.

---

## 7. Language & Localization
The interface is in **French**, tailored for the Tunisian context. 
*   Use professional but accessible terminology: *Tableau de Bord* (Dashboard), *Gestion des Parcelles* (Plot Management), *Suivi de l'Irrigation* (Irrigation Tracking).
*   Ensure date formats follow `DD/MM/YYYY`.
*   Currency should be displayed as `TND` or `DT`.