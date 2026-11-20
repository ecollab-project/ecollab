/* Keep legacy chat overlays out of keyboard navigation until they are open. */
'use strict';
(() => {
  const states = new WeakMap();
  const stack = [];
  const selector = '.modal-overlay, #peerMatchingModal, #pmCompatModal, #pmSendRequestModal';
  const focusable = 'button,input,select,textarea,a[href],[tabindex]';
  function controls(modal) {
    return [...modal.querySelectorAll(focusable)].filter(el =>
      !el.disabled && el.tabIndex >= 0 && !el.closest('[inert]') &&
      getComputedStyle(el).visibility !== 'hidden' && el.getClientRects().length);
  }
  function sync(modal) {
    const open = (modal.classList.contains('open') || modal.classList.contains('modal-open')) && modal.style.display !== 'none';
    const previous = states.get(modal);
    if (previous?.open === open) return;
    let trigger = previous?.trigger;
    if (open) {
      trigger = document.activeElement;
      modal.inert = false;
      modal.setAttribute('aria-hidden', 'false');
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      if (!modal.hasAttribute('aria-label') && !modal.hasAttribute('aria-labelledby')) {
        const title = modal.querySelector('.modal-title,.modal-header');
        modal.setAttribute('aria-label', title?.textContent.replace(/×/g, '').trim() || 'Dialog');
      }
      modal.tabIndex = -1;
      stack.push(modal);
      if (!modal.contains(document.activeElement)) (controls(modal)[0] || modal).focus();
    } else {
      const index = stack.indexOf(modal);
      if (index >= 0) stack.splice(index, 1);
      // Move focus before hiding its current ancestor from assistive technology.
      if (previous?.open && modal.contains(document.activeElement)) {
        if (trigger?.isConnected && !trigger.closest('[inert]')) trigger.focus();
        else document.activeElement.blur();
      }
      modal.inert = true;
      modal.setAttribute('aria-hidden', 'true');
      modal.removeAttribute('aria-modal');
    }
    states.set(modal, {open, trigger});
  }
  function discover(root) {
    if (root.nodeType !== 1) return;
    if (root.matches(selector)) sync(root);
    root.querySelectorAll(selector).forEach(sync);
  }
  discover(document.body);
  new MutationObserver(records => {
    for (const record of records) {
      if (record.type === 'attributes' && record.target.matches(selector)) sync(record.target);
      for (const node of record.addedNodes) discover(node);
    }
    for (const modal of [...stack]) if (!modal.isConnected) stack.splice(stack.indexOf(modal), 1);
  }).observe(document.body, {subtree:true, childList:true, attributes:true, attributeFilter:['class','style']});
  document.addEventListener('keydown', event => {
    const modal = stack.at(-1);
    if (!modal) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopImmediatePropagation();
      const close = modal.querySelector('.modal-close, .pm-close');
      if (close) close.click();
      else window.closeModal?.(modal.id);
    } else if (event.key === 'Tab') {
      const items = controls(modal), first = items[0], last = items.at(-1);
      if (!first) { event.preventDefault(); modal.focus(); }
      else if (event.shiftKey && (document.activeElement === first || !items.includes(document.activeElement))) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !items.includes(document.activeElement))) {
        event.preventDefault(); first.focus();
      }
    }
  }, true);
})();
