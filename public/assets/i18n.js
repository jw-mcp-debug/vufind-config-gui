'use strict';
/* Translations: the page embeds lang/<code>.json as a JSON data block
   (no inline script, so the Content-Security-Policy can forbid them). */

const I18N = JSON.parse(document.getElementById('i18n-data').textContent);

/** Translate a key; {name} placeholders are filled from params. */
function t(key, params = {}) {
  const text = I18N.strings[key] ?? key;
  return text.replace(/\{(\w+)\}/g, (m, name) => (name in params ? String(params[name]) : m));
}

const fmtNumber = (n) => Number(n).toLocaleString(I18N.lang);
