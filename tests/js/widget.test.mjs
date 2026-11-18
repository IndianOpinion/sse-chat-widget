import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { Document, CustomEvent, memoryStorage, streamResponse, jsonResponse, frame } from './fake-dom.mjs';

const require = createRequire(import.meta.url);
const widget = require('../../assets/sse-chat-widget.js');

const SESSION = { session: 'v1.tok', expires: Math.floor(Date.now() / 1000) + 86400, configured: true, strings: { title: 'Ask', cut_short: 'Answer cut short', error_generic: 'Something went wrong.', conversation_expired: 'Expired.' }, limits: { message_chars: 1000 } };

function setup(handlers, opts = {}) {
  const doc = new Document();
  const root = doc.createElement('div');
  root.setAttribute('data-endpoint', 'https://example.org/wp-json/sse-chat/v1/');
  root.setAttribute('data-context', opts.context || '');
  root.setAttribute('data-launcher', opts.launcher ? '1' : '0');
  const calls = [];
  const events = [];
  ['ssechat:open', 'ssechat:send', 'ssechat:answer', 'ssechat:error'].forEach((t) => root.addEventListener(t, (e) => events.push([t, e.detail])));
  const fetch = (url, init) => {
    const path = url.replace('https://example.org/wp-json/sse-chat/v1/', '');
    const body = JSON.parse(init.body);
    calls.push({ path, body, init });
    const h = handlers[path];
    const next = Array.isArray(h) ? h.shift() : h;
    return Promise.resolve(typeof next === 'function' ? next(body, init) : next);
  };
  const w = widget.createWidget(root, {
    document: doc, fetch, storage: opts.storage === undefined ? memoryStorage() : opts.storage,
    AbortController, TextDecoder, CustomEvent, supportsStreaming: opts.streaming !== false
  });
  return { doc, root, w, calls, events };
}

test('T3: a 401 refreshes the session once and resends once; a second 401 shows the error', async () => {
  const s = setup({
    session: [jsonResponse(SESSION), jsonResponse({ ...SESSION, session: 'v1.new' }), jsonResponse({ ...SESSION, session: 'v1.newer' })],
    stream: [
      jsonResponse({ code: 'session_invalid', message: 'x' }, 401),
      streamResponse([frame('meta', { conversation: 'c'.repeat(32), message: 1 }), frame('delta', { text: 'ok' }), frame('done', { finish: 'stop' })]),
      jsonResponse({ code: 'session_invalid', message: 'x' }, 401),
      jsonResponse({ code: 'session_invalid', message: 'Something went wrong.' }, 401)
    ]
  });
  await s.w.open();
  await s.w.submit('first');
  assert.deepEqual(s.calls.map((c) => c.path), ['session', 'stream', 'session', 'stream']);
  assert.equal(s.calls[3].body.session, 'v1.new');
  assert.equal(s.calls[3].body.message, 'first');
  await s.w.submit('second');
  assert.deepEqual(s.calls.slice(4).map((c) => c.path), ['stream', 'session', 'stream']);
  assert.match(s.w.log.textContent, /Something went wrong\./);
});

test('T5/T6 (widget): sends only session, conversation, message and context post; JSON fallback renders the answer', async () => {
  const s = setup({
    session: jsonResponse(SESSION),
    stream: jsonResponse({ meta: { conversation: 'a'.repeat(32), message: 1 }, text: 'Hello [1]', citations: { sources: [{ n: 1, post: 5, title: 'Post', url: 'https://example.org/p5', date: '' }], related: [] }, done: { finish: 'stop' } })
  }, { streaming: false, context: '42' });
  await s.w.open();
  await s.w.submit('  question?  ');
  const sent = s.calls[1];
  assert.deepEqual(Object.keys(sent.body).sort(), ['context_post', 'conversation', 'message', 'session']);
  assert.equal(sent.body.context_post, 42);
  assert.equal(sent.body.message, 'question?');
  assert.equal(sent.init.headers.Accept, 'application/json');
  assert.equal(s.w.log.byClass('sse-chat__cite')[0].getAttribute('href'), 'https://example.org/p5');
});

test('T7: the stop button aborts the fetch', async () => {
  let signal;
  const s = setup({
    session: jsonResponse(SESSION),
    stream: (body, init) => { signal = init.signal; return new Promise((resolve, reject) => { init.signal.addEventListener('abort', () => { const e = new Error('aborted'); e.name = 'AbortError'; reject(e); }); }); }
  });
  await s.w.open();
  const pending = s.w.submit('long one');
  await new Promise((r) => setTimeout(r, 5));
  assert.equal(s.w.stop.hidden, false);
  s.w.stop.click();
  await pending;
  assert.equal(signal.aborted, true);
  assert.equal(s.w.stop.hidden, true);
  assert.equal(s.w.send.disabled, false);
});

test('T8: model HTML is text; markers link only to URLs from citations; unknown markers stay text', () => {
  const doc = new Document();
  const box = doc.createElement('div');
  const text = 'First <img src=x onerror=alert(1)> para [1] and [7].\n\nSecond para [2, 3].';
  widget.renderAnswer(doc, box, text, [
    { n: 1, url: 'https://example.org/one', title: 'One' },
    { n: 3, url: 'javascript:alert(1)', title: 'Bad' }
  ]);
  const ps = box.byTag('p');
  assert.equal(ps.length, 2);
  assert.equal(box.byTag('img').length, 0);
  assert.match(ps[0].textContent, /<img src=x onerror=alert\(1\)>/);
  const links = box.byTag('a');
  assert.equal(links.length, 1);
  assert.equal(links[0].getAttribute('href'), 'https://example.org/one');
  assert.equal(ps[0].textContent, 'First <img src=x onerror=alert(1)> para [1] and [7].');
  assert.equal(ps[1].textContent, 'Second para [2,3].');
});

test('T9: live region, keyboard send, focus returns to the input after the answer', async () => {
  const s = setup({
    session: jsonResponse(SESSION),
    stream: streamResponse(['event: meta\ndata: {"conversation":"' + 'b'.repeat(32) + '","message":1}\n\nevent: del', 'ta\ndata: {"text":"Hi"}\n\n', frame('done', { finish: 'stop' })])
  });
  assert.equal(s.w.log.getAttribute('role'), 'log');
  assert.equal(s.w.log.getAttribute('aria-live'), 'polite');
  const label = s.root.byTag('label')[0];
  assert.equal(label.getAttribute('for'), s.w.input.getAttribute('id'));
  s.w.input.focus();
  await s.w.open();
  s.w.input.value = 'by keyboard';
  let prevented = false;
  s.w.input.listeners.keydown[0]({ key: 'Enter', shiftKey: false, preventDefault: () => { prevented = true; } });
  await new Promise((r) => setTimeout(r, 20));
  assert.equal(prevented, true);
  assert.equal(s.calls[1].body.message, 'by keyboard');
  assert.match(s.w.log.textContent, /Hi/);
  assert.equal(s.doc.activeElement, s.w.input);
  assert.deepEqual(s.events.map((e) => e[0]), ['ssechat:open', 'ssechat:send', 'ssechat:answer']);
  s.w.input.listeners.keydown[0]({ key: 'Enter', shiftKey: true, preventDefault: () => { throw new Error('shift+enter is a newline'); } });
});

test('T47: a length finish shows "Answer cut short"', async () => {
  const s = setup({ session: jsonResponse(SESSION), stream: streamResponse([frame('delta', { text: 'Partial' }), frame('done', { finish: 'length' })]) });
  await s.w.open();
  await s.w.submit('q');
  assert.match(s.w.log.textContent, /Answer cut short/);
});

test('T68 (widget): configured false hides the widget', async () => {
  const s = setup({ session: jsonResponse({ configured: false, strings: {}, limits: {} }) });
  await s.w.open();
  assert.equal(s.root.hidden, true);
  await s.w.submit('anything');
  assert.equal(s.calls.length, 1, 'nothing is sent');
});

test('expired conversation starts a new one and says so; stored session survives a reload', async () => {
  const storage = memoryStorage();
  const s = setup({
    session: jsonResponse(SESSION),
    stream: [jsonResponse({ code: 'conversation_unknown', message: 'Expired.' }, 404), streamResponse([frame('meta', { conversation: 'd'.repeat(32), message: 1 }), frame('done', { finish: 'stop' })])]
  }, { storage });
  storage.setItem('sse-chat-conversation', 'e'.repeat(32));
  await s.w.open();
  await s.w.submit('q');
  assert.equal(s.calls[1].body.conversation, 'e'.repeat(32));
  assert.equal(s.calls[2].body.conversation, '');
  assert.match(s.w.log.textContent, /Expired\./);
  assert.equal(storage.getItem('sse-chat-conversation'), 'd'.repeat(32));

  const again = setup({ stream: streamResponse([frame('done', { finish: 'stop' })]) }, { storage });
  await again.w.open();
  await again.w.submit('q2');
  assert.deepEqual(again.calls.map((c) => c.path), ['stream'], 'no new session: the per-session limits cannot be reset by reloading');
});

test('works with no storage at all', async () => {
  const s = setup({ session: jsonResponse(SESSION), stream: streamResponse([frame('done', { finish: 'stop' })]) }, { storage: null });
  await s.w.open();
  await s.w.submit('q');
  assert.equal(s.calls[1].body.session, 'v1.tok');
});

test('parseFrames keeps partial frames and skips comments', () => {
  const r = widget.parseFrames(': ping\n\nevent: delta\ndata: {"text":"a"}\n\nevent: del');
  assert.deepEqual(r.events, [{ event: 'delta', data: { text: 'a' } }]);
  assert.equal(r.rest, 'event: del');
});
