# Chat dates, unread messages and profiles

Deploy on the existing application host:

```sh
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
php database/migrate.php
```

Migration 058 adds per-user read cursors for channels, direct messages and groups. Existing channel and DM read timestamps serve as the initial fallback. Historical group messages have no previous read cursor and initially count as unread. Read cursors advance monotonically after authorized message fetches; older history does not advance them. Hidden tabs and readers scrolled away from the latest messages fetch without marking new messages read. Returning to the bottom acknowledges visible messages. The unread line remains at the visit's first unread message until reopening the conversation.

SQL timestamps are interpreted as UTC, matching the chat services. Each message shows its weekday and local clock time. Calendar dividers change at midnight in the viewer's device timezone, including in channel, direct and group conversations. Older loaded history receives the same separators. No server timezone setting needs to change.

Profiles load after the legacy chat scripts and use delegated ID-based links from message avatars, names, member rows and DM headers. Names containing quotes no longer form inline JavaScript. Slow profile requests cannot replace a newer selection. Escape closes the card and restores focus.

After deployment, hard-refresh the chat page. Check a conversation spanning local midnight, reopen a conversation with an unread message from another user, and open profiles from messages and the member list. The regression tests use isolated data and never contact production.
