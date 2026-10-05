// npm install --no-save --prefix /tmp/ecollab-test jsdom
// NODE_PATH=/tmp/ecollab-test/node_modules node tests/livekit-calls-regression.cjs
const {JSDOM}=require('jsdom');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const code=fs.readFileSync(path.join(root,'assets/js/chat/dm-call.js'),'utf8');
const settings=fs.readFileSync(path.join(root,'assets/js/calls/media-settings.js'),'utf8');
const tick=()=>new Promise(r=>setTimeout(r,0));
const events=['ParticipantConnected','ParticipantDisconnected','TrackMuted','TrackUnmuted','LocalTrackPublished','LocalTrackUnpublished','ActiveSpeakersChanged','TrackSubscribed','TrackUnsubscribed','Reconnecting','Reconnected','Disconnected'];
const clients=[];
function client(id){
 const dom=new JSDOM('<script src="/assets/js/chat/socket.js"></script>',{url:'https://ecollab.test/modules/chat/chat.php',runScripts:'outside-only'});const w=dom.window;
 w.HTMLMediaElement.prototype.play=()=>Promise.resolve();w.HTMLMediaElement.prototype.pause=()=>{};
 w.ECOLLAB_CALLS_CONFIG={userId:id,baseUrl:'',csrfToken:'test',wsUrl:'wss://test'};
 w.DM={activePartnerId:id===1?2:1,activePartnerName:'Peer',activeGroupId:null};
 Object.defineProperty(w.navigator,'mediaDevices',{value:{getDisplayMedia(){},enumerateDevices:async()=>[],getSupportedConstraints:()=>({noiseSuppression:true})}});
 w.showToast=()=>{};w.fetch=async()=>({ok:true,json:async()=>({success:true,url:'wss://test',token:'test',room_name:'Study group'})});
 class Track {constructor(kind){this.kind=kind;this.attached=[];}attach(el){el=el||w.document.createElement(this.kind==='audio'?'audio':'video');this.attached.push(el);return el;}detach(el){const result=el?[el]:this.attached;this.attached=this.attached.filter(x=>!result.includes(x));return result;}}
 class Participant {constructor(identity){this.identity=identity;this.name=identity;this.trackPublications=new Map();this.isMicrophoneEnabled=false;this.isCameraEnabled=false;this.isSpeaking=false;}getTrackPublication(source){return this.trackPublications.get(source);}async setMicrophoneEnabled(on){this.isMicrophoneEnabled=on;this.set('microphone',on,'audio');}async setCameraEnabled(on){this.isCameraEnabled=on;this.set('camera',on,'video');}async setScreenShareEnabled(on,capture,publish){this.isScreenShareEnabled=on;this.lastScreen={capture,publish};this.set('screen_share',on,'video');}set(source,on,kind){let pub=this.trackPublications.get(source);if(!pub){pub={source,track:new Track(kind),isMuted:!on};this.trackPublications.set(source,pub);}pub.isMuted=!on;this.room?.emit(on?'TrackUnmuted':'TrackMuted',pub,this);}}
 class Room {constructor(){this.localParticipant=new Participant('user-'+id);this.localParticipant.room=this;this.remoteParticipants=new Map();this.activeSpeakers=[];this.events={};}on(e,fn){(this.events[e]??=[]).push(fn);return this;}emit(e,...args){for(const fn of this.events[e]||[])fn(...args);}async connect(){}async startAudio(){}disconnect(){this.disconnected=true;this.emit('Disconnected');}}
 w.LivekitClient={Room,RoomEvent:Object.fromEntries(events.map(x=>[x,x]))};
 const sent=[];w.wsSend=data=>{sent.push(data);return true;};w.eval(settings);w.eval(code);
 const c={dom,w,sent,Participant,Track};clients.push(c);return c;
}
(async()=>{
 const a=client(1),b=client(2);
 await a.w.startDmCall(true);
 await a.w.EcollabCalls.handle({type:'dm_call_offer_sent',log_id:20,target_user_id:2});
 await b.w.EcollabCalls.handle({type:'dm_call_offer',media:'livekit',log_id:20,from_user_id:1,from_username:'A',is_video:true});
 assert(b.w.document.querySelector('.is-ringing'));
 b.w.document.querySelector('[data-action=accept]').click();await tick();await tick();
 await a.w.EcollabCalls.handle({type:'dm_call_answer',log_id:20,from_user_id:2});
 const ar=a.w.EcollabCalls.room,br=b.w.EcollabCalls.room;
 assert(ar&&br);assert(ar.localParticipant.isCameraEnabled&&br.localParticipant.isCameraEnabled);
 const remote=new a.Participant('user-2');remote.isCameraEnabled=true;remote.set('camera',true,'video');ar.remoteParticipants.set(remote.identity,remote);ar.emit('ParticipantConnected',remote);
 assert.equal(a.w.document.querySelectorAll('.ec-call-tile video').length,2);
 a.w.document.querySelector('[data-action=camera]').click();await tick();
 assert.equal(ar.localParticipant.isCameraEnabled,false);assert.equal(br.localParticipant.isCameraEnabled,true);assert.equal(remote.isCameraEnabled,true);
 assert.equal(a.w.document.querySelectorAll('.ec-call-tile video').length,1);
 await a.w.EcollabCalls.handle({type:'dm_call_end',log_id:999,from_user_id:2});assert(a.w.EcollabCalls.room,'foreign call end ignored');
 const retained=a.w.document.querySelector('.ec-call-tile video');
 a.w.document.querySelector('[data-action=expand]').click();assert(a.w.document.querySelector('.is-expanded'));
 a.w.document.querySelector('[data-action=minimize]').click();assert(a.w.document.querySelector('.is-minimized'));
 assert.equal(a.w.document.querySelector('[data-action=minimize]').textContent,'Restore');
 a.w.document.querySelector('[data-action=minimize]').click();assert(a.w.document.querySelector('.is-expanded'),'restore previous expanded size');
 assert.equal(a.w.document.querySelector('.ec-call-tile video'),retained,'resizing does not rebuild video');
 a.w.document.querySelector('[data-action=expand]').click();assert(!a.w.document.querySelector('.is-expanded'));
 assert.equal(ar.localParticipant.isCameraEnabled,false,'layout does not change camera');
 a.w.endDmCall();b.w.endDmCall();
 assert.equal(a.w.document.querySelectorAll('.ec-call, audio').length,0);
 a.w.DM.activeGroupId=7;await a.w.startDmCall(true);const group=a.w.EcollabCalls.room;
 assert(a.w.document.querySelector('.ec-call-tile[data-local=true] video'),'group local preview');
 for(let i=3;i<11;i++){const p=new a.Participant('user-'+i);p.set('camera',true,'video');group.remoteParticipants.set(p.identity,p);}
 group.activeSpeakers=[group.remoteParticipants.get('user-10')];group.emit('ActiveSpeakersChanged');
 assert.equal(a.w.document.querySelectorAll('.ec-call-tile').length,6);assert.match(a.w.document.querySelector('.ec-call-grid').textContent,/user-10/);
 for(let i=0;i<6;i++)group.emit('ActiveSpeakersChanged');
 for(const p of group.remoteParticipants.values())assert(p.getTrackPublication('camera').track.attached.length<=1,'no leaked video elements');
 a.w.document.querySelector('[data-action=screen]').click();await tick();assert.equal(group.localParticipant.lastScreen.publish.screenShareEncoding.maxBitrate,6000000);
 a.w.document.querySelector('[data-action=minimize]').click();assert(a.w.document.querySelector('.is-minimized'));
 a.w.endDmCall();
 // Closing while authorization is pending cannot resurrect a camera/call.
 let complete;a.w.fetch=()=>new Promise(r=>complete=r);const pending=a.w.startDmCall(true);await tick();a.w.endDmCall();complete({ok:true,json:async()=>({success:true,url:'wss://test',token:'x'})});await pending;assert(!a.w.EcollabCalls.room);
 for(const c of clients)c.dom.window.close();
 console.log('PASS: independent cameras, local group preview, call-id isolation, six tiles, cleanup, quality and cancellation');
})().catch(e=>{console.error(e);for(const c of clients)c.dom.window.close();process.exitCode=1;});
