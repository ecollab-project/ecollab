# eCollab External Collaboration Integrations

This branch keeps eCollab as the primary application and adds optional open-source collaboration engines behind the existing collaboration workspace.

## Architecture

- eCollab owns authentication, channel membership, navigation, permissions and document storage.
- Etherpad provides realtime shared notes when `ETHERPAD_URL` is configured.
- Excalidraw can provide a drawing editor when an approved collaboration-capable deployment is configured with `EXCALIDRAW_URL`.
- ONLYOFFICE Docs provides Word documents, spreadsheets and presentations with realtime co-editing through the native eCollab connector implemented on this branch.
- Existing eCollab native tools are not removed or replaced.

## 1. Etherpad

Etherpad is Apache-licensed, supports realtime editing, has an HTTP API, and can be embedded in another application. Its API is designed so an existing application's user/permission system can be reused.

Official API: https://docs.etherpad.org/api/http_api.html

### Docker quick start

```powershell
docker pull etherpad/etherpad
docker run --detach --publish 9001:9001 --name ecollab-etherpad etherpad/etherpad
```

Then put this in the local `.env`:

```env
ETHERPAD_URL=http://localhost:9001
```

The eCollab hub derives a non-guessable pad name from the authorized channel and does not expose `APP_KEY` to the browser. For production, configure Etherpad authentication/authorization rather than relying only on a hidden pad URL.

## 2. ONLYOFFICE Docs Community

ONLYOFFICE Docs provides document, spreadsheet and presentation editors with realtime co-editing and integrates into custom web applications through `DocsAPI.DocEditor`. JWT is used to protect editor configuration and storage-service communication. Official documentation: https://api.onlyoffice.com/docs

### Docker quick start

```powershell
docker run -i -t -d -p 8088:80 --restart=always -e JWT_SECRET=CHANGE_ME onlyoffice/documentserver
```

Use a strong secret and put the same value in the eCollab `.env` as `ONLYOFFICE_JWT_SECRET`. Never commit the real secret.

### eCollab connector flow

The branch now contains:

- `database/migrations/015_onlyoffice_documents.sql` — channel-scoped document metadata.
- `services/OnlyOfficeService.php` — HS256 JWT signing/verification and signed document URLs.
- `API/collaboration/documents.php` — authenticated document list/create API.
- `API/collaboration/documents/file.php` — signed, non-session document delivery endpoint reachable by ONLYOFFICE Docs.
- `API/collaboration/documents/callback.php` — JWT-validated save callback that replaces the eCollab-owned file and rotates the document key.
- `modules/collaboration/documents/editor.php` — authenticated editor page using the ONLYOFFICE JavaScript API and fast co-editing mode.
- `modules/collaboration/index.php` — document library UI and DOCX/XLSX/PPTX creation controls.

The workflow is:

1. A logged-in user opens an authorized channel.
2. The user creates or opens a channel document.
3. eCollab verifies channel membership before exposing the document.
4. eCollab generates a signed editor configuration and signed file URL.
5. ONLYOFFICE loads the document and multiple users can co-edit it in real time.
6. ONLYOFFICE calls eCollab when the document is ready to save.
7. eCollab verifies the callback JWT and document key, stores the returned file, increments the version and rotates the key.

This follows the documented ONLYOFFICE storage-service pattern rather than pretending a raw Document Server URL is an editor. The document URL must be reachable by the Document Server, and the callback URL must be reachable from the Document Server as well.

### Environment

```env
ONLYOFFICE_DOCUMENT_SERVER_URL=http://localhost:8088
ONLYOFFICE_JWT_SECRET=your-strong-secret
```

The old `ONLYOFFICE_EDITOR_URL` variable is retained only for compatibility with the first hub draft; the working connector uses `ONLYOFFICE_DOCUMENT_SERVER_URL` and `ONLYOFFICE_JWT_SECRET`.

### Database and local setup

Run migration `015_onlyoffice_documents.sql` against the eCollab database. The application creates document files under `uploads/collab-docs/`; the existing `.gitignore` already excludes runtime uploads from source control.

The local entry point is:

`/modules/collaboration/index.php?channel_id=<AUTHORIZED_CHANNEL_ID>`

After migration and Docker startup, create a document from the Documents tab. Do not open the raw `:8088` Document Server URL as though it were an eCollab document editor.

## 3. Excalidraw

The Excalidraw editor is open source and can be integrated as an npm component. However, the official self-hosting documentation currently notes that the self-hosted client does not itself provide sharing/collaboration. Therefore eCollab must not pretend that a plain self-hosted Excalidraw iframe is a secure multi-user workspace.

Official repository: https://github.com/excalidraw/excalidraw

Set `EXCALIDRAW_URL` only when the chosen deployment includes the collaboration mechanism you have approved.

## Current branch behavior

The integration is deliberately additive. Existing eCollab shared notes, task board, code sandbox, timer, quiz, calendar, flashcards, mind map, peer review, summaries, goals, resources and native whiteboard remain available.
