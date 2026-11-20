/* Account-wide palettes, independent of Dark / Light / System mode. */
(() => {
  'use strict';

  const root = document.documentElement;
  const source = document.getElementById('ecColorPalettes');
  if (!source || window.EcollabColors) return;
  const palettes = JSON.parse(source.textContent);
  const byId = new Map(palettes.map(palette => [palette.id, palette]));
  const roles = ['page', 'surface', 'primary', 'accent', 'text'];
  const fallback = 'ecollab-original';
  const media = window.matchMedia?.('(prefers-color-scheme: light)');
  const editor = document.getElementById('ecCustomPalette');
  const preview = document.getElementById('ecPalettePreview');
  let selected = fallback;
  let savedColors = null;
  let saving = false;

  const parse = value => typeof value === 'string' && /^#[0-9a-f]{6}(,#[0-9a-f]{6}){4}$/i.test(value)
    ? value.toLowerCase().split(',') : null;

  function luminance(hex) {
    const linear = channel => channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4;
    const rgb = [1, 3, 5].map(offset => linear(parseInt(hex.slice(offset, offset + 2), 16) / 255));
    return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
  }
  function buttonText(hex) {
    return luminance(hex) > .179 ? '#000000' : '#ffffff';
  }
  function paintPreview(colors) {
    paint(preview, colors);
    const contrast = (first, second) => {
      const a = luminance(first), b = luminance(second);
      return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    };
    const weak = colors.slice(0, 2).some(background => contrast(background, colors[4]) < 4.5);
    document.getElementById('ecPaletteContrast').hidden = !weak;
  }

  function paint(element, colors) {
    if (!element) return;
    roles.forEach((role, i) => {
      if (colors) element.style.setProperty('--ec-custom-' + role, colors[i]);
      else element.style.removeProperty('--ec-custom-' + role);
    });
    if (colors) element.style.setProperty('--ec-custom-button-text', buttonText(colors[2]));
    else element.style.removeProperty('--ec-custom-button-text');
  }

  function paletteColors() {
    const palette = byId.get(selected) || byId.get(fallback);
    const mode = root.dataset.theme === 'light' ? 'light' : 'dark';
    return roles.map(role => palette[mode][role]);
  }

  function fill(colors) {
    if (!editor) return;
    roles.forEach((role, i) => {
      editor.elements[role].value = colors[i];
      editor.elements[role + 'Hex'].value = colors[i].toUpperCase();
    });
    paintPreview(colors);
  }

  function apply(settings = {}) {
    const colors = parse(settings.custom_palette);
    const id = settings.color_palette;
    const next = id === 'custom' && colors ? 'custom' : byId.has(id) ? id : fallback;
    const changed = next !== selected || colors?.join(',') !== savedColors?.join(',');
    selected = next;
    savedColors = colors;
    root.dataset.colorPalette = selected;
    paint(root, selected === 'custom' ? savedColors : null);
    document.querySelectorAll('[data-color-choice]').forEach(button => {
      const active = button.dataset.colorChoice === selected;
      button.setAttribute('aria-pressed', String(active));
      button.classList.toggle('selected', active);
    });
    if (editor && (changed || !editor.dataset.ready)) {
      fill(selected === 'custom' ? savedColors : paletteColors());
      editor.dataset.ready = 'true';
    }
  }

  function base() {
    return window.ECOLLAB?.baseUrl || window.ECOLLAB_BASE || window.ECOLLAB_CALLS_CONFIG?.baseUrl || window.BASE_URL || '';
  }

  async function choose(id, colors) {
    if (saving || !(byId.has(id) || id === 'custom' && parse(colors))) return;
    const status = document.getElementById('ecPaletteStatus');
    const name = byId.get(id)?.name || 'Custom palette';
    const buttons = [...document.querySelectorAll('[data-color-choice], #ecCustomPalette button')];
    saving = true;
    buttons.forEach(button => { button.disabled = true; });
    if (status) status.textContent = 'Saving ' + name + '…';
    try {
      const token = document.querySelector('meta[name="csrf-token"]')?.content || window.ECOLLAB?.csrfToken || '';
      const update = id === 'custom' ? {color_palette: id, custom_palette: colors} : {color_palette: id};
      const response = await fetch(base() + '/API/profile/settings.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token},
        body: JSON.stringify(update)
      });
      const data = await response.json();
      if (!response.ok || data.success === false || data.settings?.color_palette !== id ||
          (id === 'custom' && data.settings.custom_palette !== colors)) {
        throw new Error(data.error || 'Could not save this palette.');
      }
      window._userSettings = {...window._userSettings, ...data.settings};
      apply(window._userSettings);
      if (id !== 'custom') fill(paletteColors());
      window.dispatchEvent(new CustomEvent('ecollab:settings-applied', {detail: window._userSettings}));
      if (status) status.textContent = name + ' saved to your account.';
    } catch (error) {
      if (status) status.textContent = error.message || 'Could not save this palette. Try again.';
    } finally {
      saving = false;
      buttons.forEach(button => { button.disabled = false; });
    }
  }

  function picker() {
    const host = document.getElementById('ecPaletteGrid');
    if (!host) return;
    for (const palette of palettes) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'ec-palette-choice';
      button.dataset.colorChoice = palette.id;
      button.setAttribute('aria-pressed', 'false');
      const swatches = document.createElement('span');
      swatches.className = 'ec-palette-swatches';
      swatches.setAttribute('aria-hidden', 'true');
      for (const role of roles) {
        const swatch = document.createElement('span');
        swatch.style.backgroundColor = palette.dark[role];
        swatches.append(swatch);
      }
      const name = document.createElement('strong');
      name.textContent = palette.name;
      button.append(swatches, name);
      button.addEventListener('click', () => choose(palette.id));
      host.append(button);
    }
  }

  if (editor) {
    const values = () => roles.map(role => editor.elements[role + 'Hex'].value.trim().toLowerCase()).join(',');
    for (const role of roles) {
      const color = editor.elements[role];
      const hex = editor.elements[role + 'Hex'];
      color.addEventListener('input', () => {
        hex.value = color.value.toUpperCase();
        const colors = parse(values());
        if (colors) paintPreview(colors);
      });
      hex.addEventListener('input', () => {
        if (/^#[0-9a-f]{6}$/i.test(hex.value)) color.value = hex.value;
        const colors = parse(values());
        if (colors) paintPreview(colors);
      });
    }
    editor.addEventListener('submit', event => {
      event.preventDefault();
      const colors = values();
      if (parse(colors)) choose('custom', colors);
    });
    document.getElementById('ecResetColorDraft').addEventListener('click', () => {
      fill(selected === 'custom' ? savedColors : paletteColors());
    });
    document.getElementById('ecRestoreOriginal').addEventListener('click', () => choose(fallback));
    document.getElementById('ecEditSavedPalette').addEventListener('click', () => {
      fill(savedColors || paletteColors());
      editor.elements.page.focus();
    });
  }

  function mode(settings) {
    const theme = settings.theme || 'dark';
    root.dataset.theme = theme === 'light' || theme === 'system' && media?.matches ? 'light' : 'dark';
  }

  window.EcollabColors = {apply, choose, palettes};
  picker();
  apply(window._userSettings);
  window.addEventListener('ecollab:settings-applied', event => apply(event.detail));
  media?.addEventListener?.('change', () => { if (window._userSettings?.theme === 'system') mode(window._userSettings); });
  if (!document.querySelector('script[src*="accessibility-apply.js"]') && document.querySelector('meta[name="csrf-token"]')) {
    fetch(base() + '/API/profile/settings.php', {credentials: 'same-origin'}).then(r => r.ok ? r.json() : null).then(data => {
      if (!data?.settings) return;
      window._userSettings = {...window._userSettings, ...data.settings};
      mode(data.settings);
      apply(window._userSettings);
      window.dispatchEvent(new CustomEvent('ecollab:settings-applied', {detail: window._userSettings}));
    }).catch(() => {});
  }
})();
