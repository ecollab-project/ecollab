const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/chat/chat.js', 'utf8');
const save = source.slice(source.indexOf('function saveChatLocation('), source.indexOf('window.saveChatLocation ='));
const init = source.slice(source.indexOf('  // Deep-link:'), source.indexOf('  // Keyboard shortcut:'));

function page(href, server, ids) {
  const items = ids.map(id => ({dataset: {channelId: String(id), channelName: 'channel-' + id}}));
  let selected = null;
  const location = {href, search: new URL(href).search};
  const context = vm.createContext({
    URL, URLSearchParams, CSS: {escape: String}, currentServerId: server,
    window: {location, history: {state: null, replaceState(_, unused, url) {
      location.href = String(url); location.search = new URL(url).search;
    }}},
    document: {
      querySelector(selector) {
        const match = selector.match(/data-channel-id="([^"]+)"/);
        return match ? items.find(x => x.dataset.channelId === match[1]) : items[0];
      },
      querySelectorAll() { return items; },
    },
    switchChannel(item, id) {
      selected = id;
      vm.runInContext('saveChatLocation({server_id: currentServerId, channel_id: ' + id + '})', context);
    },
  });
  vm.runInContext(save + init, context);
  return {context, location, selected};
}
let first = page('https://ecollab.tech/modules/chat/chat.php?server_id=5&channel_id=20&view=bookmarks', 5, [19,20]);
assert.equal(first.selected,20);
let refreshed = page(first.location.href,5,[19,20]);
assert.equal(refreshed.selected,20);
assert.equal(new URL(refreshed.location.href).searchParams.get('view'),'bookmarks');
let removed = page(first.location.href,5,[19]);
assert.equal(removed.selected,19);
assert.equal(new URL(removed.location.href).searchParams.get('channel_id'),'19');
vm.runInContext('saveChatLocation({server_id:8,channel_id:null})',first.context);
assert.equal(new URL(first.location.href).searchParams.get('server_id'),'8');
assert.equal(new URL(first.location.href).searchParams.has('channel_id'),false);
assert.equal(new URL(first.location.href).searchParams.get('view'),'bookmarks');
console.log('PASS: refresh preserves selected channel and section; missing channel falls back; server switch clears stale channel');
