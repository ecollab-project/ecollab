# eCollab External Collaboration Integrations

This branch keeps eCollab as the primary application and adds optional open-source collaboration engines behind `modules/collaboration/index.php`.

## Architecture

- eCollab owns authentication, channel membership, navigation, permissions and the workspace UI.
- Etherpad provides realtime shared notes when `ETHERPAD_URL` is configured.
- Excalidraw can provide a drawing editor when an approved deployment is configured with `EXCALIDRAW_URL`.
- ONLYOFFICE Docs can provide collaborative documents, spreadsheets and presentations through an eCollab connector configured with `ONLYOFFICE_EDITOR_URL`.
- Existing eCollab native tools are not removed or replaced.

## 1. Etherpad

Etherpad is Apache-licensed, supports realtime editing, has an HTTP API, and can be embedded in another application. Its API is explicitly designed so an existing application's user/permission system can be reused. See the official docs: https://docs.etherpad.org/api/http_api.html

### Docker quick start

```powershell
docker pull etherpad/etherpad
docker run --detach --publish 9001:9001 --name ecollab-etherpad etherpad/etherpad
```

Then put this in your local `.env`:

```env
ETHERPAD_URL=http://localhost:9001
```

The eCollab hub derives a non-guessable pad name from the authorized channel and does not expose `APP_KEY` to the browser.

For a real deployment, configure Etherpad authentication/authorization rather than relying only on a hidden pad URL. Etherpad supports authentication and authorization hooks and SSO/API-based integration.

## 2. ONLYOFFICE Docs Community

ONLYOFFICE Docs Community is open source and provides document, spreadsheet and presentation editors with realtime co-editing. It supports integration into a custom web application and JWT-signed requests.

Official API: https://api.onlyoffice.com/docs

Official Docker installation: https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-docker.aspx

Example installation:

```powershell
docker run -i -t -d -p 8088:80 --restart=always -e JWT_SECRET=CHANGE_ME onlyoffice/documentserver
```

Use a strong secret in the real environment. Do not commit it.

### Important

`ONLYOFFICE_EDITOR_URL` must point to an eCollab connector/editor page, not directly to the Document Server. A production connector must:

1. authorize the eCollab user and channel membership;
2. create/locate the requested document;
3. generate the ONLYOFFICE editor configuration;
4. sign the configuration with JWT;
5. validate ONLYOFFICE save callbacks;
6. keep document files in eCollab-controlled storage.

This separation keeps eCollab in charge of access control and avoids exposing raw storage endpoints.

## 3. Excalidraw

The Excalidraw editor is open source and can be integrated as an npm component. However, the official self-hosting documentation currently notes that the self-hosted client does not itself support sharing/collaboration. Therefore eCollab must not pretend that a plain self-hosted Excalidraw iframe is a secure multi-user workspace.

Official repository: https://github.com/excalidraw/excalidraw

Set `EXCALIDRAW_URL` only when the chosen deployment includes the collaboration mechanism you have approved.

## Current branch behavior

The new hub is deliberately additive. Existing eCollab shared notes, task board, code sandbox, timer, quiz, calendar, flashcards, mind map, peer review, summaries, goals, resources and native whiteboard remain available.

## Local entry point

After logging in, open:

`/modules/collaboration/index.php?channel_id=<AUTHORIZED_CHANNEL_ID>`

The page performs its own channel-membership check before showing the workspace.
