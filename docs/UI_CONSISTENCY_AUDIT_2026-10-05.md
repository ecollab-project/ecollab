# Chat, dashboards, and settings consistency review

Baseline: `98bdcdd85891abbf5d54872e311e0cd1b761c4a8` on `collabora-document-integration`.

## Verified findings and changes

- Phone settings navigation showed only one of thirteen buttons. All sections and return links now remain in a horizontally scrollable navigation bar.
- Shared `.modal` selectors treated the settings password backdrop as a dialog card: opaque surface, rounded edges, and a 32px-short viewport. The backdrop now has its own class and uses the available viewport height.
- Settings profile, theme choices, and password dialog retained hard-coded dark backgrounds in light mode. They now use shared surface, border, and text tokens.
- Settings controls contained invalid `font: ... inherit` shorthand; declarations are now explicit. Repeated section headings were consolidated into one changing page heading.
- Primary/secondary dashboard buttons used differing typography, corner radii, and padding. Shared styles now cover all three roles; destructive inline colors remain intact. A facilitator Schedule button's inline miniature sizing was removed.
- Dashboard and chat navigation used different selected-state treatments. Shared purple accent and selected-state styling now apply to dashboard navigation and chat's main navigation.
- Student/facilitator `.mo` / `.md` dialogs were omitted from shared mobile viewport sizing. Their backdrops now use viewport height and safe-area padding.
- Call settings used a hard-coded dark theme. Controls now follow shared theme tokens; text over video remains white for contrast.
- Settings switches were clickable `div`s. Native buttons now expose switch names and checked state, retain the existing persistence handler, and have 44px hit areas.

## Validation

- Rendered static templates and production CSS in Chromium at 390, 820, and 1440px widths for chat, settings, student, facilitator, and admin dashboards. Templates used placeholder data, without PHP execution or live backend requests.
- Checked mobile navigation availability, full-screen translucent settings backdrop, light settings surfaces, common button dimensions, and light call settings backgrounds. Reviewed settings screenshots.
- Ran settings controller with mocked settings/profile APIs: section switching, switch persistence/ARIA updates, and password dialog open/close passed.
- Existing call-state and media-settings JavaScript regressions passed.
- Parsed changed PHP templates with php-parser. Native PHP lint and authenticated VPS/device checks remain deployment steps.

## Separate unresolved findings

- The reported chat HTTP 500 needs the Nginx/PHP error log. This visual patch does not establish or repair its cause.
- Account Voice & Audio preferences and in-call media preferences currently use separate persistence paths. Styling consistency does not synchronize those settings.
- Full live content, real media devices, Safari/Firefox behavior, and every dashboard subpage were not exercised by static template rendering.
