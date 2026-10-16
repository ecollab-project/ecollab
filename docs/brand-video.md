# Ecollab logo animation

The project-supplied `AVP Logo Animation.mp4` is available from the landing page's **Watch Ecollab** button and the existing **About Us** section's **Play Ecollab logo animation** button. The footer About link now leads to that section.

Playback opens a native modal dialog with video controls, audio, Replay, Close and an Open video fallback link. The animation is fetched only after the viewer clicks a button; no video downloads or sound playback happen on initial page load. Closing with the button, Escape or the backdrop pauses and resets playback, unlocks page scrolling and returns focus to the entry button. Leaving the page also pauses audio. There is no dashboard/login splash or recurring animation.

The complete 5.6-second animation is served locally as an optimized 1080p H.264/AAC MP4 with fast start. Its original Drive file and hash are recorded in `assets/media/brand/sources.json`. A static poster is available before playback. These are team-supplied brand assets; no third-party open-source licensing claim is made.

Deploy with a normal `git pull --ff-only origin ecollab-collabs` and refresh the landing page. No migrations or worker changes are needed. The browser regression test checks actual video decoding, no pre-click MP4 requests, both buttons, phone/tablet/desktop sizes, replay, close/escape/backdrop behavior and focus restoration.
