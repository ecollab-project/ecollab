// eCollab Excalidraw integration entrypoint.
// The full editor is loaded from the official ESM package while eCollab retains
// authentication, Coworkspace permissions, persistence and WebSocket room scope.
import React from "https://esm.sh/react@19";
import { createRoot } from "https://esm.sh/react-dom@19/client";
import { Excalidraw } from "https://esm.sh/@excalidraw/excalidraw@0.18.0?external=react,react-dom";

const cfg = window.ECOLLAB_EXCALIDRAW || {};
const host = document.getElementById("excalidraw-root");
if (host) {
  const root = createRoot(host);
  root.render(React.createElement(Excalidraw, {
    viewModeEnabled: cfg.permission === "view",
    zenModeEnabled: false,
    gridModeEnabled: false
  }));
}
