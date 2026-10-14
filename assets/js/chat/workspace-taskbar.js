'use strict';
(() => {
  const dock = document.getElementById('workspaceSwitcher');
  const list = document.getElementById('workspaceServerList');
  const find = document.getElementById('workspaceFind');
  if (!dock || !list || !find) return;
  const icons = () => [...list.querySelectorAll('.workspace-icon')];
  const desktop = () => matchMedia('(min-width:861px)').matches;
  const reduced = () => matchMedia('(prefers-reduced-motion:reduce)').matches;
  function reveal(icon) {
    if (!desktop()) return;
    const left = icon.offsetLeft - list.offsetLeft;
    if (left < list.scrollLeft) list.scrollLeft = left;
    else if (left + icon.offsetWidth > list.scrollLeft + list.clientWidth) list.scrollLeft = left + icon.offsetWidth - list.clientWidth;
  }
  function overflow() {
    const hasOverflow = list.scrollWidth > list.clientWidth + 2;
    dock.querySelectorAll('[data-scroll]').forEach(button => {
      button.hidden = !desktop() || !hasOverflow;
      button.disabled = button.dataset.scroll === '-1' ? list.scrollLeft <= 1 : list.scrollLeft + list.clientWidth >= list.scrollWidth - 1;
    });
  }
  let active;
  function sync() {
    for (const icon of icons()) icon.setAttribute('aria-current', String(icon.classList.contains('active')));
    const selected = list.querySelector('.workspace-icon.active');
    if (selected !== active) { active = selected; if (active) reveal(active); }
    overflow();
  }
  dock.querySelectorAll('[data-scroll]').forEach(button => button.addEventListener('click', () => {
    list.scrollBy({left:Number(button.dataset.scroll) * Math.max(100, list.clientWidth * .75), behavior:reduced() ? 'auto' : 'smooth'});
  }));
  list.addEventListener('scroll', overflow, {passive:true});
  list.addEventListener('keydown', event => {
    const current = event.target.closest('.workspace-icon'), items = icons();
    if (!current || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
    event.preventDefault();
    const index = items.indexOf(current);
    const target = event.key === 'Home' ? items[0] : event.key === 'End' ? items.at(-1) : items[(index + (event.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length];
    target?.focus({preventScroll:true}); if (target) reveal(target);
  });

  // A body-level tooltip escapes the scrolling dock without covering controls.
  const tip = document.createElement('div'); tip.className = 'workspace-tooltip'; tip.hidden = true; tip.id = 'workspaceTooltip'; tip.setAttribute('role','tooltip'); document.body.appendChild(tip);
  let tipped;
  function hideTip() { tip.hidden = true; tipped?.removeAttribute('aria-describedby'); tipped = null; }
  function showTip(icon) {
    hideTip(); if (!desktop() || !icon) return;
    tipped = icon; const name = icon.dataset.serverName || icon.title || 'Server';
    const visibility = ['private','academic'].includes(icon.dataset.serverType) ? 'Private' : 'Public';
    tip.textContent = name + ' · ' + visibility; tip.hidden = false;
    icon.setAttribute('aria-describedby', tip.id);
    const box = icon.getBoundingClientRect();
    tip.style.left = Math.max(8, Math.min(innerWidth - tip.offsetWidth - 8, box.left + box.width / 2 - tip.offsetWidth / 2)) + 'px';
    tip.style.top = Math.max(8, box.top - tip.offsetHeight - 12) + 'px';
  }
  list.addEventListener('pointerover', e => showTip(e.target.closest('.workspace-icon')));
  list.addEventListener('pointerleave', hideTip);
  list.addEventListener('focusin', e => showTip(e.target.closest('.workspace-icon')));
  list.addEventListener('focusout', hideTip);
  list.addEventListener('click', hideTip);
  list.addEventListener('scroll', hideTip, {passive:true});

  const dialog = document.createElement('dialog'); dialog.className = 'workspace-picker'; dialog.setAttribute('aria-labelledby','workspacePickerTitle');
  dialog.innerHTML = '<header><div><h2 id="workspacePickerTitle">Your servers</h2><p>Switch to a learning space.</p></div><button type="button" aria-label="Close server picker">×</button></header><label for="workspaceQuery">Find a server</label><input id="workspaceQuery" type="search" placeholder="Search by name…" autocomplete="off"><div class="workspace-picker-results"></div><p class="workspace-picker-status" role="status"></p>';
  document.body.appendChild(dialog);
  const input = dialog.querySelector('input'), results = dialog.querySelector('.workspace-picker-results');
  let returnFocus = find;
  function render() {
    results.replaceChildren();
    const query = input.value.trim().toLocaleLowerCase();
    const matches = icons().filter(icon => (icon.dataset.serverName || icon.title || '').toLocaleLowerCase().includes(query));
    for (const icon of matches) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'workspace-picker-row';
      const name = document.createElement('span'); name.textContent = icon.dataset.serverName || icon.title || 'Server';
      const detail = document.createElement('small'); detail.textContent = icon.classList.contains('active') ? 'Current server' : (['private','academic'].includes(icon.dataset.serverType) ? 'Private' : 'Public');
      button.append(name, detail); button.addEventListener('click', () => { returnFocus = icon; dialog.close(); icon.click(); icon.focus({preventScroll:true}); reveal(icon); }); results.appendChild(button);
    }
    dialog.querySelector('.workspace-picker-status').textContent = matches.length ? `${matches.length} server${matches.length === 1 ? '' : 's'}` : 'No servers match your search.';
  }
  find.addEventListener('click', () => { if (dialog.open) return; hideTip(); returnFocus = find; input.value = ''; render(); dialog.showModal(); input.focus(); });
  dialog.querySelector('header button').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => returnFocus.focus({preventScroll:true}));
  dialog.addEventListener('click', e => { if (e.target === dialog) { const box = dialog.getBoundingClientRect(); if (e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom) dialog.close(); } });
  input.addEventListener('input', render);
  input.addEventListener('keydown', e => { if (e.key === 'ArrowDown') { e.preventDefault(); results.querySelector('button')?.focus(); } });
  document.addEventListener('keydown', e => { if (desktop() && e.altKey && !e.ctrlKey && !e.metaKey && e.key.toLowerCase() === 's') { e.preventDefault(); find.click(); } });
  new MutationObserver(sync).observe(list, {subtree:true, childList:true, attributes:true, attributeFilter:['class','title']});
  if (window.ResizeObserver) new ResizeObserver(overflow).observe(list);
  window.addEventListener('resize', () => { hideTip(); overflow(); });
  sync();
})();
