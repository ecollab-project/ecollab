const http = require('node:http');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
  const requests = [];
  const server = http.createServer((req, res) => {
    let raw = '';
    req.on('data', chunk => raw += chunk);
    req.on('end', () => {
      const body = JSON.parse(raw);
      requests.push({body, port: req.socket.remotePort});
      assert.equal(req.headers['x-api-key'], 'b'.repeat(64));
      res.setHeader('Content-Type', 'application/json');
      if (body.data?.type === 'http_error') { res.writeHead(503); res.end('{}'); return; }
      if (body.data?.type === 'incomplete') { res.end(JSON.stringify({result:{responses:[]}})); return; }
      const responses = (body.channels || []).map(() => body.data?.type === 'rejected' ? {error:{code:100}} : {});
      res.end(JSON.stringify({result:{responses}}));
    });
  });
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  const api = `http://127.0.0.1:${server.address().port}/api`;
  const source = path.resolve(__dirname,'../services/CentrifugoService.php');
  const code = `require ${JSON.stringify(source)};
    $broker=new CentrifugoService(str_repeat('a',64),str_repeat('b',64),${JSON.stringify(api)});
    $broker->broadcast(array_merge(range(1,1001),[1,2]),['type'=>'test','event_id'=>1]);
    $broker->broadcast([1],['type'=>'test','event_id'=>2]);
    foreach(['incomplete','rejected','http_error'] as $type){
      try{$broker->broadcast([1],['type'=>$type]);exit(2);}catch(RuntimeException $e){}
    }
    echo function_exists('curl_init')?'curl':'stream';`;
  try {
    const result = await new Promise((resolve,reject) => {
      const proc=spawn('php',['-r',code]);let out='',err='';
      proc.stdout.on('data',s=>out+=s);proc.stderr.on('data',s=>err+=s);
      proc.on('error',reject);proc.on('close',status=>resolve({status,out,err}));
    });
    assert.equal(result.status,0,result.err);
    assert.deepEqual(requests.slice(0,4).map(r=>r.body.channels.length),[500,500,1,1]);
    assert.equal(new Set(requests.slice(0,3).flatMap(r=>r.body.channels)).size,1001);
    assert.equal(requests[2].body.channels[0],'inbox:user1001');
    if(result.out==='curl')assert.equal(new Set(requests.map(r=>r.port)).size,1,'HTTP connection must be reused');
    assert.equal(requests.length,7,'Rejected responses must be tested');
    console.log('Bounded fanout, deduplication, HTTP reuse and rejection checks passed');
  } finally { server.closeAllConnections();await new Promise(resolve=>server.close(resolve)); }
})().catch(e=>{console.error(e);process.exitCode=1;});
