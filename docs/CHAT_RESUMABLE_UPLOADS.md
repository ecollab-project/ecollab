# Chat attachment uploads with Uppy + tus

This upgrade preserves the existing channel, DM and group composers. Enable it explicitly with `CHAT_RESUMABLE_UPLOADS=true`. When disabled, eCollab uses its existing multipart upload endpoints. When enabled, a missing client bundle or failed tus request reports an error; it never silently restarts a partial file as a whole-file upload.

The browser bundle contains pinned MIT-licensed Uppy Core 5.2.0 and Uppy Tus 5.1.0 (which uses tus-js-client). Build sources, dependency lockfile and bundled legal notices are committed. No CDN dependency, new database table, Node service or additional daemon is required at runtime. Rebuild with `npm ci --prefix tools/chat-uploads` and `npm run --prefix tools/chat-uploads build`.

The PHP endpoint is an eCollab implementation of tus 1.0 core, creation and termination. It is not tusd or tus-php. It does not implement concatenation, deferred length, checksums, creation-with-upload or cross-origin access. It accepts 1 MB chunks, up to 20 MB per file. Network retries, pause/resume and cancel are provided by Uppy. After a page reload or exhausted retry, select the **same unchanged file in the same chat/account** to resume. Browser storage must be available. Transfer URLs expire after 24 hours; after expiration a fresh upload starts. A completed upload URL can be reused in the same chat during that period, including when final validation was interrupted. Changing chats never moves an in-flight attachment into the other composer. On phones the progress panel is at the top; on desktops it is at the bottom right.

Every request requires the logged-in owner; mutations and the completion GET require CSRF. The endpoint rechecks channel write access (membership, private channel, announcements and moderation) or DM/group membership against the stored target on every chunk/read. Session locks are released before chunk I/O. HEAD returns the durable file offset; PATCH locks the upload and rejects mismatched offsets without appending. Files are validated by server-side MIME detection only after all bytes arrive, then stored with a safe canonical extension. Accepted MIME types preserve the existing channel/DM policies; DM/group audio and video remain unsupported. Message persistence, delivery and attachment download authorization remain existing eCollab behavior. This upgrade alone does not change their policies or fix WebSocket delivery.

Unfinished bytes and metadata must be **outside** the web root. Default: `/home/ecollabadmin/ecollab-upload-parts` when the application is `/home/ecollabadmin/ecollab-inspect`. `CHAT_TUS_STORAGE` can override this with an absolute path whose parent exists. The PHP service must own/write it. The final `uploads` folder must be writable by PHP and readable by Nginx. The store caps unfinished uploads at ten per user and 512 MB of reserved space globally. Run the cleanup job hourly so expired partial bytes are removed. Published attachments are deliberately retained because messages may reference them; normal attachment lifecycle remains the application's responsibility. Metadata lock files are small and do not contain file contents.

## VPS rollout

From the application root, pull `ecollab-collabs`, grant group read to changed runtime files, and run:

```bash
php tests/chat-tus-regression.php
php tests/chat-upload-access-regression.php
sudo install -d -o www-data -g www-data -m 0750 /home/ecollabadmin/ecollab-upload-parts
sudo chgrp www-data uploads
sudo chmod g+rwx,g+s uploads
sudo -u www-data test -w uploads
```

Back up `.env` securely, set `CHAT_RESUMABLE_UPLOADS=true`, then reload `php8.3-fpm`. Do not restart the WebSocket server for this upload change. Hard-refresh the chat page. The proxy must forward PATCH, HEAD, POST, GET and DELETE to PHP, with a request-body limit of at least 1 MB plus protocol overhead (2 MB is sufficient). The existing 20 MB whole-file limit can stay unchanged.

Install a cleanup entry such as:

```cron
17 * * * * www-data /usr/bin/php /home/ecollabadmin/ecollab-inspect/scripts/cleanup-chat-uploads.php
```

Use `/etc/cron.d/ecollab-chat-uploads`, owned by root and mode 0644. Ensure the script and services are readable by www-data. Test cleanup with `sudo -u www-data php scripts/cleanup-chat-uploads.php`.

## Acceptance checks

1. Select an image or file in a channel; verify progress, Pause/Resume and Cancel. Send it and confirm the recipient receives the existing attachment message.
2. Repeat for a human DM and a group. Select a file larger than one chunk, disable the browser's network mid-transfer, reconnect and verify it resumes. Reload and reselect the exact same file in the same chat to verify recovery. The Network panel should show POST 201, PATCH 204, HEAD 200 on resume, and a final GET returning attachment metadata.
3. Switch channel/conversation while uploading. The attachment must never land in the new chat. Return to the original chat and select it again if needed.
4. Verify a nonmember, unauthorized private-channel member and a muted user cannot upload. Test with real accounts; unit checks do not replace deployed authorization testing.
5. Confirm calls/chat stay responsive during transfers. Test on desktop and phone with the existing LiveKit call active.

Rollback: set `CHAT_RESUMABLE_UPLOADS=false`, reload PHP-FPM and refresh the page. No schema rollback is required; existing messages and attachments stay intact. Pending tus transfers cannot continue while disabled and will expire via cleanup.
