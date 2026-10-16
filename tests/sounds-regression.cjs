'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
(async()=>{
const dom=new JSDOM('<button data-sound-preview="logo">Logo</button><p id="soundPreviewStatus"></p>',{url:'https://example.invalid',runScripts:'outside-only'}),w=dom.window,instances=[];let fail=false,resolveReady;
w.ECOLLAB_CALLS_CONFIG={baseUrl:'https://example.invalid'};w._userSettings={notification_sound:1,output_volume:40};w.EcollabMediaSettings={ready:new Promise(r=>resolveReady=r)};
class Audio{constructor(){this.currentTime=0;this.paused=true;this.plays=0;instances.push(this);}canPlayType(){return 'probably';}setSinkId(){return Promise.resolve();}play(){this.plays++;this.paused=false;return fail?Promise.reject(Error('Autoplay blocked')):Promise.resolve();}pause(){this.paused=true;}}
w.Audio=Audio;w.eval(fs.readFileSync('assets/js/sounds.js','utf8'));const sounds=w.EcollabSounds;assert.equal(instances.length,0,'No autoplay/preloading on page load');
const cancelled=sounds.ring();cancelled.stop();resolveReady();await new Promise(r=>setTimeout(r,0));assert.equal(instances[0].plays,0,'Cancelled ring cannot restart after settings arrive');
assert(await sounds.play('notification'));assert(instances.at(-1).src.endsWith('/assets/sounds/ecollab/notification.ogg'));assert.equal(instances.at(-1).volume,.2);assert.equal(await sounds.play('notification'),false,'Burst notifications coalesced');
const ring=sounds.ring();await new Promise(r=>setTimeout(r,0));assert(instances.at(-1).loop);assert.equal(instances.at(-1).plays,1);ring.stop();assert(instances.at(-1).paused);assert.equal(instances.at(-1).currentTime,0);
w._userSettings.notification_sound=0;assert.equal(await sounds.play('hop-in'),false);assert(await sounds.play('logo',{preview:true}),'Explicit preview works while automatic sounds disabled');w.dispatchEvent(new w.CustomEvent('ecollab:settings-applied'));assert(instances.every(a=>a.paused),'Disabling sound stops existing playback');
w.EcollabCalls={deafened:true};assert.equal(await sounds.play('hop-out'),false);assert.equal(await sounds.play('logo',{preview:true}),false,'Deafen silences previews too');w.EcollabCalls.deafened=false;w._userSettings.notification_sound=1;
Audio.prototype.canPlayType=()=>'';assert(await sounds.play('logo',{preview:true}));assert(instances.at(-1).src.endsWith('/logo.mp3'),'MP3 fallback');fail=true;assert.equal(await sounds.play('hop-out',{preview:true}),false,'Blocked autoplay is contained');
assert.equal(await sounds.play('../../config'),false);w.dispatchEvent(new w.Event('pagehide'));assert(instances.every(a=>a.paused||a===instances.at(-1)));dom.window.close();
for(const file of ['notification','ring','hop-in','hop-out','logo'])for(const ext of ['ogg','mp3'])assert(fs.statSync('assets/sounds/ecollab/'+file+'.'+ext).size>1000);
console.log('PASS: no autoplay, supplied assets, preference/output volume, MP3 fallback, throttling, cancellable ringing, deafen, previews and blocked playback');
})().catch(e=>{console.error(e);process.exitCode=1;});
