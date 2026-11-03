'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('../tools/chat-composer/node_modules/jsdom');
const script = fs.readFileSync('assets/js/ai-markdown.js', 'utf8');
function page() {
  const dom = new JSDOM('<div id="answer"></div>', {url:'https://fixture.invalid/', runScripts:'outside-only'});
  return dom;
}
const attack = '<img src=x onerror="alert(1)"><script>alert(2)</script>';
{
  const dom = page();
  dom.window.marked = {setOptions(){}, parse(){return attack;}};
  dom.window.eval(script);
  dom.window.renderAiMarkdownInto(dom.window.document.getElementById('answer'), attack);
  assert.equal(dom.window.document.querySelector('#answer img, #answer script'), null,
    'Missing sanitizer must render input as text instead of parsing unsafe HTML');
  assert.equal(dom.window.document.getElementById('answer').textContent, attack);
  dom.window.close();
}
{
  const dom = page();
  dom.window.eval(fs.readFileSync('assets/vendor/marked/marked.umd.js','utf8'));
  dom.window.eval(fs.readFileSync('assets/vendor/dompurify/purify.min.js','utf8'));
  dom.window.eval(script);
  dom.window.renderAiMarkdownInto(dom.window.document.getElementById('answer'),
    '**Safe answer**\n\n'+attack+'<a href="javascript:alert(3)">bad</a>');
  const output = dom.window.document.getElementById('answer');
  assert.equal(output.querySelector('strong').textContent,'Safe answer');
  assert.equal(output.querySelector('script,[onerror],[onclick],a[href^="javascript:"]'),null);
  dom.window.close();
}
console.log('PASS: AI Markdown sanitization and missing-sanitizer text fallback');
