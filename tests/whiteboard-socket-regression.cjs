const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const code = fs.readFileSync('assets/js/chat/socket-core.js', 'utf8');
for (const standalone of [true, false]) {
  const sent = [];
  let rejoined = false;
  const context = vm.createContext({
    window: { ECOLLAB: { currentChannelId: 20, whiteboardStandalone: standalone }, wbRejoinRoom() { rejoined = true; } },
    document: { readyState: 'loading', addEventListener() {} },
    console, setInterval() {}, setTimeout(fn) { fn(); }, clearInterval() {},
  });
  vm.runInContext(code, context);
  context.capture = payload => sent.push(JSON.parse(payload));
  vm.runInContext('chatSocket = { send: capture }; handleSocketMessage({type:"auth_ok",user_id:18});', context);
  assert.equal(sent.some(x => x.type === 'join_channel'), !standalone);
  assert.equal(rejoined, true);
}
console.log('PASS: standalone whiteboards rejoin their room without joining chat; chat pages retain channel joins');
