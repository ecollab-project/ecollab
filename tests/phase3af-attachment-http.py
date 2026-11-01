"""Owned native HTTP security controls. No production connection or real-mail transport."""
import base64,hashlib,http.client,importlib.util,json,os,pathlib,shutil,socket,subprocess,tempfile,time,uuid
R=pathlib.Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('h',R/'tests/phase3af-gateway.py');h=importlib.util.module_from_spec(s);s.loader.exec_module(h)
def main():
 out={'status':'FAIL','head':h.git('rev-parse','HEAD'),'tree':h.git('rev-parse','HEAD^{tree}'),'working_diff':h.git('diff','HEAD'),'cases':[],'routing':'gateway forwards upload paths to actual PHP-FPM; nginx NOT RUN'}
 target=pathlib.Path(os.environ.get('PHASE3AF_ATTACHMENT_EVIDENCE',R/'docs/450-phase3af-correction/raw/attachment-http.json'));target.parent.mkdir(parents=True,exist_ok=True)
 with tempfile.TemporaryDirectory(prefix='phase3af-owned-')as tmp:
  nginx=None;nginxport=None;owned_files=[];o=h.Owned(pathlib.Path(tmp),'UTC','+00:00');o.env.update(CHAT_ATTACHMENT_STORAGE=tmp+'/attachments',CHAT_TUS_STORAGE=tmp+'/parts',CHAT_RESUMABLE_UPLOADS='true')
  def check(label,ok):out['cases'].append({'name':label,'status':'PASS'if ok else'FAIL'});h.check(ok,label)
  def php(script,data):
   r=subprocess.run([o.php,str(R/'tests'/script)],input=json.dumps(data),env=o.env,capture_output=True,text=True);h.check(r.returncode==0,o.scrub_text(r.stderr));return json.loads(r.stdout)
  def sql(text):return php('phase3ad-identity-control.php',{'action':'scalar','sql':text})
  def request(c,path,method='GET',data=None,headers=None):
   hd={'Cookie':c.cookie}if c else{};hd.update(headers or{})
   if c and c.token:hd['X-CSRF-Token']=c.token
   port=nginxport if nginxport and path.startswith('/uploads/')else o.port
   conn=http.client.HTTPConnection('127.0.0.1',port,timeout=30);conn.request(method,path,data,hd);r=conn.getresponse();got=(r.status,r.read(),dict(r.getheaders()));conn.close();return got
  def upload(c,route,fields,content=b'Owned attachment sentinel\n',field='file',name='owned.txt'):
   b='af-'+uuid.uuid4().hex;body=b''
   for k,v in fields.items():body+=('--'+b+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+str(v)+'\r\n').encode()
   body+=('--'+b+'\r\nContent-Disposition: form-data; name="'+field+'"; filename="'+name+'"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+content+('\r\n--'+b+'--\r\n').encode()
   st,raw,_=request(c,route,'POST',body,{'Content-Type':'multipart/form-data; boundary='+b});return st,json.loads(raw)
  def send(c,cid,up):return c.req('/API/chat/send-message.php','POST',{'channel_id':cid,'content_type':'file','content':'Owned attachment','attachment_path':up['file_path'],'attachment_name':'forged.txt','attachment_size':999,'attachment_mime':'text/html'})
  try:
   o.start()
   if shutil.which('nginx'):
    sock=socket.socket();sock.bind(('127.0.0.1',0));nginxport=sock.getsockname()[1];sock.close()
    cfg=(R/'deploy/attachments/nginx-attachments.conf.example').read_text().replace('include fastcgi_params;','include /etc/nginx/fastcgi_params;').replace('fastcgi_pass unix:/run/php/php8.3-fpm.sock;','fastcgi_pass 127.0.0.1:'+str(o.fcgiport)+';')
    cfg='daemon off; pid '+tmp+'/nginx.pid; error_log '+tmp+'/nginx.log; events {} http { access_log off; server { listen 127.0.0.1:'+str(nginxport)+'; root '+str(R)+'; '+cfg+' location /uploads/avatars/ { try_files $uri =404; } location / { return 404; } } }'
    conf=pathlib.Path(tmp)/'nginx.conf';conf.write_text(cfg);nginx=subprocess.Popen(['nginx','-c',str(conf),'-p',tmp],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for _ in range(100):
     try:
      with socket.create_connection(('127.0.0.1',nginxport),timeout=.1):break
     except OSError:time.sleep(.05)
    check('actual nginx startup',nginx.poll()is None);out['routing']='actual nginx with tracked upload location and native PHP-FPM';out['nginx_config_sha256']=hashlib.sha256(cfg.encode()).hexdigest()
   elif os.environ.get('PHASE3AF_REQUIRE_NGINX')=='1':raise RuntimeError('Required nginx unavailable')
   actors=[o.registered()for _ in range(4)];a,b,f,x=[v[0]for v in actors];fixture=php('phase3ad-fixture.php',{'users':[v.uid for v in[a,b,f,x]]});cid=fixture['channels']['general'];private=fixture['channels']['private']
   code="  const text = '<img src=x onerror=alert(1)>';\n    console.log(text);\n";r=a.req('/API/chat/send-message.php','POST',{'channel_id':cid,'content_type':'code','content':code});check('code whitespace preserved',r['status']==201 and r['json']['message']['content']==code)
   st,up=upload(a,'/API/chat/upload-file.php',{'target':cid});check('legacy upload endpoint writes private bytes',st==200 and not(R/up['file_path']).exists());path='/'+up['file_path']
   check('orphan denied',request(a,path)[0]==404);check('cross uploader denied',send(b,cid,up)['status']==403);check('wrong destination denied',send(a,private,up)['status']==403)
   sent=send(a,cid,up);check('uploader binds attachment',sent['status']==201);mid=sent['json']['message']['id'];check('member byte equality',request(b,path)[:2]==(200,b'Owned attachment sentinel\n'));check('anonymous denied',request(None,path)[0]in[401,403]);check('outsider denied',request(x,path)[0]==404)
   check('direct API does not bypass authorization',request(x,'/API/attachments/download.php?path='+up['file_path'])[0]==404)
   sql('DELETE FROM server_members WHERE server_id='+str(fixture['server'])+' AND user_id='+str(b.uid));check('revoked membership denied',request(b,path)[0]==404)
   sql('UPDATE messages SET is_deleted=1 WHERE id='+str(mid));check('deleted message denied',request(a,path)[0]==404)
   conv=a.req('/API/dm/open-conversation.php?partner_id='+str(b.uid))['json']['conversation_id'];st,dm=upload(a,'/API/dm/upload-file.php',{'kind':'dm','target':conv});check('DM upload',st==200);d=a.req('/API/dm/send-message.php','POST',{'conversation_id':conv,'body':'Owned DM','attachment_path':dm['file_path']});check('DM bind',d['status']==200);check('DM participant reads',request(b,'/'+dm['file_path'])[0]==200);check('DM outsider denied',request(x,'/'+dm['file_path'])[0]==404)
   check('outsider cannot upload',upload(x,'/API/chat/upload-file.php',{'target':cid})[0]==403)
   payload=b'Owned resumable sentinel\n';meta=','.join(k+' '+base64.b64encode(str(v).encode()).decode()for k,v in{'name':'owned.txt','kind':'channel','target':cid}.items());st,_,hd=request(a,'/API/chat/resumable-upload.php','POST',b'',{'Tus-Resumable':'1.0.0','Upload-Length':str(len(payload)),'Upload-Metadata':meta});check('tus create',st==201);loc=hd['Location'].replace(o.env['APP_URL'],'');st,_,_=request(a,loc,'PATCH',payload,{'Tus-Resumable':'1.0.0','Upload-Offset':'0','Content-Type':'application/offset+octet-stream'});check('tus patch',st==204);st,raw,_=request(a,loc);tus=json.loads(raw);check('tus finalization',st==200 and tus.get('success'));check('tus bytes private',not(R/tus['file_path']).exists());check('tus bind',send(a,cid,tus)['status']==201);check('tus byte equality',request(a,'/'+tus['file_path'])[:2]==(200,payload));check('tus anonymous denied',request(None,'/'+tus['file_path'])[0]in[401,403])
   png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');st,im=upload(a,'/API/threads/upload-image.php',{'scope':'channel','channel_id':private},png,'image','owned.png');check('thread image upload',st==200);t=a.req('/API/threads/index.php','POST',{'action':'create','title':'Owned protected image','body':'Owned','scope':'channel','channel_id':private,'server_id':fixture['server'],'attachments':[im]});check('thread create',t['status']==201);check('thread image bytes',request(a,im['path'])[:2]==(200,png));check('thread outsider denied',request(x,im['path'])[0]==404)
   sql("INSERT INTO server_members(server_id,user_id,server_role) VALUES("+str(fixture['server'])+','+str(b.uid)+",'moderator')");check('server moderator denied private thread image',request(b,im['path'])[0]==404)
   sql('UPDATE threads SET is_deleted=1 WHERE id='+str(t['json']['thread_id']));check('deleted thread image denied',request(a,im['path'])[0]==404)
   legacy='uploads/phase3af_'+uuid.uuid4().hex+'.txt';lf=R/legacy;lf.write_bytes(b'Owned legacy bytes');owned_files.append(lf);mid=a.req('/API/chat/send-message.php','POST',{'channel_id':cid,'content':'Owned legacy reference'})['json']['message']['id'];sql("INSERT INTO message_attachments(message_id,file_name,file_path,file_size,mime_type) VALUES("+str(mid)+",'legacy.txt','"+legacy+"',18,'text/plain')");check('legacy authorized byte equality',request(a,'/'+legacy)[:2]==(200,b'Owned legacy bytes'));check('legacy anonymous denied',request(None,'/'+legacy)[0]in[401,403]);check('legacy outsider denied',request(x,'/'+legacy)[0]==404)
   avatar=R/'uploads/avatars'/('phase3af_'+uuid.uuid4().hex+'.png');avatar.parent.mkdir(exist_ok=True);avatar.write_bytes(png);owned_files.append(avatar);check('public avatar preserved',request(None,'/'+str(avatar.relative_to(R)))[:2]==(200,png));out['status']='PASS'
  except Exception as e:out['error']=o.scrub_text(str(e));raise
  finally:
   log=pathlib.Path(tmp)/'nginx.log'
   if log.exists():out['nginx_log']=o.scrub_text(log.read_text())
   if nginx:nginx.terminate();nginx.wait(timeout=10)
   for f in owned_files:f.unlink(missing_ok=True)
   out['requests']=o.records;out['migration']=getattr(o,'migration',{});out['controls']=o.controls
   out['source_hashes']={str(p.relative_to(R)):hashlib.sha256(p.read_bytes()).hexdigest()for p in list((R/'services').glob('Attachment*.php'))+[R/'API/attachments/download.php',R/'services/MessageService.php']};o.stop();target.write_text(json.dumps(out,indent=2)+'\n')
 print('PASS',len(out['cases']),'native attachment/code checks')
if __name__=='__main__':main()
