#!/bin/sh
set -eu
# Only reviewed settings enter the broker. Retired JWT keys and other inherited
# CENTRIFUGO_* overrides must not silently reopen parallel authentication.
for value in "${CENTRIFUGO_PROXY_SECRET:-}" "${CENTRIFUGO_HTTP_API_KEY:-}"; do
    case "$value" in ''|*[!a-zA-Z0-9_-]*) echo 'Invalid broker credentials' >&2; exit 1;; esac
    if [ "${#value}" -lt 32 ]; then echo 'Invalid broker credentials' >&2; exit 1; fi
done
headers=$(printf '{"X-Ecollab-Broker-Secret":"%s"}' "$CENTRIFUGO_PROXY_SECRET")
exec env -i PATH="$PATH" \
    CENTRIFUGO_HTTP_API_KEY="$CENTRIFUGO_HTTP_API_KEY" \
    CENTRIFUGO_CLIENT_PROXY_CONNECT_HTTP_STATIC_HEADERS="$headers" \
    CENTRIFUGO_CLIENT_PROXY_REFRESH_HTTP_STATIC_HEADERS="$headers" \
    centrifugo --config="${1:-/centrifugo/config.json}"
