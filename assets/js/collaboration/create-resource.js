'use strict';

window.EcollabCreateResource = function setupResourceCreation(create) {
  const dialog = document.getElementById('createResourceDialog');
  const form = document.getElementById('createResourceForm');
  const title = document.getElementById('createResourceTitle');
  const format = document.getElementById('resourceFormat');
  const details = document.getElementById('resourceDescription');
  const error = document.getElementById('resourceCreateError');
  const cancel = document.getElementById('resourceCreateCancel');
  const submit = document.getElementById('resourceCreateSubmit');
  let kind;
  let workspaces = [];
  let pending = false;

  cancel.addEventListener('click', () => dialog.close());
  dialog.addEventListener('cancel', event => {
    if (pending) event.preventDefault();
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (pending) return;
    const fields = Object.fromEntries(new FormData(form));
    const workspace = workspaces.find(item => String(item.id) === fields.workspace_id);
    const name = fields.title.trim();
    if (!name || !workspace) {
      error.textContent = !name ? 'Enter a title.' : 'Choose a coworkspace.';
      error.hidden = false;
      form.elements[!name ? 'title' : 'workspace_id'].focus();
      return;
    }

    error.hidden = true;
    pending = true;
    submit.disabled = cancel.disabled = true;
    submit.textContent = 'Creating…';
    try {
      await create(kind, workspace, { ...fields, title: name, description: fields.description?.trim() || '' });
      dialog.close();
    } catch (failure) {
      error.textContent = failure.message || 'Creation failed. Try again.';
      error.hidden = false;
    } finally {
      pending = false;
      submit.disabled = cancel.disabled = false;
      submit.textContent = 'Create and open';
    }
  });

  return function open(nextKind, choices, { type = 'docx', workspaceId } = {}) {
    if (dialog.open || pending || choices.length === 0) return;
    kind = nextKind;
    workspaces = choices;
    const isDocument = kind === 'document';
    form.reset();
    title.textContent = isDocument ? 'New document' : 'New whiteboard';
    format.hidden = form.elements.type.disabled = !isDocument;
    details.hidden = form.elements.description.disabled = isDocument;
    details.open = false;
    form.elements.type.value = type;
    form.elements.workspace_id.replaceChildren(...choices.map(item =>
      new Option(item.name, String(item.id))
    ));
    if (choices.some(item => String(item.id) === String(workspaceId))) {
      form.elements.workspace_id.value = String(workspaceId);
    }
    error.hidden = true;
    dialog.showModal();
    form.elements.title.focus();
  };
};
