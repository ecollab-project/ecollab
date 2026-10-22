import { Centrifuge } from 'centrifuge';
export function startDelivery(Client = Centrifuge) {
  if (!window.ECOLLAB?.centrifugoEnabled || window.EcollabDelivery) return;
  const inbox = 'inbox:user' + Number(window.ECOLLAB.userId);
  const base = window.ECOLLAB.baseUrl || '';
  const url = new URL(base || window.location.origin, window.location.href);
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
  url.pathname = '/realtime/connection/websocket'; url.search = ''; url.hash = '';
  // Same-origin WebSocket carries the existing HttpOnly session cookie.
  // The broker authenticates it online and assigns this inbox server-side.
  const client = new Client(url.href);
  const sync = detail => window.dispatchEvent(new CustomEvent('ecollab:delivery-sync', {detail}));
  const seen = new Set();
  client.on('publication', ({channel, data}) => {
    if (channel !== inbox) return;
    if (!((data?.type === 'chat_changed' && ['channel','dm','group'].includes(data.kind)) || (data?.type === 'code_lab_changed' && data.kind === 'code_lab'))
      || ![data.target_id, data.message_id, data.event_id].every(n => Number.isSafeInteger(n) && n > 0)) return;
    if (seen.has(data.event_id)) return;
    seen.add(data.event_id); if (seen.size > 512) seen.delete(seen.values().next().value);
    sync(data);
  });
  // No private hint history; always catch up through authorized HTTP reads.
  client.on('subscribed', ({channel}) => {if (channel === inbox) sync({kind:'all'});});
  client.on('error', () => {});
  window.EcollabDelivery = {get state() {return client.state;}, disconnect: () => client.disconnect()};
  document.addEventListener('visibilitychange', () => {if (!document.hidden) sync({kind:'all'});});
  window.addEventListener('online', () => sync({kind:'all'}));
  client.connect();
}
startDelivery();
