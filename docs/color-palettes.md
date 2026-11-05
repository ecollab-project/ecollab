# Ecollab color customization

Forest & Lime is the default application palette. Its source colors are `#0B3D2E`, `#1E7F57`, `#A7D966`, `#F4F9F4`, and `#FFFFFF`. Settings → Appearance offers all 20 named combinations from the supplied reference, with the five source swatches visible on each card. Color palettes are independent of Dark / Light / System mode and avatar gradients. The selected palette saves to the authenticated user's account and applies to chat, taskbar, dashboards, settings and other Ecollab pages using the shared appearance assets. Public pages use Forest & Lime by default. Existing logos, uploaded content, editor syntax and status meanings retain their own colors.

Deploy on the application VPS:

```sh
cd ~/ecollab-inspect
git pull --ff-only origin feat/collaboration-assistance-matching
php database/migrate.php
```

The palette refresh retains the `forest-mint` ID, updates accounts already using the default and preserves other saved palettes and appearance modes. It requires no new migration.

Migration 059 adds `user_settings.color_palette`, with `forest-mint` as its default for existing and new accounts. It is safe to rerun and retains existing dark/light/system choices. No runtime service restart is required. Hard-refresh the browser after deployment.

The catalog is `assets/data/color-palettes.json`. Its source swatches match the reference; its UI shades adapt those colors for readable text and buttons in both modes. PHP validates palette IDs against this catalog, and the settings API requires the existing authentication and CSRF checks. Unlisted IDs are rejected with HTTP 422. A failed save restores the previous palette and shows an error.

Run `python3 scripts/build-color-palettes.py` after changing catalog UI colors to rebuild the CSS used before JavaScript loads. `--check` verifies the generated CSS. The DOM regression checks metadata, contrast, save behavior and rollback. The MariaDB test checks migration repeatability and persistence. Browser checks cover eight actual PHP page templates, all 20 palettes, both appearance modes and three viewport sizes.

The palettes are: Minimal Monochrome; Blue & Orange; Purple & Pink; Green & Neutral; Black & Gold; Red & White; Teal & Sand; Pastel Dream; Grey & Blue; Warm Earth Tones; Neon Pop; Sunset Vibes; Forest & Lime; Pink & Purple; Navy & Coral; Black & Purple; Beige & Brown; Cyan & Magenta; Grayscale + Accent; Vintage Retro.
