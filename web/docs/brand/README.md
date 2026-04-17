# Paper Trail MD — Brand Reference

This folder is the single source of truth for brand reference docs and
production data.  Brand media (logos, overlays, images, etc.) lives under
`web/assets/brand/`.

---

## Docs in this folder

| File | Purpose |
|------|---------|
| `brand-guide.md` | Canonical brand guide — palette, typography, logo direction, overlay/thumbnail direction, posting rhythm |
| `posting-guide.md` | Weekly posting schedule with times, platforms, and goals |
| `brand_colors.json` | Machine-readable color definitions |
| `posting_schedule.json` | Machine-readable posting schedule |
| `social_images.json` | Social image metadata and references |
| `social_restrictions.json` | Platform-specific content restrictions |
| `social_time_limits.json` | Platform video duration limits |
| `teaser_script.md` | Teaser copy and script notes |

---

## Asset areas under `assets/brand/`

| Folder | Contains |
|--------|---------|
| `logos/` | Master logos and icon assets (SVG, PNG) |
| `overlays/` | Lower thirds, stamps, and UI textures |
| `watermarks/` | Watermark variants for video (light and dark) |
| `intros/` | Platform-specific intro notes and assets |
| `thumbnails/` | Thumbnail templates and exports |
| `images/` | Supporting brand images, collages, and UI screenshots |
| `images/brand_assets/` | Detective/evidence stock imagery |
| `images/brand_images/overlay_images/` | Overlay source images |

The `brand-manifest.json` at the root of `assets/brand/` is the canonical
asset index — it lists brand name, palette tokens, and contact information.

---

## Quick reference

**Primary palette** (from `brand-manifest.json` and `brand-guide.md`):

| Token | Hex | Usage |
|-------|-----|-------|
| Midnight Black | `#0B0C10` | Main background |
| Paper White | `#F5F5F3` | Text, contrast |
| Deep Navy | `#1C2A39` | Sections, headers |
| Slate Gray | `#2F3A40` | Cards, containers |
| Evidence Red | `#C1121F` | Alerts, key moments |
| Highlighter Yellow | `#FFD60A` | Callouts, emphasis |
| Forensic Teal | `#2EC4B6` | Data, links |
| Muted Gold | `#BFA181` | Premium accents |

**80 / 15 / 5 rule**: 80% dark, 15% neutral, 5% accent.  
Red and yellow should feel intentional, not decorative.

---

## Notes

- Placeholder media in `logos/`, `watermarks/`, `intros/`, and `thumbnails/`
  should be replaced with production-ready files as they are finalised.
- All former duplicate brand style docs that lived in `docs/markdown/` have
  been consolidated into `brand-guide.md` in this folder.
