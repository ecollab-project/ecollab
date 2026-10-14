# Responsive device layouts

The shared device layer loads after page-specific styles on routed HTML templates and the student/facilitator workspace template. It adds narrow-screen card/form layouts, touch targets, scrollable tables, safe modal sizes, flexible headers and large-screen reading limits. Page-specific desktop layouts and the centered chat server taskbar remain in place.

`assets/js/device-layout.js` adds accessible scroll containers only to tables without an existing scroll parent. It retains original table elements, IDs and event handlers. It measures editor/whiteboard headers so wrapped controls do not cover the editing area. `mobile-viewport.js` tracks usable browser height and retains native pinch zoom.

Deploy from the VPS checkout:

```bash
cd ~/ecollab-inspect
bash scripts/deploy-responsive-layout.sh
```

No database migration or service restart is required. Public page/asset permissions are normalized; secrets and storage permissions are untouched.

## Verification

- `tests/device-layout-regression.cjs`: template coverage, dynamic table wrapping, preserved action handlers and editor header measurements.
- `tests/responsive-browser-regression.cjs`: renders actual PHP templates with isolated presentation fixtures and checks 240, 280, 320, 390, 568, 768, 1024, 1440, 1920, 2560 and 3840 CSS-pixel widths, including short landscape screens. Screenshots and measurements are uploaded by the Responsive page layouts workflow.
- Fixtures do not connect to production databases or services. The browser layout check does not replace signed-in workflow testing.

After deployment, verify navigation, chat composition, file upload, call minimize/restore, dialogs, permissions/report tables, document access and whiteboard editing on the browsers your users use. Collabora and Excalidraw own their internal interfaces; this change covers their eCollab shells. Operating-system text scaling, browser zoom and virtual keyboards also warrant testing on actual devices.
