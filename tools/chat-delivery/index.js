import { Centrifuge } from 'centrifuge';
export function startDelivery(Client = Centrifuge) {
  if (!window.ECOLLAB?.centrifugoEnabled || window.EcollabDelivery) return;
  const inbox = 'inbox:user' + Number(window.ECOLLAB.userId);
  const base = window.ECOLLAB.baseUrl || '';
  const getToken = async (mode, channel) => {
    const result = await window.apiFetch(base + '/API/auth/realtime-token.php', {
      method: 'POST', body: JSON.stringify({mode, channel})
    });
    if (result.inbox !== inbox || !result.token) throw new Error('Realtime token unavailable');
    return result.token;
  };
  const url = new URL(base || window.location.origin, window.location.href);
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
  url.pathname = '/realtime/connection/websocket'; url.search = ''; url.hash = '';
  const client = new Client(url.href, {getToken: () => getToken('connection')});
  const subscription = client.newSubscription(inbox, {getToken: () => getToken('subscription', inbox)});
  const sync = detail => window.dispatchEvent(new CustomEvent('ecollab:delivery-sync', {detail}));
  const seen = new Set();
  subscription.on('publication', ({data}) => {
    if (data?.type !== 'chat_changed' || !['channel','dm','group'].includes(data.kind)
      || ![data.target_id, data.message_id, data.event_id].every(n => Number.isSafeInteger(n) && n > 0)) return;
    if (seen.has(data.event_id)) return;
    seen.add(data.event_id); if (seen.size > 512) seen.delete(seen.values().next().value);
    sync(data);
  });
  // Even successful history recovery may be incomplete after a broker restart.
  subscription.on('subscribed', () => sync({kind:'all'}));
  subscription.on('error', () => console.warn('[Delivery] Reconnecting; existing chat transport remains available'));
  client.on('error', () => {});
  window.EcollabDelivery = {get state() {return client.state;}, disconnect: () => client.disconnect()};
  document.addEventListener('visibilitychange', () => {if (!document.hidden) sync({kind:'all'});});
  window.addEventListener('online', () => sync({kind:'all'}));
  subscription.subscribe(); client.connect();
}
startDelivery();
