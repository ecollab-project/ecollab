export function number(env,name,fallback,min,max){const raw=env[name]===undefined?fallback:env[name];const value=Number(raw);if(!Number.isInteger(value)||value<min||value>max)throw new Error(`${name} must be an integer from ${min} to ${max}`);return value;}
export function plan(env,accountCount){
  const vus=number(env,'VUS',2,1,1000);if(vus>accountCount)throw new Error('Each virtual user needs a different synthetic account');
  const ramp=number(env,'RAMP_SECONDS',vus<=2?5:60,1,600),hold=number(env,'HOLD_SECONDS',vus<=2?20:120,5,1800);
  const interval=number(env,'INTERVAL_SECONDS',5,1,60),connection=number(env,'CONNECTION_SECONDS',vus<=2?10:30,5,120);
  const writeEvery=number(env,'WRITE_EVERY',0,0,1000);
  return {vus,ramp,hold,interval,connection,writeEvery,stages:[{duration:ramp+'s',target:vus},{duration:hold+'s',target:vus},{duration:'10s',target:0}]};
}
export function validateTarget(target){
  if(!/^k6-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/.test(target.fixture_id||''))throw new Error('Use a generated synthetic test fixture');
  if(!/^https?:\/\/[a-z0-9.\[\]:-]+\/?$/i.test(target.base_url||''))throw new Error('Invalid base URL');
  if(target.base_url.startsWith('http:') && !/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/?$/.test(target.base_url))throw new Error('Use HTTPS outside localhost');
  if(!/^wss?:\/\/[a-z0-9.\[\]:-]+(\/[a-z0-9_./-]*)?$/i.test(target.ws_url||''))throw new Error('Invalid Ratchet WebSocket URL');
  if(![target.server_id,target.channel_id].every(x=>Number.isSafeInteger(x)&&x>0))throw new Error('Invalid fixture server/channel IDs');
}

export function validateAccounts(accounts,target){
  const names=new Set(),ids=new Set(),prefix='k6_'+target.fixture_id.slice(-8)+'_';
  if(!Array.isArray(accounts)||!accounts.length)throw new Error('Missing synthetic accounts');
  for(const account of accounts){if(!Number.isSafeInteger(account.id)||account.id<1||ids.has(account.id)||typeof account.email!=='string'||!account.email.startsWith(prefix)||!account.email.endsWith('@example.invalid')||names.has(account.email)||typeof account.password!=='string'||account.password.length<32)throw new Error('Use distinct generated synthetic accounts');ids.add(account.id);names.add(account.email);}
}
