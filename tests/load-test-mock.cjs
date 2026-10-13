const http=require('node:http'),{WebSocketServer}=require('../tools/load-tests/node_modules/ws'),fs=require('node:fs'),path=require('node:path'),{spawn}=require('node:child_process');
const port=Number(process.env.TEST_LOAD_PORT||18120),folder=path.resolve(__dirname,'../tools/load-tests');let next=51,accountsBySession=new Map(),delivered=0,pong=0;
const sockets=new Map();
const server=http.createServer((req,res)=>{let body='';req.on('data',b=>body+=b);req.on('end',()=>{
  let user=Number((req.headers.cookie||'').match(/mock_session=(\d+)/)?.[1]||0);const json=(value,status=200)=>{res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify(value));};
  if(req.url==='/API/auth/csrf-token.php'){json({success:true,token:'csrf'});return;}
  if(req.url==='/API/auth/login.php'){const data=JSON.parse(body);user=Number(data.identifier.match(/_(\d+)@/)?.[1]);res.setHeader('Set-Cookie','mock_session='+user+'; Path=/');accountsBySession.set(user,true);json({success:true});return;}
  if(!user){json({error:'Unauthenticated'},401);return;}
  if(req.url.startsWith('/API/chat/get-messages.php')){json({success:true,messages:[{id:1}],has_more:false});return;}
  if(req.url.startsWith('/API/chat/get-channel')){json({success:true,channels:[],channel:{id:1}});return;}
  if(req.url==='/API/auth/ws-token.php'){json({token:'ratchet-'+user});return;}
  if(req.url==='/API/auth/realtime-token.php'){const data=JSON.parse(body);json({token:data.mode+'-'+user,inbox:'inbox:user'+user});return;}
  if(req.url==='/API/chat/send-message.php'){if(process.env.MOCK_FAIL_WRITES){json({error:'Write refused'},403);return;}const id=next++;json({success:true,message:{id}},201);for(const socket of sockets.values())socket.send(JSON.stringify({push:{channel:'inbox:user'+user,pub:{data:{type:'chat_changed',kind:'channel',target_id:1,message_id:id,event_id:id}}}}));delivered++;return;}
  json({error:'Missing endpoint'},404);
});});
const ws=new WebSocketServer({noServer:true});server.on('upgrade',(req,socket,head)=>ws.handleUpgrade(req,socket,head,client=>ws.emit('connection',client,req)));
ws.on('connection',(socket,req)=>{let uid=0;const ping=setInterval(()=>{if(req.url.startsWith('/realtime/')&&socket.readyState===1)socket.send('{}');},1000);socket.on('close',()=>{clearInterval(ping);if(sockets.get(uid)===socket)sockets.delete(uid);});socket.on('message',raw=>{const data=JSON.parse(raw);
  if(req.url==='/ratchet'){if(data.type==='auth'){if(process.env.MOCK_REFUSE_AUTH || typeof data.ws_token!=='string'){socket.send(JSON.stringify({type:'error',message:'Invalid auth: ws_token required'}));return;}socket.send(JSON.stringify({type:'auth_ok',user_id:Number(data.ws_token.split('-')[1])}));}if(data.type==='join_channel')socket.send(JSON.stringify({type:'joined_channel',channel_id:1}));return;}
  if(data.id===1){uid=Number(data.connect.token.split('-')[1]);socket.send(JSON.stringify({id:1,connect:{client:'test',version:'6.9.6'}}));}
  if(data.id===2){sockets.set(uid,socket);socket.send(JSON.stringify({id:2,subscribe:{recoverable:true}}));}
  if(Object.keys(data).length===0)pong++;
});});
server.listen(port,'127.0.0.1',()=>{
 const target={fixture_id:'k6-20261012-110000-aabbccdd',base_url:'http://127.0.0.1:'+port,ws_url:'ws://127.0.0.1:'+port+'/ratchet',server_id:1,channel_id:1};fs.writeFileSync(folder+'/target.json',JSON.stringify(target));fs.writeFileSync(folder+'/accounts.json',JSON.stringify([1,2].map(id=>({id,email:'k6_aabbccdd_'+id+'@example.invalid',password:'a'.repeat(48)}))));
 const binary=process.env.K6_BIN||'k6';const child=spawn(binary,['run','-e','VUS=2','-e','RAMP_SECONDS=1','-e','HOLD_SECONDS=12','-e','INTERVAL_SECONDS=1','-e','CONNECTION_SECONDS=10','-e','WRITE_EVERY=1','chat-capacity.js'],{cwd:folder,stdio:['ignore','pipe','pipe']});let output='';child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>output+=d);
 child.on('error',error=>{console.error(error.message);process.exit(1);});child.on('exit',code=>{for(const socket of ws.clients)socket.terminate();ws.close();server.close();fs.unlinkSync(folder+'/target.json');fs.unlinkSync(folder+'/accounts.json');
   const result=JSON.parse(fs.readFileSync(folder+'/results-2-users.json','utf8'));if(process.env.MOCK_REFUSE_AUTH){if(code===0||result.ratchet.rate!==0||!output.includes('authentication_rejected')){console.error(output);process.exit(1);}console.log('Real k6 correctly reports Ratchet authentication rejection');}
   else if(process.env.MOCK_FAIL_WRITES){if(code===0||result.api.rate===1){console.error(output);process.exit(1);}console.log('Real k6 correctly rejects failed message writes');}
   else{if(code!==0||accountsBySession.size!==2||!delivered||!pong||result.own_delivery.rate!==1){console.error(output);process.exit(1);}console.log('Real k6 smoke passed: unique logins, CSRF, reads, Ratchet, broker pings and 201 message delivery');}
 });
});
