// A very small DOM for the widget tests. It throws if anything sets innerHTML.

class Node {
  constructor(doc) { this.ownerDocument = doc; this.childNodes = []; this.parentNode = null; }
  get firstChild() { return this.childNodes[0] || null; }
  appendChild(n) { if (n.parentNode) { n.parentNode.removeChild(n); } n.parentNode = this; this.childNodes.push(n); return n; }
  removeChild(n) { const i = this.childNodes.indexOf(n); if (i >= 0) { this.childNodes.splice(i, 1); n.parentNode = null; } return n; }
  get textContent() { return this.childNodes.map((c) => c.textContent).join(''); }
}

export class Text extends Node {
  constructor(doc, data) { super(doc); this.data = String(data); this.nodeType = 3; }
  get textContent() { return this.data; }
}

export class Element extends Node {
  constructor(doc, tag) {
    super(doc);
    this.tagName = tag.toUpperCase();
    this.nodeType = 1;
    this.attributes = {};
    this.listeners = {};
    this.hidden = false;
    this.disabled = false;
    this.value = '';
  }
  setAttribute(k, v) { this.attributes[k] = String(v); }
  getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null; }
  removeAttribute(k) { delete this.attributes[k]; }
  set textContent(v) { this.childNodes = []; if (v !== '') { this.appendChild(new Text(this.ownerDocument, v)); } }
  get textContent() { return super.textContent; }
  set innerHTML(v) { throw new Error('innerHTML must never be used: ' + v); }
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
  dispatchEvent(evt) {
    evt.target = evt.target || this;
    (this.listeners[evt.type] || []).forEach((fn) => fn(evt));
    if (evt.bubbles && this.parentNode && this.parentNode.dispatchEvent) { this.parentNode.dispatchEvent(evt); }
    return true;
  }
  focus() { this.ownerDocument.activeElement = this; (this.listeners.focus || []).forEach((fn) => fn({ type: 'focus' })); }
  click() { this.dispatchEvent({ type: 'click' }); }
  findAll(pred, out = []) {
    for (const c of this.childNodes) {
      if (c.nodeType === 1) { if (pred(c)) { out.push(c); } c.findAll(pred, out); }
    }
    return out;
  }
  byClass(cls) { return this.findAll((e) => (e.getAttribute('class') || '').split(' ').includes(cls)); }
  byTag(tag) { return this.findAll((e) => e.tagName === tag.toUpperCase()); }
}

export class Document {
  constructor() { this.activeElement = null; }
  createElement(tag) { return new Element(this, tag); }
  createTextNode(t) { return new Text(this, t); }
}

export class CustomEvent {
  constructor(type, init) { this.type = type; this.detail = init && init.detail; this.bubbles = !!(init && init.bubbles); }
}

export function memoryStorage() {
  const m = new Map();
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k), map: m };
}

// A fetch Response whose body streams the given string chunks.
export function streamResponse(chunks, status = 200) {
  const enc = new TextEncoder();
  let i = 0;
  return {
    status,
    body: { getReader: () => ({ read: () => Promise.resolve(i < chunks.length ? { done: false, value: enc.encode(chunks[i++]) } : { done: true }) }) },
    json: () => Promise.reject(new Error('not json'))
  };
}

export function jsonResponse(data, status = 200) {
  return { status, body: null, json: () => Promise.resolve(data) };
}

export function frame(event, data) {
  return 'event: ' + event + '\ndata: ' + JSON.stringify(data) + '\n\n';
}
