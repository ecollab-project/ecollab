/* Daily page-category signals only. No text, URLs, search terms or source code. */
(() => {
  'use strict';
  const uid = Number(document.querySelector('meta[name="ecollab-user"]')?.content);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const source = document.currentScript?.src;
  if (!uid || !csrf || !source) return;
  const root = new URL('../../', source);
  const endpoint = new URL('API/profile/learning-activity.php', root);
  function category(path) {
    if (/\/modules\/code-lab\//.test(path)) return 'code_lab';
    if (/\/modules\/(?:library|books)\//.test(path)) return 'library';
    if (/\/modules\/collaboration\//.test(path)) return 'collaboration';
    if (/\/modules\/chat\/chat\.php$/.test(path)) return 'chat';
    if (/\/modules\/(?:student|facilitator|admin)\/dashboard\.php$/.test(path)) return 'dashboard';
    return null;
  }
  const sent = new Set();
  function record(page) {
    if (!page || sent.has(page)) return;
    sent.add(page);
    fetch(endpoint, {method:'POST',credentials:'same-origin',keepalive:true,
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({page})}).catch(() => {});
  }
  record(category(location.pathname));
  document.addEventListener('click', event => {
    const link = event.target.closest?.('a[href]');
    if (!link) return;
    const url = new URL(link.href, location.href);
    if (url.origin === location.origin) record(category(url.pathname));
  });
})();
