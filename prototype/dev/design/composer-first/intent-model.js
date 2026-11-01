(function (root) {
  'use strict';
  const examples = {
    quiet: 'I’d like somewhere quiet to unwind today.',
    football: 'I want to play football today.',
    social: 'I feel like a little company today.',
  };
  function interpret(raw) {
    const text = raw.trim();
    const lower = text.toLowerCase();
    if (!text) return { kind: 'empty', original: text };
    // Prototype scenarios only; deliberately never represented as production AI.
    const base = { original: text };
    if (/registration|anmeldung|paperwork|visa|permit|document|bureaucracy/.test(lower)) return { ...base, kind: 'paperwork' };
    if (/directions|take me to|how (do|can) i get|route to/.test(lower)) return { ...base, kind: 'travel' };
    if (/volleyball/.test(lower)) return { ...base, kind: 'unavailable', reason: 'volleyball' };
    if (/\b\d+\s*(minutes?|mins?|km|kilomet)/.test(lower) || /\b\d{1,2}:\d{2}\b/.test(lower)) return { ...base, kind: 'unavailable', reason: 'constraint' };
    if (/\b(no|without|avoid|not)\b/.test(lower)) return { ...base, kind: 'unavailable', reason: 'exclusion' };
    if (/quiet|calm|unwind|peace|relax/.test(lower)) return { ...base, kind: 'quiet' };
    if (/football|soccer/.test(lower)) return { ...base, kind: 'football' };
    if (/company|hang out|meet|social|people|friends/.test(lower)) return { ...base, kind: 'social' };
    return { ...base, kind: 'clarify' };
  }
  function createState() {
    let request = null;
    let chosen = [];
    let saved = [];
    return {
      submit(text) { request = interpret(text); return request; },
      toggle(id) { chosen = chosen.includes(id) ? chosen.filter((value) => value !== id) : [...chosen, id]; return [...chosen]; },
      save() { saved = [...chosen]; return [...saved]; },
      editSaved() { chosen = [...saved]; return [...chosen]; },
      snapshot() { return { request: request ? { ...request } : null, chosen: [...chosen], saved: [...saved] }; },
    };
  }
  const api = { examples, interpret, createState };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.ExpaduConcept = api;
})(typeof window === 'undefined' ? this : window);
