# Self-hosted chat emoji and animated stickers

Channels, direct messages and group chats share a searchable media picker. The
emoji button inserts Unicode into the existing draft; the sticker button inserts
a bundled sticker shortcode. Neither automatically sends or saves a message.
Existing send permissions, quotas and message persistence remain in
place. No database migration, API key, external API or new service is needed.

## Library and attribution
- Microsoft Fluent Emoji flat SVG artwork: MIT, copyright Microsoft Corporation.
  Source commit: 1ffb34c752ecf5d402f04cfb4b392c77f57c54bc.
  https://github.com/microsoft/fluentui-emoji
- Emoji Mart native metadata: @emoji-mart/data 1.2.1, Unicode 15 dataset; MIT,
  copyright Missive. https://github.com/missive/emoji-mart
- Animated Noto Emoji by Google: CC BY 4.0,
  https://creativecommons.org/licenses/by/4.0/ .
  https://googlefonts.github.io/noto-emoji-files/ . Original WebP files from the
  Google Fonts CDN, downloaded 2026-10-15. No artwork modifications.

The searchable Unicode catalog is broader than the bundled Fluent artwork.
Bundled emoji use Fluent SVGs; other emoji and skin variants retain native
Unicode rendering. This is a pinned catalog, not a promise of all new Unicode
releases. docs/licenses contains the MIT notices; the picker links to the public
modules/chat/media-credits.php page for artwork credits and licenses.

assets/media/provenance.json records exact source URLs, versions and SHA-256
hashes. Verify the installed files with python3 scripts/check-chat-media.py.
Do not delete notices when copying or updating assets.

## Behavior
- Recent selections are stored only in the browser's localStorage. Storage failure
  does not prevent picking emoji. No chat contents are stored there.
- Search matches names and keywords, not just broad category labels. Results are
  capped at 160 displayed tiles to keep the picker responsive.
- Supported skin choices insert real Unicode variants. Missing artwork falls back
  to the device's native emoji instead of changing the selected tone.
- Sticker messages use :ec-sticker:<approved-id>: as their stored text. Only exact
  allowlisted tokens render as images. Unknown tokens remain literal text.
- Rendering never changes stored message text or raw edit data. Markdown code,
  code blocks, links and editable fields are left alone.
- The picker supports keyboard focus, Escape, a close button, accessible labels
  and viewport clamping. Reduced-motion users receive static Fluent images for
  the bundled sticker animations. Static fallback coverage is verified offline.
- The library replaces the old fake GIF tiles (static emoji placeholders). It is
  an animated emoji sticker catalog, not a movie/meme GIF search service. Existing
  GIF/image file attachments still use the normal attachment flow.
- Loading metadata or images happens from the Ecollab origin. All upstream assets
  are bundled; no CDN requests occur at runtime. A failed metadata load offers
  retry after reopening without touching the draft.

## Deploy and verify
Pull ecollab-collabs on the VPS with umask 0022 and refresh the browser. There is
no environment or schema change. Run the offline manifest verification above.

Browser acceptance: choose an emoji in a channel draft, search 'rocket', choose
a skin tone, then choose and send an animated sticker. Repeat in a 1:1 chat and
a group chat; reload and verify history. Check that draft text and rich formatting
survive picker use. Enable reduced motion in the OS/browser and verify stickers
are static. Existing GIF uploads should still render.
