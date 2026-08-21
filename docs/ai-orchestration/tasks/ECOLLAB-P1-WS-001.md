# ECOLLAB-P1-WS-001 — WebSocket Channel Authorization

## Objective

Prevent an authenticated WebSocket client from subscribing to a channel unless the authenticated user is authorized to access that channel.

## Evidence baseline

The engineering audit/review identified an authorization gap in `websocket/ChatServer.php::handleJoinChannel()`: the connection is authenticated, but channel membership was not checked before the connection was added to the channel subscription structure. Authentication alone must not imply channel authorization.

## Required behavior

1. Authenticate the WebSocket connection using the existing token flow.
2. Resolve the authenticated user identity server-side.
3. Validate that the user is a member of the target server/channel according to the existing application access rules.
4. Only after authorization succeeds may the connection be inserted into the channel subscription structure.
5. On authorization failure, send the repository's established error frame and keep the WebSocket connection open unless the existing protocol explicitly requires closure.
6. Do not leak channel membership or private-channel information through error details.

## Acceptance criteria

- [ ] Unauthorized authenticated user cannot subscribe to an inaccessible channel.
- [ ] Authorized member can subscribe normally.
- [ ] Authorization occurs before the `channelSubs[$channelId][] = $conn` mutation.
- [ ] Authorization failure does not accidentally close the connection.
- [ ] Error frame matches the existing WebSocket protocol convention.
- [ ] Existing authorized chat behavior remains intact.
- [ ] Regression tests cover both authorized and unauthorized cases.
- [ ] Relevant PHP/static/CI checks pass.

## Required specialists

- Realtime: WebSocket lifecycle and subscription behavior.
- Backend: existing membership/access services.
- Security: authorization boundary and information leakage.
- Debugger: only if tests expose a pre-existing protocol/runtime issue.

## Required skills

- `ecollab-project-loop`
- `ecollab-realtime`
- `ecollab-security`
- `ecollab-regression`
- `ecollab-api-contracts` when error-frame/API contract evidence is needed

## Prohibited changes

- Do not replace the WebSocket architecture.
- Do not bypass existing authentication middleware/token validation.
- Do not add a second membership model if an existing authoritative membership service/query exists.
- Do not close sockets merely to hide an authorization failure.
- Do not modify unrelated chat behavior.
