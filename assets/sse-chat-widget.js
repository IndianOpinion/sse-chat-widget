/*!
 * sse-chat-widget front end. No framework. Sends only the reader's message,
 * the session token, the conversation id and the page's post id. Model
 * output is rendered as text; it is never assigned to innerHTML.
 */
(function (global, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else if (global && global.document) {
    var start = function () { api.mountAll(global); };
    if (global.document.readyState === 'loading') {
      global.document.addEventListener('DOMContentLoaded', start);
    } else {
      start();
    }
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  var SESSION_KEY = 'sse-chat-session';
  var CONVERSATION_KEY = 'sse-chat-conversation';

  /* Parse complete SSE frames from a text buffer. Returns events and the unparsed rest. */
  function parseFrames(buffer) {
    var events = [];
    var text = buffer.replace(/\r\n/g, '\n');
    var idx;
    while ((idx = text.indexOf('\n\n')) !== -1) {
      var block = text.slice(0, idx);
      text = text.slice(idx + 2);
      var name = 'message';
      var data = [];
      var lines = block.split('\n');
      for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        if (line === '' || line.charAt(0) === ':') { continue; }
        if (line.indexOf('event:') === 0) { name = line.slice(6).trim(); }
        else if (line.indexOf('data:') === 0) { data.push(line.slice(5).replace(/^ /, '')); }
      }
      if (data.length) {
        var parsed = null;
        try { parsed = JSON.parse(data.join('\n')); } catch (e) { parsed = null; }
        if (parsed !== null) { events.push({ event: name, data: parsed }); }
      }
    }
    return { events: events, rest: text };
  }

  /*
   * Render answer text into container as paragraphs. Markers like [2] become
   * links only when "sources" holds that number; any other marker stays text.
   */
  function renderAnswer(doc, container, text, sources) {
    while (container.firstChild) { container.removeChild(container.firstChild); }
    var byNumber = {};
    (sources || []).forEach(function (s) {
      if (s && typeof s.n === 'number' && typeof s.url === 'string' && /^https?:\/\//i.test(s.url)) { byNumber[s.n] = s; }
    });
    var paragraphs = String(text).split(/\n[ \t]*\n+/);
    paragraphs.forEach(function (para) {
      if (para.trim() === '') { return; }
      var p = doc.createElement('p');
      var parts = para.split(/(\[\d{1,3}(?:\s*,\s*\d{1,3})*\])/);
      parts.forEach(function (part) {
        if (part === '') { return; }
        if (/^\[\d{1,3}(?:\s*,\s*\d{1,3})*\]$/.test(part)) {
          var nums = part.slice(1, -1).split(',');
          nums.forEach(function (raw, i) {
            var n = parseInt(raw, 10);
            var src = byNumber[n];
            if (src) {
              var a = doc.createElement('a');
              a.setAttribute('href', src.url);
              a.setAttribute('class', 'sse-chat__cite');
              a.setAttribute('title', String(src.title || ''));
              a.textContent = '[' + n + ']';
              p.appendChild(a);
            } else {
              p.appendChild(doc.createTextNode((i === 0 ? '[' : '') + raw.trim() + (i === nums.length - 1 ? ']' : ',')));
            }
          });
        } else {
          p.appendChild(doc.createTextNode(part));
        }
      });
      container.appendChild(p);
    });
  }

  function readStore(storage, key) {
    try { return storage ? storage.getItem(key) : null; } catch (e) { return null; }
  }
  function writeStore(storage, key, value) {
    try {
      if (!storage) { return; }
      if (value === null) { storage.removeItem(key); } else { storage.setItem(key, value); }
    } catch (e) { /* storage unavailable: memory only */ }
  }

  function el(doc, tag, cls, text) {
    var n = doc.createElement(tag);
    if (cls) { n.setAttribute('class', cls); }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  function createWidget(root, env) {
    var doc = env.document;
    var endpoint = root.getAttribute('data-endpoint') || '';
    var contextAttr = root.getAttribute('data-context') || '';
    var contextPost = /^\d+$/.test(contextAttr) ? parseInt(contextAttr, 10) : null;
    var isLauncher = root.getAttribute('data-launcher') === '1';
    var memory = { session: null, conversation: null };
    var strings = {};
    var limits = { message_chars: 1000 };
    var opened = false;
    var configured = true;
    var busy = false;
    var controller = null;
    var sessionPromise = null;

    function emit(name, detail) {
      var Ctor = env.CustomEvent;
      if (typeof Ctor === 'function') {
        root.dispatchEvent(new Ctor(name, { detail: detail || {}, bubbles: true }));
      }
    }

    // Build the interface.
    var panel = el(doc, 'div', 'sse-chat__panel');
    var title = el(doc, 'h2', 'sse-chat__title', root.getAttribute('data-title') || '');
    var log = el(doc, 'div', 'sse-chat__log');
    log.setAttribute('role', 'log');
    log.setAttribute('aria-live', 'polite');
    log.setAttribute('aria-relevant', 'additions text');
    var greeting = el(doc, 'p', 'sse-chat__greeting', root.getAttribute('data-greeting') || '');
    log.appendChild(greeting);
    var statusLine = el(doc, 'p', 'sse-chat__status', '');
    statusLine.hidden = true;
    var form = el(doc, 'form', 'sse-chat__form');
    var labelId = 'sse-chat-input-' + Math.random().toString(36).slice(2, 9);
    var label = el(doc, 'label', 'sse-chat__label', 'Message');
    label.setAttribute('for', labelId);
    var input = el(doc, 'textarea', 'sse-chat__input');
    input.setAttribute('id', labelId);
    input.setAttribute('rows', '2');
    var send = el(doc, 'button', 'sse-chat__send', 'Send');
    send.setAttribute('type', 'submit');
    var stop = el(doc, 'button', 'sse-chat__stop', 'Stop');
    stop.setAttribute('type', 'button');
    stop.hidden = true;
    var actions = el(doc, 'div', 'sse-chat__actions');
    var restart = el(doc, 'button', 'sse-chat__new', 'Start a new conversation');
    restart.setAttribute('type', 'button');
    var forget = el(doc, 'button', 'sse-chat__forget', 'Forget this conversation');
    forget.setAttribute('type', 'button');
    var disclaimer = el(doc, 'p', 'sse-chat__disclaimer', '');
    form.appendChild(label);
    form.appendChild(input);
    form.appendChild(send);
    form.appendChild(stop);
    actions.appendChild(restart);
    actions.appendChild(forget);
    panel.appendChild(title);
    panel.appendChild(log);
    panel.appendChild(statusLine);
    panel.appendChild(form);
    panel.appendChild(actions);
    panel.appendChild(disclaimer);

    var toggle = null;
    if (isLauncher) {
      toggle = el(doc, 'button', 'sse-chat__launcher', 'Chat');
      toggle.setAttribute('type', 'button');
      toggle.setAttribute('aria-expanded', 'false');
      panel.hidden = true;
      root.appendChild(toggle);
      toggle.addEventListener('click', function () {
        var show = panel.hidden;
        panel.hidden = !show;
        toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (show) { open(); input.focus(); }
      });
    }
    root.appendChild(panel);

    function applyStrings() {
      if (!root.getAttribute('data-title') && strings.title) { title.textContent = strings.title; }
      if (!root.getAttribute('data-greeting') && strings.greeting) { greeting.textContent = strings.greeting; }
      if (strings.placeholder) { input.setAttribute('placeholder', strings.placeholder); label.textContent = strings.placeholder; }
      if (strings.send) { send.textContent = strings.send; }
      if (strings.stop) { stop.textContent = strings.stop; }
      if (strings.new_conversation) { restart.textContent = strings.new_conversation; }
      if (strings.forget) { forget.textContent = strings.forget; }
      if (strings.disclaimer) { disclaimer.textContent = strings.disclaimer; }
      if (limits.message_chars) { input.setAttribute('maxlength', String(limits.message_chars)); }
      if (toggle && strings.title) { toggle.textContent = strings.title; }
    }

    function str(key, fallback) { return strings[key] || fallback; }

    function note(text, cls) {
      var p = el(doc, 'p', 'sse-chat__note' + (cls ? ' ' + cls : ''), text);
      log.appendChild(p);
      return p;
    }

    function getSession() {
      if (memory.session && memory.session.expires * 1000 > Date.now() + 60000) { return memory.session; }
      var raw = readStore(env.storage, SESSION_KEY);
      if (raw) {
        try {
          var s = JSON.parse(raw);
          if (s && typeof s.token === 'string' && s.expires * 1000 > Date.now() + 60000) {
            memory.session = s;
            if (s.strings) { strings = s.strings; }
            if (s.limits) { limits = s.limits; }
            applyStrings();
            return s;
          }
        } catch (e) { /* ignore */ }
      }
      return null;
    }

    function postJson(path, body, accept, signal) {
      return env.fetch(endpoint + path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': accept || 'application/json' },
        body: JSON.stringify(body),
        signal: signal
      });
    }

    function newSession() {
      return postJson('session', {}).then(function (res) {
        return res.json().then(function (data) {
          if (data && data.strings) { strings = data.strings; }
          if (data && data.limits) { limits = data.limits; }
          applyStrings();
          if (!data || data.configured === false) {
            configured = false;
            root.hidden = true;
            return null;
          }
          var s = { token: data.session, expires: data.expires, strings: strings, limits: limits };
          memory.session = s;
          writeStore(env.storage, SESSION_KEY, JSON.stringify(s));
          return s;
        });
      });
    }

    function ensureSession(force) {
      if (!force) {
        var s = getSession();
        if (s) { return Promise.resolve(s); }
      }
      if (!sessionPromise) {
        sessionPromise = newSession().then(function (s) { sessionPromise = null; return s; }, function (e) { sessionPromise = null; throw e; });
      }
      return sessionPromise;
    }

    function open() {
      if (opened) { return sessionPromise || Promise.resolve(getSession()); }
      opened = true;
      emit('ssechat:open');
      memory.conversation = readStore(env.storage, CONVERSATION_KEY);
      // The display strings travel with the stored session, so a reload does
      // not need a new token (which would also reset the per-session limits).
      return ensureSession(false);
    }

    function setConversation(id) {
      memory.conversation = id;
      writeStore(env.storage, CONVERSATION_KEY, id);
    }

    function setBusy(on) {
      busy = on;
      send.disabled = on;
      stop.hidden = !on;
      input.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    function streamingSupported() {
      return env.supportsStreaming !== false && typeof env.TextDecoder === 'function';
    }

    function ask(message, attempt) {
      attempt = attempt || { session: 0, conversation: 0 };
      return ensureSession(false).then(function (session) {
        if (!session) { throw { code: 'not_configured' }; }
        controller = env.AbortController ? new env.AbortController() : null;
        var streaming = streamingSupported();
        var body = { session: session.token, conversation: memory.conversation || '', message: message };
        if (contextPost) { body.context_post = contextPost; }
        return postJson('stream', body, streaming ? 'text/event-stream' : 'application/json', controller ? controller.signal : undefined)
          .then(function (res) {
            if (res.status === 200) { return streaming && res.body && res.body.getReader ? readStream(res) : readJson(res); }
            return res.json().then(function (err) { return err || {}; }, function () { return {}; }).then(function (err) {
              err.status = res.status;
              if (res.status === 401 && err.code === 'session_invalid' && attempt.session === 0) {
                memory.session = null;
                writeStore(env.storage, SESSION_KEY, null);
                return ensureSession(true).then(function () { return ask(message, { session: 1, conversation: attempt.conversation }); });
              }
              if (res.status === 404 && err.code === 'conversation_unknown' && attempt.conversation === 0) {
                setConversation(null);
                note(str('conversation_expired', 'Your earlier conversation has expired, so this starts a new one.'));
                return ask(message, { session: attempt.session, conversation: 1 });
              }
              throw err;
            });
          });
      });
    }

    var answer = null;

    function startAnswer() {
      var wrap = el(doc, 'div', 'sse-chat__answer');
      var body = el(doc, 'div', 'sse-chat__text');
      wrap.appendChild(body);
      log.appendChild(wrap);
      answer = { wrap: wrap, body: body, text: '', sources: [], related: [], finish: null, error: null };
    }

    function handle(evt) {
      var d = evt.data || {};
      switch (evt.event) {
        case 'meta':
          if (typeof d.conversation === 'string') { setConversation(d.conversation); }
          break;
        case 'status':
          statusLine.textContent = String(d.text || '');
          statusLine.hidden = false;
          break;
        case 'delta':
          statusLine.hidden = true;
          answer.text += String(d.text || '');
          renderAnswer(doc, answer.body, answer.text, answer.sources);
          break;
        case 'citations':
          answer.sources = Array.isArray(d.sources) ? d.sources : [];
          answer.related = Array.isArray(d.related) ? d.related : [];
          renderAnswer(doc, answer.body, answer.text, answer.sources);
          renderSources();
          break;
        case 'error':
          answer.error = d;
          note(String(d.message || str('error_generic', 'Something went wrong. Please try again.')), 'sse-chat__note--error');
          emit('ssechat:error', { code: d.code });
          break;
        case 'done':
          answer.finish = d.finish;
          if (d.finish === 'length' && !answer.error) { note(str('cut_short', 'Answer cut short')); }
          break;
      }
    }

    function renderSources() {
      var list = answer.sources.length ? answer.sources : answer.related;
      if (!list.length) { return; }
      var heading = el(doc, 'p', 'sse-chat__sources-label', answer.sources.length ? str('sources_label', 'Sources') : str('related_label', 'Related on this site'));
      var ul = el(doc, 'ul', 'sse-chat__sources');
      list.forEach(function (s) {
        if (!s || typeof s.url !== 'string' || !/^https?:\/\//i.test(s.url)) { return; }
        var li = doc.createElement('li');
        var a = el(doc, 'a', null, (answer.sources.length ? '[' + s.n + '] ' : '') + String(s.title || s.url));
        a.setAttribute('href', s.url);
        li.appendChild(a);
        ul.appendChild(li);
      });
      answer.wrap.appendChild(heading);
      answer.wrap.appendChild(ul);
    }

    function readStream(res) {
      var reader = res.body.getReader();
      var decoder = new env.TextDecoder();
      var buffer = '';
      function pump() {
        return reader.read().then(function (chunk) {
          if (chunk.done) {
            buffer += decoder.decode();
            parseFrames(buffer + '\n\n').events.forEach(handle);
            return;
          }
          buffer += decoder.decode(chunk.value, { stream: true });
          var parsed = parseFrames(buffer);
          buffer = parsed.rest;
          parsed.events.forEach(handle);
          return pump();
        });
      }
      return pump();
    }

    function readJson(res) {
      return res.json().then(function (d) {
        if (d.meta) { handle({ event: 'meta', data: d.meta }); }
        if (d.text) { handle({ event: 'delta', data: { text: d.text } }); }
        if (d.citations) { handle({ event: 'citations', data: d.citations }); }
        if (d.error) { handle({ event: 'error', data: d.error }); }
        if (d.done) { handle({ event: 'done', data: d.done }); }
      });
    }

    function submit(message) {
      message = String(message || '').trim();
      if (!message || busy || !configured) { return Promise.resolve(); }
      var q = el(doc, 'div', 'sse-chat__question', message);
      log.appendChild(q);
      input.value = '';
      setBusy(true);
      emit('ssechat:send');
      startAnswer();
      return ask(message).then(function () {
        emit('ssechat:answer', { finish: answer.finish, cited: answer.sources.length });
      }, function (err) {
        statusLine.hidden = true;
        if (err && err.name === 'AbortError') { return; }
        var code = err && err.code ? err.code : 'error';
        if (code === 'not_configured') { return; }
        var text = err && typeof err.message === 'string' && err.status ? err.message : str('error_generic', 'Something went wrong. Please try again.');
        note(text, 'sse-chat__note--error');
        if (code === 'conversation_full') { restart.focus(); }
        emit('ssechat:error', { code: code });
      }).then(function () {
        setBusy(false);
        controller = null;
        input.focus();
      });
    }

    form.addEventListener('submit', function (e) {
      if (e && e.preventDefault) { e.preventDefault(); }
      submit(input.value);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        if (e.preventDefault) { e.preventDefault(); }
        submit(input.value);
      }
    });
    input.addEventListener('focus', function () { open(); });
    stop.addEventListener('click', function () {
      if (controller) { controller.abort(); }
    });
    restart.addEventListener('click', function () {
      setConversation(null);
      while (log.firstChild) { log.removeChild(log.firstChild); }
      log.appendChild(greeting);
      input.focus();
    });
    forget.addEventListener('click', function () {
      var session = getSession();
      if (!memory.conversation || !session) { return; }
      postJson('forget', { session: session.token, conversation: memory.conversation }).then(function (res) {
        return res.json().then(function (d) { return { status: res.status, data: d || {} }; });
      }).then(function (r) {
        setConversation(null);
        while (log.firstChild) { log.removeChild(log.firstChild); }
        log.appendChild(greeting);
        note(r.status === 200 ? String(r.data.message || str('forgotten', '')) : str('conversation_expired', ''));
      }, function () { note(str('error_generic', 'Something went wrong. Please try again.'), 'sse-chat__note--error'); });
    });

    return { open: open, submit: submit, root: root, input: input, log: log, stop: stop, send: send, panel: panel, toggle: toggle };
  }

  function mountAll(win) {
    var doc = win.document;
    var nodes = doc.querySelectorAll('[data-sse-chat]');
    var storage = null;
    try { storage = win.sessionStorage; storage.getItem('x'); } catch (e) { storage = null; }
    var streaming = typeof win.ReadableStream === 'function' && typeof win.Response === 'function' && 'body' in win.Response.prototype;
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].getAttribute('data-sse-chat-mounted')) { continue; }
      nodes[i].setAttribute('data-sse-chat-mounted', '1');
      createWidget(nodes[i], {
        document: doc,
        fetch: win.fetch.bind(win),
        storage: storage,
        AbortController: win.AbortController,
        TextDecoder: win.TextDecoder,
        CustomEvent: win.CustomEvent,
        supportsStreaming: streaming
      });
    }
  }

  return { parseFrames: parseFrames, renderAnswer: renderAnswer, createWidget: createWidget, mountAll: mountAll };
});
