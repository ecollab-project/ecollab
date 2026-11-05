# eCollab color customization

**eCollab Original** is the default palette, restored from the pink/violet, near-black look in the supplied Drive screenshots. Its source swatches are `#05010D`, `#FF2D75`, `#FF1493`, `#A855F7`, and `#FFFFFF`. The existing auth/landing design tokens also confirm these colors. Button shades are slightly darker than the bright screenshot gradient to keep white button labels readable. Light mode uses white surfaces, a faint pink/violet tint and dark text.

Settings → Appearance now offers 21 named palettes, including eCollab Original and **FCMS Burgundy & Coral** as separate choices. Palettes remain independent of Dark / Light / System mode and avatar gradients. Saved choices apply across chat, the taskbar, dashboards, settings, Code Lab and public entry pages through the shared appearance assets. Logos, uploaded content, editor syntax and status meanings retain their own colors.

Deploy on the application VPS:

```sh
cd ~/ecollab-inspect &&
git pull --ff-only origin feat/collaboration-assistance-matching &&
php database/migrate.php &&
sudo systemctl restart php8.3-fpm
```

Migration 069 sets `ecollab-original` as the database default and switches rows using the previous default slot (`forest-mint`) to it. Other saved palettes and dark/light/system settings are preserved. FCMS now has its own stable ID, `fcms-burgundy-coral`; choosing it after deployment is retained on subsequent visits. Migration 059 remains unchanged for existing migration history. Hard-refresh after deployment. No other services need restarting.

The catalog is `assets/data/color-palettes.json`. PHP validates palette IDs against it; saving requires the existing authentication and CSRF checks. A failed save restores the previous palette. Run `python3 scripts/build-color-palettes.py` after changing the catalog, or `--check` to verify the generated CSS. The first-paint CSS, JavaScript fallback and PHP account defaults all use `ecollab-original`.

Verification covers all 21 palettes in light/dark mode, contrast, saving and rollback. The MariaDB test checks repeatable migrations, restoration of legacy defaults, preservation of explicit choices and new-row defaults. Browser checks cover eight PHP page templates and three viewport sizes, including switching between Original and FCMS and persistence after reload. These tests use synthetic settings; production deployment remains a separate step.

Reference screenshots: https://drive.google.com/drive/folders/1bXAcT2ZMLIJAX8eow27dLkxoGA63bVbY (login `image_2026-09-27_110036353.png` and signup `image_2026-09-27_110625805.png`). Screenshot files are references only and are not bundled into the application.
