# Ecollab project sound pack

Audio supplied by the project team through [Ecolllab(SFX)](https://drive.google.com/drive/folders/1d0IwdlUaBLaeK3n4M98tzhXCMx628emu).

| Original | Application use |
| --- | --- |
| Notif(Final).ogg | Existing message, mention and other live notification alerts |
| ring(Loop).ogg | Incoming and outgoing direct/group call ringing |
| HopIn(Final).ogg | Successful call/voice connection and later participant arrivals |
| HopOut(Final).ogg | Call/voice departure and participant departures |
| Logo(Final).ogg | Explicit brand-sound preview in notification settings |

Web copies retain the complete audio duration and remove embedded artwork and metadata. Each sound has Ogg Vorbis and MP3 copies, served from the application rather than Drive. Original input hashes are recorded in sources.json. These are project-supplied assets; no third-party open-source license is asserted here.

`assets/js/sounds.js` handles format selection, saved output volume/device, sound preferences, deafen, notification burst throttling and cancellable looping. No sound starts automatically on page load. Explicit Settings previews can play while automatic notification sounds are disabled. Ring playback stops on call acceptance, decline, cancellation, timeout and cleanup. Call previews/minimize controls never end the call or trigger departure sounds.

Browsers may block audio before user interaction. Blocked playback is contained and never disconnects a call. Background Web Push and lock-screen notification sounds are controlled by the browser/OS; service workers cannot play this custom audio pack. The custom notification sound applies while the Ecollab page can play audio.

Deployment only needs a normal Git pull and browser refresh. No database migration, service restart or compiler/realtime configuration change is required.
