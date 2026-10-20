# Optional Tiptap message composer

Open-source Tiptap 3.31.4 adds bold, italic, strikethrough, inline code, code blocks, bullet/numbered lists, quotes and undo to the existing channel, direct and group composers. The committed local bundle uses the official Markdown extension, Marked and DOMPurify; license notices accompany it. No paid/cloud editor service is required, and no new process, database migration or server dependency is added.

`CHAT_RICH_COMPOSER=false` is the default. The flag enables both composition and sanitized Markdown message rendering. The existing PHP endpoints, permissions, Uppy attachments, Centrifugo delivery, Ratchet signaling and LiveKit calls remain in use. Channel drafts remain local/private. Shared Yjs draft editing is deliberately outside this upgrade's scope.

## Behavior

- Enter sends a normal message. Shift+Enter adds a line break. Enter inside a list, quote or code block continues that block; Ctrl/Cmd+Enter sends from there.
- Existing channel mention suggestions and emoji insertion use the editor selection. Username underscores remain compatible with existing mention matching.
- Programmatic clears, channel changes and draft restoration reset undo history so undo cannot bring another channel's draft into the current composer.
- Formatting is stored as Markdown in existing text fields; raw HTML stays literal in messages. Rendering allows only basic formatting and safe HTTP/HTTPS/mailto links, opens links with noopener/noreferrer, and never embeds HTML scripts, images or iframes. Attachments keep using their existing authorized upload flow.
- Inline channel edits expose original Markdown and render the saved/cancelled value through the same safe renderer. DM/group panels attach and destroy their editor as they open/close.
- The 4,000-character composer limit counts formatting syntax. Existing messages and longer AI responses can still be read.
- Switching the flag off restores the plain input; formatted messages then show their stored Markdown text. Existing message data is not rewritten.

## Deploy on the current VPS

Copy only this block into the VPS terminal:

```bash
cd ~/ecollab-inspect
ECOLLAB_PREVIOUS=$(git rev-parse HEAD)
git switch ecollab-collabs && git pull --ff-only origin ecollab-collabs &&
bash scripts/deploy-chat-composer.sh "$ECOLLAB_PREVIOUS"
```

The helper records a prior commit, archives chat assets and configuration before changing the environment, grants www-data access to runtime assets, backs up `.env` privately outside the web root, enables the flag and reloads PHP. Existing database/container state is unchanged. Hard-refresh both users' chat pages afterward.

Test formatting in channel, private and group messages; send an image with formatted text; restore a channel draft; insert an emoji and mention; switch channels and verify undo does not restore the old draft; test mobile keyboard/newlines and code-block Ctrl+Enter. Reconnect to verify Centrifugo still delivers formatted messages. Check that plain legacy messages and Jarred responses remain readable.

## Rollback

```bash
cd ~/ecollab-inspect
php scripts/configure-chat-composer.php disable && sudo systemctl reload php8.3-fpm
```

Hard-refresh chat pages. For code rollback use the recorded commit in the printed backup directory and your existing code rollback process. This upgrade needs no database restore.

## Build and tests

`npm ci --ignore-scripts --prefix tools/chat-composer`; `npm run build --prefix tools/chat-composer`; `node tests/chat-composer-regression.cjs`.

Tests exercise the real bundled editor, serialization/rendering, channel drafts, mention/emoji insertion, disabled state, Enter/Shift+Enter/code-block controls, length limits, unsafe HTML/links, dynamic private/group panels and cleanup. The existing delivery/upload/voice/UI checks run separately.

Official documentation: https://tiptap.dev/docs/editor/markdown and https://tiptap.dev/docs/editor/extensions/functionality/starterkit.
