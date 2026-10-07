'use strict';
/* Ranking editor for searchspecs.yaml: field weights per search type, dismax
   parameters, global extra parameters and a before/after preview run directly
   against Solr. Uses h(), api(), toast(), state, t() from app.js/i18n.js and
   card(), field(), clone() from mcp.js. */

const GLOBAL = 'GlobalExtraParams';

// Known dismax parameters; explanations come from the language files (ranking.param_<name>)
const DISMAX_PARAMS = ['mm', 'tie', 'pf', 'pf2', 'pf3', 'ps', 'qs', 'bq', 'bf', 'boost'];
const paramHelp = (name) => (DISMAX_PARAMS.includes(name) ? t('ranking.param_' + name) : null);
const CONDITIONS = ['SearchTypeIn', 'SearchTypeNotIn', 'AllSearchTypesIn', 'NoDismaxParams', 'SortIn', 'SortNotIn'];

/** Stable comparison regardless of key order */
const canon = (v) => JSON.stringify(v, (k, x) => (x && typeof x === 'object' && !Array.isArray(x)
  ? Object.fromEntries(Object.keys(x).sort().map((key) => [key, x[key]])) : x));
const same = (a, b) => canon(a ?? null) === canon(b ?? null);

const parseField = (s) => { const m = String(s).match(/^(.*?)(?:\^([\d.]+))?$/); return { name: m[1], boost: m[2] ?? '' }; };
const fmtField = (f) => f.name + (f.boost !== '' && f.boost !== undefined ? '^' + f.boost : '');
const isDismax = (s) => Array.isArray(s?.DismaxFields) && s.DismaxFields.length > 0;

// ================================================================ Editor
async function renderRankingEditor(panel) {
  panel.append(h('p', { class: 'empty' }, t('ranking.loading')));
  let data;
  try {
    data = await api('specModel');
  } catch (e) {
    panel.replaceChildren(h('p', { class: 'note warn' }, e.message));
    return;
  }
  if (data.directives.length) {
    panel.replaceChildren(h('p', { class: 'note warn' }, t('ranking.directives', { list: data.directives.join(', ') })));
    return;
  }

  const orig = data.orig;
  // Effective configuration as in VuFind: local sections replace sections of the same name
  const effective = { ...orig, ...(data.local ?? {}) };
  const saved = clone(effective);
  const draft = clone(effective);
  const solr = { total: data.solr.total, byName: Object.fromEntries(data.solr.fields.map((f) => [f.name, f])) };
  const types = Object.keys(draft).filter((k) => k !== GLOBAL && !k.startsWith('@'));
  if (!state.specType || !(state.specType in draft || state.specType === GLOBAL)) state.specType = 'AllFields';

  const bar = h('div', { class: 'savebar', hidden: true },
    h('span', { id: 'dirtycount' }, t('savebar.unsaved')),
    h('button', { class: 'ghost', onclick: () => { state.specDirty = false; openFile(SPEC_FILE, true); } }, t('action.discard')),
    h('button', { class: 'primary', onclick: save }, t('action.save')));
  const nav = h('nav', { class: 'rknav', 'aria-label': t('ranking.searchtypes') });
  const editor = h('div', { class: 'rkedit' });
  const preview = h('section', { class: 'mcard rkpreview' });

  const notes = [];
  if (data.localComments) notes.push(h('p', { class: 'note warn' }, t('ranking.comments_lost')));
  if (!state.hasSolr) notes.push(h('p', { class: 'note warn' }, t('ranking.no_solr')));
  panel.replaceChildren(
    h('p', { class: 'note' }, t('ranking.intro')),
    ...notes,
    h('div', { class: 'rk' }, nav, h('div', { class: 'rkmain' }, editor, preview)),
    bar);

  const changedFromOrig = (k) => !same(draft[k], orig[k]);
  const markDirty = () => {
    state.specDirty = !same(draft, saved);
    bar.hidden = !state.specDirty;
    renderNav();
    schedulePreview();
  };

  async function save() {
    const overrides = {};
    for (const k of Object.keys(draft)) if (changedFromOrig(k)) overrides[k] = draft[k];
    try {
      const r = await api('specSave', { body: { overrides, mtime: data.mtime } });
      state.specDirty = false;
      toast(r.removedLocal ? t('ranking.saved_removed')
        : t('ranking.saved_n', { n: Object.keys(overrides).length }) + (r.backup ? t('toast.backup_suffix', { path: r.backup }) : ''));
      await refreshAfterWrite();
    } catch (e) { toast(e.message, 'err'); }
  }

  function renderNav() {
    const item = (k, label, sub) => h('button', {
      class: 'file' + (state.specType === k ? ' active' : ''),
      onclick: () => { state.specType = k; renderNav(); renderEditor(); runPreview(); },
    }, h('span', { class: 'fname' }, label), sub ? h('span', { class: 'flabel' }, sub) : null,
    changedFromOrig(k) ? h('span', { class: 'badge local' }, t('ranking.changed')) : null);
    const dismax = types.filter((k) => isDismax(draft[k]));
    const lucene = types.filter((k) => !isDismax(draft[k]));
    nav.replaceChildren(
      h('div', { class: 'group' }, h('h2', {}, t('ranking.searchtypes')),
        dismax.map((k) => item(k, k, t('ranking.n_fields', { n: draft[k].DismaxFields.length })))),
      h('div', { class: 'group' }, h('h2', {}, t('ranking.all_types')),
        item(GLOBAL, t('ranking.extra_params'), t('ranking.n_rules', { n: (draft[GLOBAL] ?? []).length }))),
      h('details', { class: 'group others' }, h('summary', {}, t('ranking.lucene_only', { n: lucene.length })),
        lucene.map((k) => item(k, k, 'QueryFields'))));
  }

  function renderEditor() {
    const k = state.specType;
    editor.replaceChildren(k === GLOBAL ? globalCard(draft, markDirty, renderEditor) : typeCard(k));
  }

  function typeCard(k) {
    const s = draft[k];
    const resetBtn = changedFromOrig(k) ? h('button', { class: 'ghost small', onclick: () => {
      if (k in orig) draft[k] = clone(orig[k]); else delete draft[k];
      markDirty(); renderEditor();
    } }, '↺ ' + t('entry.reset')) : null;
    const head = h('div', { class: 'mrow' }, h('h3', { class: 'rktitle' }, k),
      changedFromOrig(k) ? h('span', { class: 'badge local' }, t('ranking.differs')) : h('span', { class: 'badge' }, t('badge.default')),
      h('span', { class: 'rkspacer' }), resetBtn);
    if (!isDismax(s)) {
      return h('section', { class: 'mcard' }, head,
        h('p', { class: 'mhint' }, t('ranking.queryfields_hint')),
        h('pre', { class: 'json' }, JSON.stringify(s, null, 2)));
    }
    const exactOn = !!s.ExactSettings;
    return h('section', { class: 'mcard' }, head,
      h('p', { class: 'mhint' }, typeHint(k)),
      fieldsEditor(s, orig[k]?.DismaxFields, solr, markDirty, renderEditor),
      paramsEditor(s, markDirty, renderEditor),
      h('details', { class: 'rkexact', open: exactOn },
        h('summary', {}, t('ranking.exact'), exactOn ? h('span', { class: 'badge local' }, t('ranking.exact_own')) : h('span', { class: 'badge' }, t('ranking.exact_same'))),
        h('p', { class: 'mhint' }, t('ranking.exact_hint')),
        exactOn
          ? h('div', {}, fieldsEditor(s.ExactSettings, orig[k]?.ExactSettings?.DismaxFields, solr, markDirty, renderEditor),
            h('button', { class: 'link danger', onclick: () => { delete s.ExactSettings; markDirty(); renderEditor(); } }, t('ranking.exact_remove')))
          : h('button', { class: 'ghost small', onclick: () => {
            s.ExactSettings = { DismaxFields: s.DismaxFields.filter((f) => /unstemmed|isbn|issn/.test(f)), DismaxHandler: s.DismaxHandler ?? 'edismax' };
            if (!s.ExactSettings.DismaxFields.length) s.ExactSettings.DismaxFields = [...s.DismaxFields];
            markDirty(); renderEditor();
          } }, '+ ' + t('ranking.exact_add'))));
  }

  // ---------------------------------------------------------------- Preview
  let timer = null;
  function schedulePreview() { clearTimeout(timer); timer = setTimeout(runPreview, 450); }

  const qInput = h('input', { type: 'search', value: state.specQ ?? '', placeholder: t('ranking.preview_placeholder'), 'aria-label': t('ranking.preview_label'),
    oninput: schedulePreview });
  const out = h('div', { class: 'rkout' });
  preview.append(
    h('h3', {}, t('ranking.preview_title')),
    h('p', { class: 'mhint' }, t('ranking.preview_hint')),
    h('form', { class: 'mrow', onsubmit: (e) => { e.preventDefault(); runPreview(); } },
      qInput, h('button', { class: 'primary', type: 'submit' }, t('ranking.compare'))),
    out);

  async function runPreview() {
    const q = qInput.value.trim();
    state.specQ = q;
    if (!state.hasSolr) { out.replaceChildren(h('p', { class: 'mhint' }, t('ranking.no_solr'))); return; }
    if (!q) { out.replaceChildren(h('p', { class: 'mhint' }, t('ranking.enter_terms'))); return; }
    const type = state.specType === GLOBAL ? 'AllFields' : state.specType;
    const req = (cfg) => api('specPreview', { body: { q, type, spec: cfg[type] ?? {}, global: cfg[GLOBAL] ?? [], all: { [type]: cfg[type] ?? {} } } });
    const changed = !same(draft[type], saved[type]) || !same(draft[GLOBAL], saved[GLOBAL]);
    out.replaceChildren(h('p', { class: 'mhint' }, t('ranking.asking_solr')));
    try {
      const [a, b] = await Promise.all([req(saved), changed ? req(draft) : null]);
      out.replaceChildren(previewResult(a, b, q, type));
    } catch (e) { out.replaceChildren(h('p', { class: 'note warn' }, e.message)); }
  }

  renderNav();
  renderEditor();
  runPreview();
}

function typeHint(k) {
  const key = 'ranking.type_' + k;
  const text = t(key);
  return text === key ? t('ranking.type_default') : text;
}

// ---------------------------------------------------------------- Fields & weights
function fieldsEditor(s, origFields, solr, dirty, render) {
  const list = s.DismaxFields.map(parseField);
  const origBoost = Object.fromEntries((origFields ?? []).map(parseField).map((f) => [f.name, f.boost]));
  const max = Math.max(1, ...list.map((f) => Number(f.boost || 1)));
  const write = () => { s.DismaxFields = list.map(fmtField); dirty(); };
  const hasStats = Object.keys(solr.byName).length > 0;

  const rows = list.map((f, i) => {
    const st = solr.byName[f.name];
    const bar = h('span', { class: 'rkbar' }, h('span', { style: `width:${Math.max(2, (Number(f.boost || 1) / max) * 100)}%` }));
    const input = h('input', { type: 'number', min: '0', step: 'any', value: f.boost, placeholder: '1', class: 'mono', 'aria-label': t('ranking.weight_of', { field: f.name }),
      oninput: (e) => {
        f.boost = e.target.value;
        bar.firstChild.style.width = Math.max(2, (Number(f.boost || 1) / max) * 100) + '%';
        write();
      },
      onchange: render });
    let fill = null;
    if (!hasStats) fill = h('span', { class: 'badge' }, '–');
    else if (!st) fill = h('span', { class: 'badge warn', title: t('ranking.not_in_index_title') }, t('ranking.not_in_index'));
    else if (!st.docs) fill = h('span', { class: 'badge warn', title: t('ranking.empty_field_title') }, t('ranking.empty_field', { type: st.type }));
    else fill = h('span', { class: 'badge', title: t('ranking.field_type', { type: st.type }) }, t('ranking.field_fill', { n: st.docs, total: solr.total, type: st.type }));
    const ob = origBoost[f.name];
    const def = f.name in origBoost
      ? (ob !== f.boost ? h('button', { class: 'link', title: t('ranking.weight_reset_title'), onclick: () => { f.boost = ob; write(); render(); } }, `↺ ${ob || '1'}`) : null)
      : h('span', { class: 'badge local' }, t('ranking.new'));
    return h('div', { class: 'rkfield' },
      h('code', { class: 'rkname' }, f.name), fill, bar, input, h('span', { class: 'rkdef' }, def),
      h('button', { class: 'link danger', 'aria-label': t('ranking.remove_field', { field: f.name }), onclick: () => { list.splice(i, 1); write(); render(); } }, t('action.remove')));
  });

  const used = new Set(list.map((f) => f.name));
  const candidates = Object.values(solr.byName).filter((f) => !used.has(f.name) && f.docs > 0 && !/^(_|id$|.*_str_mv$|.*_facet$)/.test(f.name))
    .sort((a, b) => a.name.localeCompare(b.name));
  const add = h('select', { 'aria-label': t('action.add_field'), onchange: (e) => {
    if (!e.target.value) return;
    list.push({ name: e.target.value, boost: '' }); write(); render();
  } }, h('option', { value: '' }, '+ ' + t('action.add_field')),
  candidates.map((f) => h('option', { value: f.name }, t('ranking.candidate', { field: f.name, n: f.docs, type: f.type }))));

  return h('div', { class: 'rkfields' },
    h('div', { class: 'rkfield rkhead' }, h('span', {}, t('ranking.col_field')), h('span', {}, t('ranking.col_fill')), h('span', {}, t('ranking.col_weight')),
      h('span', {}, 'Boost'), h('span', {}, ''), h('span', {}, '')),
    rows,
    h('div', { class: 'mrow' }, hasStats ? add : null, h('small', { class: 'mhint inline' }, t('ranking.boost_hint'))));
}

// ---------------------------------------------------------------- Dismax parameters
function paramsEditor(s, dirty, render) {
  const params = Array.isArray(s.DismaxParams) ? s.DismaxParams : [];
  const write = () => { if (params.length) s.DismaxParams = params; else delete s.DismaxParams; dirty(); };
  const rows = params.map((p, i) => h('div', { class: 'rkparam' },
    h('input', { type: 'text', value: p[0] ?? '', list: 'rk-dismax-params', class: 'mono', 'aria-label': t('ranking.param_name'),
      oninput: (e) => { p[0] = e.target.value; write(); }, onchange: render }),
    h('input', { type: 'text', value: p[1] ?? '', class: 'mono', 'aria-label': t('ranking.param_value', { name: p[0] }), spellcheck: 'false',
      oninput: (e) => { p[1] = e.target.value; write(); } }),
    h('button', { class: 'link danger', onclick: () => { params.splice(i, 1); write(); render(); } }, t('action.remove')),
    paramHelp(p[0]) ? h('small', { class: 'mhint inline rkphelp' }, paramHelp(p[0])) : null));
  const fqResult = h('span', { class: 'mhint inline' });
  const fq = h('input', { type: 'text', value: s.FilterQuery ?? '', class: 'mono', placeholder: t('ranking.fq_placeholder'), spellcheck: 'false', 'aria-label': t('ranking.fq'),
    oninput: (e) => { if (e.target.value) s.FilterQuery = e.target.value; else delete s.FilterQuery; fqResult.textContent = ''; dirty(); } });
  const testFq = async () => {
    if (!fq.value) return;
    const r = await api('solrFilter', { body: { fq: fq.value } }).catch((e) => ({ error: e.message }));
    fqResult.textContent = r.error ? '⚠ ' + r.error.split('\n')[0] : t('solr.n_records', { n: fmtNumber(r.count) });
    fqResult.className = 'mhint inline ' + (r.error || !r.count ? 'bad' : 'good');
  };
  return h('div', { class: 'rkparams' },
    h('h4', {}, t('mcp.parameters')),
    h('div', { class: 'mgrid' },
      field(t('ranking.handler'), h('select', { onchange: (e) => { s.DismaxHandler = e.target.value; dirty(); render(); } },
        ['edismax', 'dismax'].map((v) => h('option', { value: v, selected: (s.DismaxHandler ?? 'edismax') === v }, v))),
      t('ranking.handler_hint')),
      field(t('ranking.fq'), h('div', { class: 'mrow tight' }, fq, state.hasSolr ? h('button', { class: 'ghost small', type: 'button', onclick: testFq }, t('action.check')) : null, fqResult),
        t('ranking.fq_hint'))),
    h('div', { class: 'mfield' }, h('span', {}, t('ranking.dismax_params')),
      rows.length ? rows : h('small', {}, t('ranking.no_params', { mm: (s.DismaxHandler ?? 'edismax') === 'edismax' ? '0%' : '100%' }))),
    h('div', { class: 'mrow' },
      h('button', { class: 'ghost small', onclick: () => { params.push(['', '']); write(); render(); } }, '+ ' + t('ranking.param')),
      ...['mm', 'tie', 'pf', 'bq'].filter((n) => !params.some((p) => p[0] === n)).map((n) =>
        h('button', { class: 'ghost small', title: paramHelp(n), onclick: () => {
          params.push([n, { mm: '100%', tie: '0.1', pf: 'title_full^200', bq: 'format:Book^5' }[n]]); write(); render();
        } }, '+ ' + n))),
    h('datalist', { id: 'rk-dismax-params' }, DISMAX_PARAMS.map((n) => h('option', { value: n }))));
}

// ---------------------------------------------------------------- Global extra parameters
function globalCard(draft, dirty, render) {
  const list = Array.isArray(draft[GLOBAL]) ? draft[GLOBAL] : [];
  const write = () => { if (list.length) draft[GLOBAL] = list; else delete draft[GLOBAL]; dirty(); };
  const types = Object.keys(draft).filter((k) => k !== GLOBAL && isDismax(draft[k]));

  const ruleCard = (r, i) => {
    r.conditions = Array.isArray(r.conditions) ? r.conditions : [];
    const val = Array.isArray(r.value) ? r.value.join('\n') : (r.value ?? '');
    const condRows = r.conditions.map((c, j) => {
      const key = Object.keys(c)[0] ?? 'SearchTypeIn';
      const values = [].concat(c[key] ?? []);
      const setValues = (v) => { r.conditions[j] = { [key]: v }; write(); };
      const valueInput = /SearchType/.test(key)
        ? h('div', { class: 'chips' }, types.map((type) => h('label', { class: 'chip' },
          h('input', { type: 'checkbox', checked: values.includes(type), onchange: (e) => {
            setValues(e.target.checked ? [...values, type] : values.filter((x) => x !== type)); render();
          } }), ' ', type)))
        : h('input', { type: 'text', value: values.join(', '), class: 'mono', placeholder: key.startsWith('Sort') ? 'score desc' : 'bf, bq',
          oninput: (e) => setValues(e.target.value.split(',').map((x) => x.trim()).filter(Boolean)) });
      return h('div', { class: 'rkcond' },
        h('select', { 'aria-label': t('ranking.condition'), onchange: (e) => { r.conditions[j] = { [e.target.value]: values }; write(); render(); } },
          CONDITIONS.map((k) => h('option', { value: k, selected: k === key }, `${k} – ${t('ranking.cond_' + k)}`))),
        valueInput,
        h('button', { class: 'link danger', onclick: () => { r.conditions.splice(j, 1); write(); render(); } }, t('action.remove')));
    });
    return h('div', { class: 'mitem' },
      h('div', { class: 'mrow' }, h('strong', {}, t('ranking.rule_n', { n: i + 1 })),
        paramHelp(r.param) ? h('span', { class: 'mhint inline' }, paramHelp(r.param)) : null,
        h('span', { class: 'rkspacer' }),
        h('button', { class: 'link danger', onclick: () => { list.splice(i, 1); write(); render(); } }, t('action.delete'))),
      h('div', { class: 'rkrule' },
        field(t('ranking.rule_param'), h('input', { type: 'text', value: r.param ?? '', list: 'rk-dismax-params', class: 'mono',
          oninput: (e) => { r.param = e.target.value; write(); }, onchange: render })),
        field(t('ranking.rule_value'), (() => {
          const ta = h('textarea', { rows: 1, class: 'mono', spellcheck: 'false', oninput: (e) => {
            const v = e.target.value.split('\n').map((x) => x.trim()).filter(Boolean);
            r.value = v.length > 1 ? v : (v[0] ?? ''); write();
          } });
          ta.value = val; return ta;
        })())),
      h('div', { class: 'mfield' }, h('span', {}, t('ranking.conditions')),
        condRows.length ? condRows : h('small', {}, t('ranking.no_conditions')),
        h('div', {}, h('button', { class: 'ghost small', onclick: () => { r.conditions.push({ SearchTypeIn: ['AllFields'] }); write(); render(); } }, '+ ' + t('ranking.condition')))));
  };

  const presets = [
    ['ranking.preset_recent', { param: 'bf', value: 'recip(rord(publishDateSort),1,1000,1000)',
      conditions: [{ SearchTypeIn: ['AllFields', 'Title'] }, { NoDismaxParams: ['bf', 'bq'] }, { SortIn: ['score desc'] }] }],
    ['ranking.preset_books', { param: 'bq', value: 'format:Book^5', conditions: [{ SearchTypeIn: ['AllFields'] }] }],
    ['ranking.preset_language', { param: 'bq', value: t('ranking.preset_language_value'), conditions: [{ SearchTypeIn: ['AllFields'] }] }],
  ];
  return h('section', { class: 'mcard' },
    h('div', { class: 'mrow' }, h('h3', { class: 'rktitle' }, t('ranking.global_title')),
      draft[GLOBAL] ? h('span', { class: 'badge local' }, t('ranking.global_active')) : h('span', { class: 'badge' }, t('ranking.global_none'))),
    h('p', { class: 'mhint' }, t('ranking.global_hint')),
    ...list.map(ruleCard),
    h('div', { class: 'mrow' },
      h('button', { class: 'ghost small', onclick: () => { list.push({ param: '', value: '', conditions: [] }); write(); render(); } }, '+ ' + t('ranking.rule')),
      presets.map(([label, rule]) => h('button', { class: 'ghost small', onclick: () => { list.push(clone(rule)); write(); render(); } }, '+ ' + t(label)))),
    h('datalist', { id: 'rk-dismax-params' }, DISMAX_PARAMS.map((n) => h('option', { value: n }))));
}

// ---------------------------------------------------------------- Preview result
function previewResult(a, b, q, type) {
  const vufind = state.vufindUrl ? h('a', { class: 'ghost small', target: 'vufind',
    href: `${state.vufindUrl}/Search/Results?${new URLSearchParams({ lookfor: q, type })}` }, t('ranking.open_in_vufind')) : null;
  if (a.unsupported) return h('p', { class: 'note warn' }, a.unsupported);
  if (a.error) return h('p', { class: 'note warn' }, 'Solr: ' + a.error);
  if (b?.error) return h('p', { class: 'note warn' }, t('ranking.solr_draft_error', { error: b.error }));
  const rank = Object.fromEntries(a.docs.map((d, i) => [d.id, i]));
  const table = (res, compare) => h('div', { class: 'tablewrap' }, h('table', { class: 'mtable rktable' },
    h('thead', {}, h('tr', {}, ['#', t('col.title'), t('col.year'), 'Score', compare ? 'Δ' : null].filter(Boolean).map((c) => h('th', {}, c)))),
    h('tbody', {}, res.docs.map((d, i) => {
      let delta = null;
      if (compare) {
        const before = rank[d.id];
        delta = before === undefined ? h('span', { class: 'rkup' }, t('ranking.new'))
          : before > i ? h('span', { class: 'rkup' }, `▲ ${before - i}`)
          : before < i ? h('span', { class: 'rkdown' }, `▼ ${i - before}`) : h('span', { class: 'mhint inline' }, '–');
      }
      const title = d.title || d.id;
      return h('tr', {},
        h('td', { class: 'rknum' }, i + 1),
        h('td', {}, state.vufindUrl ? h('a', { href: `${state.vufindUrl}/Record/${encodeURIComponent(d.id)}`, target: 'vufind' }, title) : title,
          d.author ? h('div', { class: 'mhint inline' }, d.author) : null),
        h('td', {}, d.year),
        h('td', { class: 'rknum' }, d.score?.toFixed(2)),
        compare ? h('td', { class: 'rknum' }, delta) : null);
    }))));
  const params = (res) => h('details', {}, h('summary', { class: 'mhint' }, t('ranking.solr_params')),
    h('pre', { class: 'json' }, res.params.map((p) => `${p[0]} = ${p[1]}`).join('\n')));
  const col = (title, res, compare) => h('div', { class: 'rkcol' },
    h('h4', {}, title, ' ', h('span', { class: 'badge' }, t('ranking.n_hits', { n: fmtNumber(res.numFound) }))),
    res.note ? h('p', { class: 'mhint' }, res.note) : null,
    table(res, compare), params(res));
  return h('div', {},
    h('div', { class: 'mrow' }, vufind, h('span', { class: 'mhint inline' }, t('ranking.first_20', { type }))),
    b ? h('div', { class: 'rkcols' }, col(t('ranking.col_saved'), a, false), col(t('ranking.col_draft'), b, true))
      : col(t('ranking.col_current'), a, false));
}
