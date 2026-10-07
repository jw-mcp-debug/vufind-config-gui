'use strict';
/* Core of the GUI: file list, structured ini/properties editor, raw text,
   diff and global settings search. Uses t() from i18n.js. */

const $ = (sel, el = document) => el.querySelector(sel);
const h = (tag, attrs = {}, ...children) => {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'style') el.style.cssText = v; // CSSOM, allowed by the CSP (style attributes are not)
    else if (v === true) el.setAttribute(k, '');
    else el.setAttribute(k, v);
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
};

const MCP_FILE = 'config/vufind/ModelContextProtocol.yaml';
const SPEC_FILE = 'config/vufind/searchspecs.yaml';
const DEFAULT_FILE = 'config/vufind/searches.ini';

const state = {
  inst: null, instances: [], mcp: null, hasSolr: false, problems: [],
  files: [], others: [], vufindUrl: '',
  view: 'file',       // 'file' or 'mcptest'
  current: null,      // answer of ?api=get
  tab: 'settings',
  dirty: new Map(),   // line -> {line, key, value, active}
  mcpDirty: false,    // unsaved changes in the MCP editor
  specDirty: false,   // unsaved changes in the ranking editor
  specType: null, specQ: '', // selected search type and test query in the ranking editor
  filter: '', onlyActive: false, onlyChanged: false,
  focusLine: null,    // line the global search jumps to
};

async function api(action, { query = {}, body } = {}) {
  const qs = new URLSearchParams({ api: action, ...(state.inst ? { inst: state.inst } : {}), ...query });
  // The custom header proves the request comes from this page (CSRF protection, see src/Security.php)
  const headers = { 'X-VuFind-Config-Gui': '1' };
  if (body) headers['Content-Type'] = 'application/json';
  const res = await fetch('?' + qs, body ? { method: 'POST', headers, body: JSON.stringify(body) } : { headers });
  const data = await res.json().catch(() => ({ error: t('error.bad_response') }));
  if (!res.ok || data.error) throw new Error(data.error || res.statusText);
  return data;
}

function toast(msg, kind = 'ok') {
  const el = $('#toast');
  el.textContent = msg;
  el.className = 'toast show ' + kind;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => (el.className = 'toast'), kind === 'err' ? 7000 : 3500);
}

function confirmDiscard() {
  if (state.mcpDirty && !confirm(t('confirm.discard_mcp'))) return false;
  if (state.specDirty && !confirm(t('confirm.discard_ranking'))) return false;
  if (state.dirty.size && !confirm(t('confirm.discard_n', { n: state.dirty.size }))) return false;
  state.mcpDirty = false;
  state.specDirty = false;
  return true;
}

function setHash(target) {
  history.replaceState(null, '', '#' + state.inst + ':' + encodeURIComponent(target));
}

const savedNote = (r) => (r.createdLocal ? t('toast.local_created_suffix') : '') + (r.backup ? t('toast.backup_suffix', { path: r.backup }) : '');

// ------------------------------------------------------------ Instance & file list
async function loadFiles() {
  const data = await api('files');
  Object.assign(state, { inst: data.instance, files: data.files, others: data.others, vufindUrl: data.vufindUrl,
    instances: data.instances, mcp: data.mcp, hasSolr: data.hasSolr, problems: data.problems });
  const sel = $('#instance');
  sel.replaceChildren(...state.instances.map((i) => h('option', { value: i.key, selected: i.key === state.inst }, i.label)));
  sel.hidden = state.instances.length < 2;
  $('#testsearch').hidden = !state.vufindUrl;
  renderFiles();
}

async function switchInstance(key) {
  if (!confirmDiscard()) { $('#instance').value = state.inst; return; }
  state.inst = key;
  state.current = null;
  state.dirty.clear();
  await loadFiles();
  state.mcp ? openMcpTest() : openFile(DEFAULT_FILE);
}

const badge = (f) => h('span', { class: 'badge ' + (f.hasLocal ? 'local' : 'orig') }, f.hasLocal ? t('badge.local') : t('badge.default'));

function renderFiles() {
  const nav = $('#files');
  nav.replaceChildren();
  const groups = {};
  for (const f of state.files) (groups[f.group] ||= []).push(f);
  // The MCP test sits at the top of the "MCP server" group
  const mcpTest = state.mcp ? h('button', { class: 'file' + (state.view === 'mcptest' ? ' active' : ''), onclick: openMcpTest },
    h('span', { class: 'fname' }, t('mcptest.title')), h('span', { class: 'flabel' }, t('mcptest.nav_label')),
    h('span', { class: 'badge local' }, t('badge.live'))) : null;
  if (mcpTest && !groups['group.mcp']) nav.append(h('div', { class: 'group' }, h('h2', {}, t('group.mcp')), mcpTest));
  const item = (f) => h('button', {
    class: 'file' + (state.view === 'file' && state.current?.info.rel === f.rel ? ' active' : ''),
    onclick: () => openFile(f.rel),
    title: f.rel,
  }, h('span', { class: 'fname' }, f.name), f.label && h('span', { class: 'flabel' }, t(f.label)), badge(f));
  for (const [g, list] of Object.entries(groups)) {
    nav.append(h('div', { class: 'group' }, h('h2', {}, t(g)), g === 'group.mcp' ? mcpTest : null, list.map(item)));
  }
  const othersBox = h('div', {}, state.others.map(item));
  const filterInput = h('input', { type: 'search', placeholder: t('nav.filter'), 'aria-label': t('nav.filter_label'), oninput: (e) => {
    const q = e.target.value.toLowerCase();
    for (const b of othersBox.querySelectorAll('.file')) b.hidden = !b.title.toLowerCase().includes(q);
  } });
  nav.append(h('details', { class: 'group others', open: state.others.some((f) => f.hasLocal) || state.current?.info.curated === false },
    h('summary', {}, t('nav.others', { n: state.others.length })), filterInput, othersBox));
}

// ------------------------------------------------------------ Open a file
async function openFile(rel, keepTab = false) {
  if (!confirmDiscard()) return;
  try {
    state.current = await api('get', { query: { file: rel } });
  } catch (e) {
    return toast(e.message, 'err');
  }
  state.dirty.clear();
  state.view = 'file';
  if (!keepTab) state.tab = rel === MCP_FILE && state.mcp ? 'mcp' : rel === SPEC_FILE ? 'ranking' : state.current.info.type === 'yaml' ? 'raw' : 'settings';
  setHash(rel);
  renderFiles();
  renderMain();
}

async function openMcpTest() {
  if (!confirmDiscard()) return;
  state.view = 'mcptest';
  state.current = null;
  setHash('@mcptest');
  renderFiles();
  renderMcpTest($('#main'));
}

function renderMain() {
  const { info } = state.current;
  const main = $('#main');
  main.replaceChildren();

  for (const p of state.problems) main.append(h('p', { class: 'note warn' }, p));

  const actions = [];
  if (!info.hasLocal) {
    actions.push(h('button', { class: 'ghost', onclick: createLocal }, t('file.create_local')));
  } else if (!info.protected) {
    actions.push(h('button', { class: 'ghost danger', onclick: deleteLocal }, t('file.delete_local')));
  }
  main.append(h('div', { class: 'filehead' },
    h('div', {},
      h('h1', {}, info.name),
      h('p', { class: 'meta' },
        h('code', {}, (info.hasLocal ? 'local/' : '') + info.rel),
        h('span', { class: 'badge ' + (info.hasLocal ? 'local' : 'orig') }, info.hasLocal ? t('file.local_active') : t('file.no_local')),
        h('span', { class: 'badge effect' + (info.effect === 'reindex' ? ' warn' : '') }, t('file.effect', { effect: t('effect.' + info.effect) })))),
    h('div', { class: 'actions' }, actions)));

  if (!info.hasLocal) main.append(h('p', { class: 'note' }, t('file.first_save_note')));
  if (info.rel === 'import/marc.properties') main.append(h('p', { class: 'note warn' }, t('file.marc_properties_note')));

  const isMcp = info.rel === MCP_FILE && state.mcp;
  const tabs = [['mcp', t('tab.mcp')], ['ranking', t('tab.ranking')], ['settings', t('tab.settings')], ['raw', t('tab.raw')], ['diff', t('tab.diff')]]
    .filter(([id]) => !(id === 'settings' && info.type === 'yaml') && !(id === 'mcp' && !isMcp) && !(id === 'ranking' && info.rel !== SPEC_FILE));
  main.append(h('div', { class: 'tabs', role: 'tablist' }, tabs.map(([id, label]) =>
    h('button', { role: 'tab', 'aria-selected': state.tab === id ? 'true' : 'false', class: state.tab === id ? 'on' : '', onclick: () => {
      if (id !== state.tab && !confirmDiscard()) return;
      if (id !== 'settings') state.dirty.clear();
      state.tab = id; renderMain();
    } }, label))));

  const panel = h('div', { class: 'panel' });
  main.append(panel);
  if (state.tab === 'mcp') renderMcpEditor(panel);
  if (state.tab === 'ranking') renderRankingEditor(panel);
  if (state.tab === 'settings') renderSettings(panel);
  if (state.tab === 'raw') renderRaw(panel);
  if (state.tab === 'diff') renderDiff(panel);
}

// ------------------------------------------------------------ Structured view
function isChanged(e) {
  if (!e.orig) return e.active; // not in the original
  return e.orig.active !== e.active || (e.active && e.orig.value !== e.value);
}

function matches(e, sectionName) {
  if (state.onlyActive && !e.active) return false;
  if (state.onlyChanged && !isChanged(e)) return false;
  const q = state.filter.toLowerCase();
  return !q || e.key.toLowerCase().includes(q) || String(e.value).toLowerCase().includes(q)
    || e.help.toLowerCase().includes(q) || sectionName.toLowerCase().includes(q);
}

function renderSettings(panel) {
  const { sections } = state.current;
  const count = h('span', { class: 'count' });
  const list = h('div', { class: 'sections' });
  const rerender = () => {
    list.replaceChildren();
    let shown = 0;
    const filtering = state.filter || state.onlyActive || state.onlyChanged;
    for (const s of sections) {
      const entries = s.entries.filter((e) => matches(e, s.name));
      if (!entries.length) continue;
      shown += entries.length;
      const changed = s.entries.filter(isChanged).length;
      const body = h('div', { class: 'entries' });
      const hasFocus = state.focusLine !== null && entries.some((e) => e.line === state.focusLine);
      const det = h('details', { class: 'section', open: hasFocus || (filtering && entries.length < 60) },
        h('summary', {},
          h('span', { class: 'sname' }, s.name ? `[${s.name}]` : t('settings.no_section')),
          h('span', { class: 'scount' }, t('settings.section_count', { active: s.entries.filter((e) => e.active).length, total: s.entries.length })),
          changed ? h('span', { class: 'badge local' }, t('settings.n_changed', { n: changed })) : null),
        s.help ? h('pre', { class: 'shelp' }, s.help) : null,
        body);
      const fill = () => { if (!body.childElementCount) body.append(...entries.map(entryRow)); };
      if (det.open) fill();
      det.addEventListener('toggle', () => det.open && fill());
      list.append(det);
    }
    count.textContent = t('settings.n_options', { n: shown });
    if (!shown) list.append(h('p', { class: 'empty' }, t('settings.no_match')));
  };

  panel.append(h('div', { class: 'toolbar' },
    h('input', { type: 'search', value: state.filter, placeholder: t('settings.filter'), 'aria-label': t('settings.filter_label'),
      oninput: (e) => { state.filter = e.target.value; rerender(); } }),
    h('label', {}, h('input', { type: 'checkbox', checked: state.onlyActive, onchange: (e) => { state.onlyActive = e.target.checked; rerender(); } }), ' ' + t('settings.only_active')),
    h('label', {}, h('input', { type: 'checkbox', checked: state.onlyChanged, onchange: (e) => { state.onlyChanged = e.target.checked; rerender(); } }), ' ' + t('settings.only_changed')),
    count));
  panel.append(list);
  panel.append(saveBar());
  rerender();
  if (state.focusLine !== null) {
    const row = panel.querySelector(`.entry[data-line="${state.focusLine}"]`);
    state.focusLine = null;
    if (row) { row.scrollIntoView({ block: 'center' }); row.classList.add('flash'); }
  }
}

// Allowed values from the help text: pairs like "enabled"/"disabled" must both appear there in quotes
const CHOICE_PAIRS = [['enabled', 'disabled'], ['true', 'false'], ['on', 'off'], ['yes', 'no'], ['1', '0']];
function valueChoices(e) {
  const vals = [e.value, e.orig?.value].filter((v) => v != null).map((v) => v.toLowerCase());
  if (vals.some((v) => /^(true|false)$/.test(v))) return ['true', 'false'];
  const help = (e.help || '').toLowerCase();
  for (const pair of CHOICE_PAIRS) {
    const quoted = pair.every((w) => new RegExp(`["']${w}["']`).test(help));
    if (quoted && vals.some((v) => pair.includes(v))) return pair;
  }
  return null;
}

function entryRow(e) {
  const secret = /pass(word)?|secret|api_?key|token/i.test(e.key);
  const choices = valueChoices(e);
  // The switch only comments the line in or out. That turns an option off only
  // if it is inactive in the original; if it is active there, commenting it out
  // makes VuFind fall back to its built-in default (usually the same value).
  // Then there is no switch, only the value. An already commented-out line keeps
  // the switch so it can be activated again.
  const canSwitch = !e.orig?.active || !e.active;
  const row = h('div', { class: 'entry' + (e.active ? '' : ' inactive'), 'data-line': e.line });

  const update = (patch) => {
    // Choosing a value in a commented-out line means setting it
    if ('value' in patch && !('active' in patch) && !e.active) patch.active = true;
    Object.assign(e, patch);
    if (toggle.type === 'checkbox') toggle.checked = e.active;
    state.dirty.set(e.line, { line: e.line, key: e.key, value: e.value, active: e.active });
    row.classList.toggle('inactive', !e.active);
    row.classList.add('dirty');
    renderDefault();
    updateSaveBar();
  };

  const toggle = canSwitch
    ? h('input', { type: 'checkbox', class: 'switch', checked: e.active, title: e.active ? t('entry.switch_on') : t('entry.switch_off'),
      'aria-label': t('entry.switch_label', { key: e.key }), onchange: (ev) => update({ active: ev.target.checked }) })
    : h('span', { class: 'switch-ph', title: t('entry.no_switch') });

  let input;
  if (choices) {
    const known = choices.some((v) => v.toLowerCase() === e.value.toLowerCase());
    input = h('select', { 'aria-label': e.key, onchange: (ev) => update({ value: ev.target.value }) },
      known ? null : h('option', { value: e.value, selected: true }, e.value),
      choices.map((v) => h('option', { value: v, selected: e.value.toLowerCase() === v.toLowerCase() }, v)));
  } else {
    input = h('input', { type: secret ? 'password' : 'text', value: e.value, 'aria-label': e.key, spellcheck: 'false',
      autocomplete: secret ? 'off' : null, oninput: (ev) => update({ value: ev.target.value }) });
  }

  const def = h('div', { class: 'default' });
  const renderDefault = () => {
    def.replaceChildren();
    if (!e.orig) {
      def.append(h('span', { class: 'badge local' }, t('entry.not_in_original')));
    } else if (!e.active && e.orig.active) {
      def.append(h('span', { class: 'badge warn', title: t('entry.default_applies_title') }, t('entry.default_applies')),
        h('button', { class: 'link', onclick: () => update({ active: true }) }, t('entry.set')));
    } else if (isChanged(e)) {
      const shownOrig = secret ? '••••' : (e.orig.value === '' ? t('entry.empty') : e.orig.value);
      def.append(h('span', {}, t('entry.default') + ' ', h('code', {}, e.orig.active ? shownOrig : t('entry.commented', { value: shownOrig }))),
        h('button', { class: 'link', title: t('entry.reset_title'), onclick: () => {
          update({ value: e.orig.value, active: e.orig.active });
          if (input.tagName === 'SELECT') input.value = e.value.toLowerCase(); else input.value = e.value;
        } }, '↺ ' + t('entry.reset')));
    }
  };
  renderDefault();

  const help = e.help.trim() ? h('pre', { class: 'help', hidden: true }, e.help.trim()) : null;
  const helpBtn = help ? h('button', { class: 'link', 'aria-expanded': 'false', onclick: (ev) => {
    help.hidden = !help.hidden;
    ev.target.setAttribute('aria-expanded', String(!help.hidden));
  } }, t('entry.help')) : null;

  row.append(...[toggle, h('label', { class: 'key' }, e.key), input, h('div', { class: 'side' }, def, helpBtn), help].filter(Boolean));
  return row;
}

function saveBar() {
  return h('div', { id: 'savebar', class: 'savebar', hidden: true },
    h('span', { id: 'dirtycount' }),
    h('button', { class: 'ghost', onclick: () => { state.dirty.clear(); openFile(state.current.info.rel, true); } }, t('action.discard')),
    h('button', { class: 'primary', onclick: saveStructured }, t('action.save')));
}

function updateSaveBar() {
  const bar = $('#savebar');
  if (!bar) return;
  bar.hidden = state.dirty.size === 0;
  $('#dirtycount').textContent = t('savebar.n_unsaved', { n: state.dirty.size });
}

async function saveStructured() {
  const { info, mtime } = state.current;
  try {
    const r = await api('save', { body: { file: info.rel, mtime, changes: [...state.dirty.values()] } });
    state.dirty.clear();
    toast(t('toast.n_saved', { n: r.applied }) + savedNote(r));
    await refreshAfterWrite();
  } catch (e) {
    toast(e.message, 'err');
  }
}

async function refreshAfterWrite() {
  const rel = state.current.info.rel;
  const data = await api('files');
  Object.assign(state, { files: data.files, others: data.others });
  await openFile(rel, true);
}

// ------------------------------------------------------------ Raw text & diff
function renderRaw(panel) {
  const { raw, info } = state.current;
  const ta = h('textarea', { class: 'raw', spellcheck: 'false', 'aria-label': t('tab.raw') });
  ta.value = raw;
  const status = h('span', { class: 'count' });
  ta.addEventListener('input', () => { status.textContent = ta.value !== raw ? t('raw.changed') : ''; });
  panel.append(ta, h('div', { class: 'savebar static' }, status,
    h('button', { class: 'ghost', onclick: () => { ta.value = raw; status.textContent = ''; } }, t('action.discard')),
    h('button', { class: 'primary', onclick: async () => {
      if (ta.value === raw) return toast(t('toast.no_changes'));
      try {
        const r = await api('saveRaw', { body: { file: info.rel, mtime: state.current.mtime, raw: ta.value } });
        toast(t('toast.saved') + savedNote(r));
        await refreshAfterWrite();
      } catch (e) { toast(e.message, 'err'); }
    } }, t('action.save'))));
}

async function renderDiff(panel) {
  if (!state.current.info.hasLocal) {
    panel.append(h('p', { class: 'empty' }, t('diff.no_local')));
    return;
  }
  let diff;
  try {
    ({ diff } = await api('diff', { query: { file: state.current.info.rel } }));
  } catch (e) {
    panel.append(h('p', { class: 'note warn' }, e.message));
    return;
  }
  if (!diff.trim()) {
    panel.append(h('p', { class: 'empty' }, t('diff.identical')));
    return;
  }
  panel.append(h('pre', { class: 'diff' }, diff.split('\n').map((l) => h('span', {
    class: l.startsWith('+++') || l.startsWith('---') ? 'dh' : l.startsWith('@@') ? 'da' : l.startsWith('+') ? 'dp' : l.startsWith('-') ? 'dm' : '',
  }, l + '\n'))));
}

// ------------------------------------------------------------ Actions
async function createLocal() {
  try {
    await api('createLocal', { body: { file: state.current.info.rel } });
    toast(t('toast.local_created'));
    await refreshAfterWrite();
  } catch (e) { toast(e.message, 'err'); }
}

async function deleteLocal() {
  if (!confirm(t('confirm.delete_local'))) return;
  try {
    const r = await api('deleteLocal', { body: { file: state.current.info.rel } });
    toast(t('toast.local_deleted') + (r.backup ? t('toast.backup_suffix', { path: r.backup }) : ''));
    await refreshAfterWrite();
  } catch (e) { toast(e.message, 'err'); }
}

$('#clearcache').addEventListener('click', async () => {
  try {
    const r = await api('clearCache', { body: {} });
    toast(t('toast.cache_cleared', { detail: Object.entries(r.cache).map(([k, v]) => `${k} ${v}`).join(', ') || '–' }));
  } catch (e) { toast(e.message, 'err'); }
});

$('#testsearch').addEventListener('submit', (ev) => {
  ev.preventDefault();
  const q = $('#tq').value.trim();
  const url = q
    ? `${state.vufindUrl}/Search/Results?${new URLSearchParams({ lookfor: q, type: $('#ttype').value })}`
    : `${state.vufindUrl}/`;
  window.open(url, 'vufind');
});

$('#instance').addEventListener('change', (e) => switchInstance(e.target.value));

// The header wraps on narrow screens; the sticky file list must start below it
new ResizeObserver(([entry]) => document.documentElement.style.setProperty('--top-h', entry.target.offsetHeight + 'px'))
  .observe($('header.top'));

$('#lang').addEventListener('change', (e) => {
  if (!confirmDiscard()) { e.target.value = I18N.lang; return; }
  document.cookie = `vcg_lang=${encodeURIComponent(e.target.value)}; path=/; max-age=31536000; SameSite=Strict`;
  location.reload();
});

window.addEventListener('beforeunload', (e) => { if (state.dirty.size || state.mcpDirty || state.specDirty) { e.preventDefault(); e.returnValue = ''; } });

// Start: hash "#<instance>:<file>" or "#<instance>:@mcptest"
(() => {
  const m = location.hash.slice(1).match(/^([a-z0-9_-]+):(.*)$/);
  if (m) state.inst = m[1];
  const target = m ? decodeURIComponent(m[2]) : '';
  loadFiles().then(() => {
    if (target === '@mcptest' && state.mcp) return openMcpTest();
    openFile(target && target !== '@mcptest' ? target : DEFAULT_FILE);
  }).catch((e) => toast(e.message, 'err'));
})();

// ------------------------------------------------------------ Global settings search
const gq = $('#gq');
const gres = $('#gresults');
let gTimer = null, gSeq = 0, gHits = [], gSel = -1;

const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
function mark(text, terms) {
  if (!terms.length) return text;
  const re = new RegExp('(' + terms.map(esc).join('|') + ')', 'gi');
  return text.split(re).map((p, i) => (i % 2 ? h('mark', {}, p) : p));
}

function closeGlobal() { gres.hidden = true; gSel = -1; }

async function gotoHit(hit) {
  closeGlobal();
  const sameFile = state.view === 'file' && state.current?.info.rel === hit.rel;
  if (!sameFile && !confirmDiscard()) return;
  state.filter = ''; state.onlyActive = false; state.onlyChanged = false;
  state.focusLine = hit.kind === 'entry' ? hit.line : null;
  if (sameFile && hit.kind === 'entry' && state.tab === 'settings') {
    // Same file: rebuild the view without silently discarding changes
    renderMain();
    return;
  }
  state.dirty.clear();
  state.current = null;
  await openFile(hit.rel);
}

function renderGlobal(data, q) {
  const terms = q.toLowerCase().split(/\s+/).filter(Boolean);
  gHits = data.hits;
  gSel = -1;
  gres.replaceChildren();
  gres.append(h('div', { class: 'ghead' }, data.total
    ? t('gsearch.summary', { total: data.total, files: data.files }) + (data.total > data.hits.length ? t('gsearch.best_shown', { n: data.hits.length }) : '')
    : t('gsearch.none')));
  let lastFile = null;
  data.hits.forEach((hit, i) => {
    if (hit.rel !== lastFile) {
      lastFile = hit.rel;
      gres.append(h('div', { class: 'gfile' }, hit.file, h('span', {}, t(hit.group))));
    }
    const where = hit.kind === 'entry' ? (hit.section ? `[${hit.section}]` : '') : t('tab.raw');
    gres.append(h('button', { class: 'ghit' + (hit.active ? '' : ' off'), 'data-i': i, onclick: () => gotoHit(hit) },
      h('span', { class: 'gkey' }, mark(hit.key, terms)),
      hit.value ? h('span', { class: 'gval' }, '= ', mark(hit.value.slice(0, 80), terms)) : null,
      h('span', { class: 'gsec' }, where, hit.active ? '' : ' · ' + t('gsearch.commented')),
      hit.help ? h('span', { class: 'ghelp' }, mark(hit.help, terms)) : null));
  });
  gres.hidden = false;
}

function moveSel(d) {
  const items = [...gres.querySelectorAll('.ghit')];
  if (!items.length) return;
  items[gSel]?.classList.remove('sel');
  gSel = (gSel + d + items.length) % items.length;
  items[gSel].classList.add('sel');
  items[gSel].scrollIntoView({ block: 'nearest' });
}

gq.addEventListener('input', () => {
  clearTimeout(gTimer);
  const q = gq.value.trim();
  if (q.length < 2) return closeGlobal();
  gTimer = setTimeout(async () => {
    const seq = ++gSeq;
    try {
      const data = await api('searchAll', { query: { q } });
      if (seq === gSeq) renderGlobal(data, q);
    } catch (e) { toast(e.message, 'err'); }
  }, 200);
});
gq.addEventListener('keydown', (ev) => {
  if (ev.key === 'ArrowDown') { ev.preventDefault(); moveSel(1); }
  else if (ev.key === 'ArrowUp') { ev.preventDefault(); moveSel(-1); }
  else if (ev.key === 'Enter') { ev.preventDefault(); const hit = gHits[gSel >= 0 ? gSel : 0]; if (hit) gotoHit(hit); }
  else if (ev.key === 'Escape') { closeGlobal(); gq.blur(); }
});
gq.addEventListener('focus', () => { if (gHits.length && gq.value.trim().length >= 2) gres.hidden = false; });
document.addEventListener('click', (ev) => { if (!ev.target.closest('.gsearch')) closeGlobal(); });
document.addEventListener('keydown', (ev) => {
  if (ev.key === '/' && !ev.target.closest('input, textarea, select')) { ev.preventDefault(); gq.focus(); gq.select(); }
});
