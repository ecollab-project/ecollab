const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const server=fs.readFileSync(path.join(root,'websocket/ChatServer.php'),'utf8');
const field=server.match(/\$wsToken\s*=\s*trim\(\$data\['([^']+)'\]/)?.[1];assert(field,'Production Ratchet authentication field found');
const runner=fs.readFileSync(path.join(root,'tools/load-tests/chat-capacity.js'),'utf8');
const expression=runner.match(/ratchet\.onopen=\(\)=>ratchet\.send\(JSON\.stringify\((\{[^}]+\})\)\)/)?.[1];assert(expression,'Runner authentication payload found');
const payload=vm.runInNewContext('('+expression+')',{wsToken:'synthetic-token'});assert.equal(payload.type,'auth');assert.equal(payload[field],'synthetic-token');assert.equal(Object.keys(payload).length,2);
console.log('Ratchet production contract passed: runner uses the actual server credential field');
