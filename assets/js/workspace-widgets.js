(() => {
  'use strict';
  const catalogElement = document.getElementById('ecWorkspaceWidgetCatalog');
  if (!catalogElement) return;
  const catalog = JSON.parse(catalogElement.textContent);
  const defaults = () => Object.entries(catalog).map(([id, item]) => [id, item.side, true]);
  const zones = {
    left: document.getElementById('ecWorkspaceLeft'),
    right: document.getElementById('sidebarRight')
  };
  const widgets = new Map([...document.querySelectorAll('[data-workspace-widget]')].map(node => [node.dataset.workspaceWidget, node]));
  const toggles = [...document.querySelectorAll('[data-context-toggle]')];
  const editor = document.getElementById('ecWidgetForm');
  const liveStatus = document.getElementById('ecWidgetStatus');
  const samples = new Map([...(editor?.querySelectorAll('[data-widget-sample]') ?? [])]
    .map(template => [template.dataset.widgetSample, template]));
  let previous = null;
  let saved = '';
  let draft = defaults();
  let saving = false;
  let origin;
  let dragged = null;
  let marked = null;

  function decode(raw) {
    if (!raw) return defaults();
    try {
      const rows = JSON.parse(raw);
      const seen = new Set();
      if (!Array.isArray(rows) || rows.length !== Object.keys(catalog).length) return defaults();
      for (const row of rows) {
        if (!Array.isArray(row) || row.length !== 3) return defaults();
        const [id, side, visible] = row;
        if (!Object.hasOwn(catalog, id) || seen.has(id) || !Object.hasOwn(zones, side) || typeof visible !== 'boolean') return defaults();
        seen.add(id);
      }
      return rows;
    } catch {
      return defaults();
    }
  }

  function drawer(open, trigger) {
    if (!zones.right) return;
    if (open) {
      origin = trigger;
      window.closeSidebar?.();
    }
    zones.right.classList.toggle('ec-context-open', open);
    toggles.forEach(button => button.setAttribute('aria-expanded', String(open)));
    const backdrop = document.getElementById('ecContextBackdrop');
    if (backdrop) backdrop.hidden = !open;
    if (open) zones.right.focus();
    else if (origin) {
      if (!origin.hidden) origin.focus();
      origin = null;
    }
  }

  toggles.forEach(button => button.addEventListener('click', () => drawer(!zones.right.classList.contains('ec-context-open'), button)));
  document.getElementById('ecContextBackdrop')?.addEventListener('click', () => drawer(false));
  document.querySelector('.mob-hamburger')?.addEventListener('click', () => drawer(false));
  const collapse = window.matchMedia?.('(max-width: 1100px)');
  collapse?.addEventListener('change', () => drawer(false));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && zones.right?.classList.contains('ec-context-open')) {
      event.preventDefault();
      drawer(false);
    }
  });

  function apply(raw) {
    if (!zones.left || !zones.right || raw === previous) return;
    previous = raw;
    const rows = decode(raw);
    for (const [id, side, visible] of rows) {
      const node = widgets.get(id);
      if (!node) continue;
      node.hidden = !visible;
      zones[side].appendChild(node);
    }
    zones.right.hidden = !rows.some(([id, side, visible]) => widgets.has(id) && side === 'right' && visible);
    toggles.forEach(button => { button.hidden = zones.right.hidden; });
    if (zones.right.hidden) drawer(false);
  }

  function place(row, side, target, after = false) {
    if (row === target) return;
    row[1] = side;
    row[2] = true;
    draft.splice(draft.indexOf(row), 1);
    draft.splice(target ? draft.indexOf(target) + Number(after) : draft.length, 0, row);
  }

  function render(focus) {
    if (!editor) return;
    for (const side of ['left', 'right', 'hidden']) {
      const host = document.getElementById('ecWidgetList-' + side);
      const rows = draft.filter(([, position, visible]) => side === 'hidden' ? !visible : visible && position === side);
      host.replaceChildren();
      for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const [id, , visible] = row;
        const name = catalog[id].label;
        const item = document.createElement('li');
        item.dataset.editorWidget = id;
        const grip = document.createElement('span');
        grip.className = 'ec-widget-grip';
        grip.draggable = true;
        grip.textContent = '⠿';
        grip.title = 'Drag ' + name;
        grip.setAttribute('aria-hidden', 'true');
        const label = document.createElement('label');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = visible;
        checkbox.dataset.widgetControl = 'visibility';
        checkbox.addEventListener('change', () => {
          row[2] = checkbox.checked;
          render([id, 'visibility']);
        });
        label.append(checkbox, name);
        item.append(grip, label);
        if (!visible) {
          host.append(item);
          continue;
        }
        const actions = document.createElement('div');
        actions.className = 'ec-widget-actions';
        for (const [direction, offset] of [['up', -1], ['down', 1]]) {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'btn secondary';
          button.dataset.widgetControl = direction;
          button.textContent = direction === 'up' ? '↑' : '↓';
          button.title = 'Move ' + name + ' ' + direction;
          button.setAttribute('aria-label', button.title);
          button.disabled = i + offset < 0 || i + offset >= rows.length;
          button.addEventListener('click', () => {
            place(row, side, rows[i + offset], offset > 0);
            render([id, direction]);
          });
          actions.append(button);
        }
        const move = document.createElement('button');
        move.type = 'button';
        move.className = 'btn secondary';
        move.dataset.widgetControl = 'move';
        const other = side === 'left' ? 'right' : 'left';
        move.textContent = side === 'left' ? '→' : '←';
        move.title = 'Move ' + other + ' for ' + name;
        move.setAttribute('aria-label', move.title);
        move.addEventListener('click', () => {
          place(row, other);
          render([id, 'move']);
        });
        actions.append(move);
        item.append(actions);
        const sample = samples.get(id);
        if (sample) item.append(sample.content.cloneNode(true));
        host.append(item);
      }
      if (!rows.length) {
        const empty = document.createElement('li');
        empty.className = 'ec-widget-empty';
        empty.textContent = side === 'hidden' ? 'Drop widgets here to hide them' : 'Drop a widget here';
        host.append(empty);
      }
    }
    if (focus) {
      const [id, control] = focus;
      const item = editor.querySelector('[data-editor-widget="' + id + '"]');
      const requested = item?.querySelector('[data-widget-control="' + control + '"]:not(:disabled)');
      (requested || item?.querySelector('input'))?.focus();
    }
  }

  function clearDrop() {
    if (marked) delete marked.dataset.dropEdge;
    marked = null;
  }

  function locateDrop(event) {
    const zone = event.target.closest?.('[data-widget-drop]');
    if (!zone || !editor.contains(zone)) return null;
    const item = event.target.closest('[data-editor-widget]');
    const rect = item?.getBoundingClientRect();
    return {zone, item, after: !!rect && event.clientY > rect.top + rect.height / 2};
  }

  function load(settings = {}) {
    const raw = typeof settings.workspace_layout === 'string' ? settings.workspace_layout : '';
    apply(raw);
    if (!editor || (editor.dataset.ready && saved === raw)) return;
    saved = raw;
    dragged = null;
    clearDrop();
    draft = decode(raw);
    editor.dataset.ready = 'true';
    render();
  }

  async function save(raw) {
    if (saving) return;
    const fieldset = editor.querySelector('fieldset');
    saving = true;
    fieldset.disabled = true;
    liveStatus.textContent = 'Saving layout…';
    try {
      const base = window.ECOLLAB?.baseUrl || window.ECOLLAB_BASE || window.ECOLLAB_CALLS_CONFIG?.baseUrl || '';
      const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const response = await fetch(base + '/API/profile/settings.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token},
        body: JSON.stringify({workspace_layout: raw})
      });
      const payload = await response.json();
      if (!response.ok || payload.settings?.workspace_layout !== raw) throw new Error(payload.error || 'Layout could not be saved.');
      window._userSettings = {...window._userSettings, ...payload.settings};
      load(window._userSettings);
      window.dispatchEvent(new CustomEvent('ecollab:settings-applied', {detail: window._userSettings}));
      liveStatus.textContent = raw ? 'Workspace layout saved.' : 'Default workspace layout restored.';
    } catch (error) {
      liveStatus.textContent = error.message || 'Layout could not be saved. Try again.';
    } finally {
      saving = false;
      fieldset.disabled = false;
    }
  }

  if (editor) {
    editor.addEventListener('dragstart', event => {
      const grip = event.target.closest?.('.ec-widget-grip');
      if (!grip || saving || !event.dataTransfer) return;
      const item = grip.closest('[data-editor-widget]');
      dragged = draft.find(row => row[0] === item.dataset.editorWidget);
      if (!dragged) return;
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', catalog[dragged[0]].label);
      event.dataTransfer.setDragImage(item, 20, 20);
    });
    editor.addEventListener('dragover', event => {
      if (!dragged || saving) return;
      clearDrop();
      const drop = locateDrop(event);
      if (!drop || drop.item?.dataset.editorWidget === dragged[0]) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      marked = drop.item || drop.zone;
      marked.dataset.dropEdge = drop.item ? (drop.after ? 'after' : 'before') : 'zone';
    });
    editor.addEventListener('dragleave', event => {
      if (marked && !marked.contains(event.relatedTarget)) clearDrop();
    });
    editor.addEventListener('dragend', () => { dragged = null; clearDrop(); });
    editor.addEventListener('drop', event => {
      if (!dragged || saving) return;
      const drop = locateDrop(event);
      if (!drop) return;
      event.preventDefault();
      const row = dragged;
      dragged = null;
      clearDrop();
      const side = drop.zone.dataset.widgetDrop;
      if (side === 'hidden') row[2] = false;
      else place(row, side, draft.find(candidate => candidate[0] === drop.item?.dataset.editorWidget), drop.after);
      render([row[0], 'visibility']);
    });
    document.getElementById('ecWidgetUndo').addEventListener('click', () => {
      draft = decode(saved);
      render();
      liveStatus.textContent = 'Unsaved changes discarded.';
    });
    editor.addEventListener('submit', event => {
      event.preventDefault();
      save(JSON.stringify(draft));
    });
    document.getElementById('ecWidgetReset').addEventListener('click', () => {
      draft = defaults();
      render();
      save('');
    });
  }
  load(window._userSettings);
  window.addEventListener('ecollab:settings-applied', event => load(event.detail));
})();
