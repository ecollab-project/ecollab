'use strict';

const { JSDOM } = require('jsdom');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

function render(page) {
  return execFileSync(process.env.PHP_BIN || 'php', [
    ...JSON.parse(process.env.PHP_FIXTURE_ARGS || '[]'),
    '-d', 'session.save_path=/tmp', 'tests/responsive-render-fixture.php', page
  ], {encoding: 'utf8'});
}

const dom = new JSDOM(render('collabs'), {
  url: 'https://ecollab.test/modules/collaboration/server-coworkspaces.php?server_id=1',
  runScripts: 'outside-only'
});
const {window} = dom;
const {document} = window;
const wait = () => new Promise(resolve => setImmediate(resolve));
const posts = [];
const opened = [];
let resolveCreation;
let fail = false;
window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
window.HTMLDialogElement.prototype.close = function () { this.open = false; };
window.EcollabCallContinuity = {navigate: url => opened.push(url)};

window.fetch = async (url, options) => {
  const route = new URL(url).pathname;
  if (options.method === 'POST') {
    posts.push(JSON.parse(options.body));
    if (fail) return {ok: false, json: async () => ({success: false, error: 'Try a different title.'})};
    return new Promise(resolve => { resolveCreation = result => resolve({ok: true, json: async () => ({success: true, ...result})}); });
  }
  const result = route.endsWith('server-coworkspaces.php') ? {
    server: {name: 'Test server'},
    workspaces: [
      {id: 4, name: 'General', visibility: 'public', channel_id: 11, allow_create_documents: 1, allow_whiteboard: 0},
      {id: 8, name: 'Project', visibility: 'private', channel_id: 12, member_role: 'editor', allow_create_documents: 1, allow_whiteboard: 1},
      {id: 9, name: 'Read only', visibility: 'private', channel_id: 13, member_role: 'viewer', allow_create_documents: 0, allow_whiteboard: 1}
    ]
  } : {documents: [], whiteboards: []};
  return {ok: true, json: async () => ({success: true, ...result})};
};

function submit(form) {
  form.dispatchEvent(new window.Event('submit', {cancelable: true, bubbles: true}));
}

(async () => {
  try {
    assert.match(render('collabs-picker'), /Choose a server/);
    assert.match(render('collabs-picker'), /href="\?server_id=1"/);
    window.eval(readFileSync('assets/js/collaboration/create-resource.js', 'utf8'));
    const app = [...document.scripts].find(script => script.textContent.includes('function creationChoices'));
    window.eval(app.textContent);
    await wait();
    const form = document.getElementById('createResourceForm');
    const dialog = document.getElementById('createResourceDialog');
    window.setType('xlsx');
    window.openCreate('document');
    assert.equal(form.elements.type.value, 'xlsx');
    assert.deepEqual([...form.elements.workspace_id.options].map(option => option.value), ['4', '8']);
    assert.equal(document.activeElement, form.elements.title);
    form.elements.title.value = 'Budget';
    form.elements.workspace_id.value = '8';
    submit(form);
    submit(form);
    await wait();
    assert.equal(posts.length, 1);
    assert.deepEqual([posts[0].type, posts[0].workspace_id], ['xlsx', 8]);
    resolveCreation({document: {open_url: 'https://ecollab.test/modules/collaboration/document.php?id=60'}});
    await wait();
    assert.equal(dialog.open, false);
    assert.match(opened[0], /document\.php/);

    document.getElementById('whiteboardNav').click();
    window.openCreate('whiteboard');
    assert.equal(form.elements.type.disabled, true);
    assert.deepEqual([...form.elements.workspace_id.options].map(option => option.value), ['8']);
    form.elements.title.value = 'Project sketch';
    form.elements.visibility.value = 'private';
    fail = true;
    submit(form);
    await wait();
    assert.equal(dialog.open, true);
    assert.equal(document.getElementById('resourceCreateError').textContent, 'Try a different title.');
    assert.equal(form.elements.title.value, 'Project sketch');
    fail = false;
    submit(form);
    await wait();
    assert.deepEqual([posts.at(-1).workspace_id, posts.at(-1).visibility], [8, 'private']);
    resolveCreation({whiteboard: {id: 42}});
    await wait();
    const board = new URL(opened[1]);
    assert.equal(board.pathname, '/modules/whiteboard/session.php');
    assert.equal(board.searchParams.get('channel_id'), '12');
    assert.equal(board.searchParams.get('whiteboard_id'), '42');
    console.log('PASS: server picker, create and open, file type, eligible destinations, errors and duplicate submissions');
  } finally {
    dom.window.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
