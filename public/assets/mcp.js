'use strict';
/* MCP editor (ModelContextProtocol.yaml) and MCP test.
   EXPERIMENTAL: targets the MCP server of VuFind pull request #4939, which is
   not merged yet. Only active for instances with an "mcp" entry in the config.
   Uses h(), $(), api(), toast(), state, t() from app.js/i18n.js. */

const SOLR_CLASS = 'VuFindApi\\Mcp\\Capabilities\\SearchSolr';
// Implementations in the pull request and their parameters (names must match the PHP arguments)
const FUNCTIONS = {
  searchRecords: { label: 'mcp.fn_searchRecords', params: { keywords: 'string', contentType: 'string' }, kind: 'tool' },
  getRecord: { label: 'mcp.fn_getRecord', params: { recordId: 'string' }, kind: 'resource' },
};
const NAME_RE = /^[A-Za-z][A-Za-z0-9_-]*$/;

const clone = (o) => JSON.parse(JSON.stringify(o ?? null));
const asObj = (v) => (v && typeof v === 'object' && !Array.isArray(v) ? v : {});
const parked = () => state.mcp?.parkedKey ?? 'GuiDisabled';

// ================================================================ Editor
async function renderMcpEditor(panel) {
  panel.append(h('p', { class: 'empty' }, t('mcp.loading')));
  let data;
  try {
    data = await api('mcpModel');
  } catch (e) {
    panel.replaceChildren(h('p', { class: 'note warn' }, e.message));
    return;
  }
  const P = parked();
  const m = clone(data.model) || {};
  m.General = asObj(m.General);
  m.Tools = asObj(m.Tools);
  m.ResourceTemplates = asObj(m.ResourceTemplates);
  m.ContentTypes = asObj(m.ContentTypes);
  m.ResponseFields = Array.isArray(m.ResponseFields) ? m.ResponseFields : ['recordPageAbsoluteLink', 'title', 'authors'];
  m[P] = asObj(m[P]);
  m[P].Tools = asObj(m[P].Tools);
  m[P].ResourceTemplates = asObj(m[P].ResourceTemplates);
  const mtime = data.mtime;

  const markDirty = () => { state.mcpDirty = true; bar.hidden = false; };
  const body = h('div', { class: 'mcp' });
  const bar = h('div', { class: 'savebar', hidden: true },
    h('span', { id: 'dirtycount' }, t('savebar.unsaved')),
    h('button', { class: 'ghost', onclick: () => { state.mcpDirty = false; openFile(MCP_FILE, true); } }, t('action.discard')),
    h('button', { class: 'primary', onclick: save }, t('mcp.save_test')));
  panel.replaceChildren(h('p', { class: 'note warn' }, t('mcp.experimental')), body, bar);

  async function save() {
    const problems = validate(m);
    if (problems.length) return toast(t('mcp.fix_first', { problems: problems.join(' · ') }), 'err');
    const out = clone(m);
    if (!Object.keys(out[P].Tools).length) delete out[P].Tools;
    if (!Object.keys(out[P].ResourceTemplates).length) delete out[P].ResourceTemplates;
    if (!Object.keys(out[P]).length) delete out[P];
    try {
      const r = await api('mcpSave', { body: { model: out, mtime } });
      state.mcpDirty = false;
      // Check right away whether the server accepts the new configuration
      const tl = await api('mcpRpc', { body: { method: 'tools/list' } });
      if (!tl.ok) toast(t('mcp.saved_no_answer', { error: tl.error }), 'err');
      else if (tl.response?.error) toast(t('mcp.saved_list_error', { error: tl.response.error.message }), 'err');
      else toast(t('mcp.saved_ok', { n: tl.response.result.tools.length }) + (r.backup ? t('toast.backup_suffix', { path: r.backup }) : ''));
      await refreshAfterWrite();
    } catch (e) {
      toast(e.message, 'err');
    }
  }

  const render = () => {
    body.replaceChildren(
      serverSection(m, markDirty, render),
      toolSection(m, markDirty, render),
      resourceSection(m, markDirty, render),
      contentTypeSection(m, data.formats, markDirty, render),
      fieldSection(m, data.fields, markDirty, render),
    );
  };
  render();
}

function validate(m) {
  const P = parked();
  const errs = [];
  [...Object.keys(m.Tools), ...Object.keys(m[P].Tools)].forEach((n) => { if (!NAME_RE.test(n)) errs.push(t('mcp.err_toolname', { name: n })); });
  for (const [n, tool] of Object.entries(m.Tools)) {
    const ct = tool.inputSchema?.properties?.contentType;
    if (ct && (!ct.enum || !ct.enum.length)) errs.push(t('mcp.err_ct_empty', { tool: n }));
    for (const v of ct?.enum ?? []) if (!m.ContentTypes[v]) errs.push(t('mcp.err_ct_missing', { tool: n, type: v }));
    if (!tool.inputSchema?.properties || !Object.keys(tool.inputSchema.properties).length) errs.push(t('mcp.err_no_params', { tool: n }));
  }
  for (const k of Object.keys(m.ContentTypes)) if (!NAME_RE.test(k)) errs.push(t('mcp.err_ct_name', { type: k }));
  if (!m.ResponseFields.length) errs.push(t('mcp.err_no_fields'));
  return errs;
}

const card = (title, hint, ...children) =>
  h('section', { class: 'mcard' }, h('h3', {}, title), hint ? h('p', { class: 'mhint' }, hint) : null, ...children);

const field = (label, input, hint) =>
  h('label', { class: 'mfield' }, h('span', {}, label), input, hint ? h('small', {}, hint) : null);

function textInput(obj, key, dirty, attrs = {}) {
  return h('input', { type: 'text', value: obj[key] ?? '', spellcheck: 'false', ...attrs,
    oninput: (e) => { if (e.target.value === '') delete obj[key]; else obj[key] = e.target.value; dirty(); } });
}

function textArea(obj, key, dirty, rows = 2) {
  const ta = h('textarea', { rows, oninput: (e) => { if (e.target.value === '') delete obj[key]; else obj[key] = e.target.value; dirty(); } });
  ta.value = obj[key] ?? '';
  return ta;
}

// ---------------------------------------------------------------- Server
function serverSection(m, dirty) {
  const g = m.General;
  const enabled = h('input', { type: 'checkbox', class: 'switch', checked: !!g.enabled, 'aria-label': t('mcp.server_enabled'),
    onchange: (e) => { g.enabled = e.target.checked; dirty(); } });
  return card(t('mcp.server'), null,
    h('div', { class: 'mrow' }, enabled, h('strong', {}, t('mcp.server_enabled')),
      h('span', { class: 'mhint inline' }, t('mcp.endpoint') + ' ', h('code', {}, state.mcp.publicUrl), ' · ' + t('mcp.transport'))),
    h('div', { class: 'mgrid' },
      field(t('mcp.name'), textInput(g, 'name', dirty, { placeholder: 'VuFind® Server' }), t('mcp.name_hint')),
      field(t('mcp.version_suffix'), textInput(g, 'versionSuffix', dirty, { placeholder: '-a' }), t('mcp.version_suffix_hint'))),
    field(t('mcp.description'), textArea(g, 'description', dirty), t('mcp.description_hint')));
}

// ---------------------------------------------------------------- Tools
function toolSection(m, dirty, render) {
  const P = parked();
  const active = Object.entries(m.Tools).map(([n, tool]) => [n, tool, true]);
  const off = Object.entries(m[P].Tools).map(([n, tool]) => [n, tool, false]);
  const add = (preset) => {
    let n = preset === 'ct' ? 'searchRecordsByContentType' : 'searchRecordsAnyType';
    for (let i = 2; m.Tools[n] || m[P].Tools[n]; i++) n = n.replace(/\d*$/, '') + i;
    // Tool texts are read by the model; VuFind's defaults are English
    const props = { keywords: { type: 'string', description: 'Keywords to search for' } };
    if (preset === 'ct') props.contentType = { type: 'string', description: 'A type of library content', enum: Object.keys(m.ContentTypes).slice(0, 1) };
    m.Tools[n] = {
      title: preset === 'ct' ? 'Search Records by Content Type' : 'Search Records',
      description: preset === 'ct' ? 'Search library catalog records for a specific content type by keywords.' : 'Search library catalog records of all content types by keywords.',
      class: SOLR_CLASS, function: 'searchRecords',
      inputSchema: { type: 'object', properties: props, required: Object.keys(props) },
    };
    dirty(); render();
  };
  return card(t('mcp.tools'), t('mcp.tools_hint'),
    ...active.concat(off).map(([n, tool, on]) => toolCard(m, n, tool, on, dirty, render)),
    h('div', { class: 'mrow' },
      h('button', { class: 'ghost', onclick: () => add('any') }, '+ ' + t('mcp.add_search_tool')),
      h('button', { class: 'ghost', onclick: () => add('ct') }, '+ ' + t('mcp.add_ct_tool'))));
}

/** Rename an entry (keeping the order) and/or move it into another object */
function moveEntry(from, to, oldName, newName) {
  const entries = Object.entries(from).map(([k, v]) => (k === oldName ? [newName, v] : [k, v]));
  for (const k of Object.keys(from)) delete from[k];
  for (const [k, v] of entries) from[k] = v;
  if (to !== from) { to[newName] = from[newName]; delete from[newName]; }
}

function toolCard(m, name, tool, on, dirty, render) {
  const P = parked();
  const home = on ? m.Tools : m[P].Tools;
  const fn = FUNCTIONS[tool.function];
  tool.inputSchema = asObj(tool.inputSchema);
  tool.inputSchema.type ??= 'object';
  const props = tool.inputSchema.properties = asObj(tool.inputSchema.properties);
  const req = tool.inputSchema.required = Array.isArray(tool.inputSchema.required) ? tool.inputSchema.required : [];

  const nameInput = h('input', { type: 'text', value: name, class: 'mname', spellcheck: 'false', 'aria-label': t('mcp.toolname'),
    onchange: (e) => {
      const nn = e.target.value.trim();
      if (nn === name) return;
      if (!NAME_RE.test(nn) || m.Tools[nn] || m[P].Tools[nn]) { toast(t('mcp.name_taken'), 'err'); e.target.value = name; return; }
      moveEntry(home, home, name, nn); dirty(); render();
    } });

  const paramRows = Object.entries(props).map(([p, def]) => {
    const isCt = p === 'contentType';
    const enumBox = isCt ? h('div', { class: 'chips' }, Object.keys(m.ContentTypes).map((ct) =>
      h('label', { class: 'chip' }, h('input', { type: 'checkbox', checked: (def.enum ?? []).includes(ct), onchange: (e) => {
        def.enum = Object.keys(m.ContentTypes).filter((k) => (k === ct ? e.target.checked : (def.enum ?? []).includes(k)));
        dirty();
      } }), ' ', ct)),
      Object.keys(m.ContentTypes).length ? null : h('small', {}, t('mcp.ct_first'))) : null;
    return h('div', { class: 'mparam' },
      h('div', { class: 'mrow' }, h('code', {}, p), h('span', { class: 'badge' }, def.type ?? 'string'),
        h('label', { class: 'mhint inline' }, h('input', { type: 'checkbox', checked: req.includes(p), onchange: (e) => {
          const i = req.indexOf(p); if (e.target.checked && i < 0) req.push(p); if (!e.target.checked && i >= 0) req.splice(i, 1); dirty();
        } }), ' ' + t('mcp.required')),
        h('button', { class: 'link danger', onclick: () => { delete props[p]; const i = req.indexOf(p); if (i >= 0) req.splice(i, 1); dirty(); render(); } }, t('action.remove'))),
      field(t('mcp.param_description'), textArea(def, 'description', dirty, 1)),
      isCt ? field(t('mcp.allowed_types'), enumBox) : null);
  });

  const missing = fn ? Object.keys(fn.params).filter((p) => !props[p]) : [];
  return h('div', { class: 'mitem' + (on ? '' : ' off') },
    h('div', { class: 'mrow' },
      h('input', { type: 'checkbox', class: 'switch', checked: on, title: on ? t('mcp.active') : t('mcp.parked'), 'aria-label': t('mcp.tool_active', { name }),
        onchange: (e) => { moveEntry(home, e.target.checked ? m.Tools : m[P].Tools, name, name); dirty(); render(); } }),
      nameInput,
      h('span', { class: 'mhint inline' }, on ? t('mcp.toolname_hint') : t('mcp.parked_hint')),
      h('button', { class: 'link danger', onclick: () => { if (confirm(t('confirm.delete_tool', { name }))) { delete home[name]; dirty(); render(); } } }, t('action.delete'))),
    h('div', { class: 'mgrid' },
      field(t('mcp.title'), textInput(tool, 'title', dirty), t('mcp.title_hint')),
      field(t('mcp.implementation'), h('select', { onchange: (e) => { tool.function = e.target.value; tool.class ||= SOLR_CLASS; dirty(); render(); } },
        Object.entries(FUNCTIONS).filter(([, f]) => f.kind === 'tool').map(([k, f]) => h('option', { value: k, selected: tool.function === k }, t(f.label))),
        fn ? null : h('option', { value: tool.function, selected: true }, t('mcp.custom_fn', { name: tool.function }))), h('code', { class: 'mclass' }, tool.class ?? ''))),
    field(t('mcp.tool_description'), textArea(tool, 'description', dirty, 2)),
    h('div', { class: 'mparams' }, h('strong', {}, t('mcp.parameters')), ...paramRows,
      missing.length ? h('div', { class: 'mrow' }, missing.map((p) => h('button', { class: 'ghost small', onclick: () => {
        props[p] = { type: fn.params[p], description: '' };
        if (p === 'contentType') props[p].enum = Object.keys(m.ContentTypes).slice(0, 1);
        dirty(); render();
      } }, `+ ${p}`))) : null));
}

// ---------------------------------------------------------------- Resources
function resourceSection(m, dirty, render) {
  const P = parked();
  const all = [...Object.entries(m.ResourceTemplates).map(([n, r]) => [n, r, true]),
    ...Object.entries(m[P].ResourceTemplates).map(([n, r]) => [n, r, false])];
  return card(t('mcp.resources'), t('mcp.resources_hint'),
    ...all.map(([n, r, on]) => {
      const home = on ? m.ResourceTemplates : m[P].ResourceTemplates;
      return h('div', { class: 'mitem' + (on ? '' : ' off') },
        h('div', { class: 'mrow' },
          h('input', { type: 'checkbox', class: 'switch', checked: on, 'aria-label': t('mcp.tool_active', { name: n }),
            onchange: (e) => { moveEntry(home, e.target.checked ? m.ResourceTemplates : m[P].ResourceTemplates, n, n); dirty(); render(); } }),
          h('code', { class: 'mname' }, n), h('code', {}, r.uriTemplate ?? ''),
          h('button', { class: 'link danger', onclick: () => { if (confirm(t('confirm.delete_tool', { name: n }))) { delete home[n]; dirty(); render(); } } }, t('action.delete'))),
        h('div', { class: 'mgrid' }, field(t('mcp.title'), textInput(r, 'title', dirty)), field(t('mcp.description'), textInput(r, 'description', dirty))));
    }),
    all.length ? null : h('button', { class: 'ghost', onclick: () => {
      m.ResourceTemplates.getRecord = { title: 'Get Record by ID', description: 'Retrieve a single record by its bibliographic id.',
        class: SOLR_CLASS, function: 'getRecord', uriTemplate: 'catalog://record/{recordId}' };
      dirty(); render();
    } }, '+ ' + t('mcp.add_record_resource')));
}

// ---------------------------------------------------------------- Content types
function contentTypeSection(m, formats, dirty, render) {
  const P = parked();
  const rows = Object.entries(m.ContentTypes).map(([k, def]) => {
    const result = h('span', { class: 'mhint inline' });
    const input = h('input', { type: 'text', value: def.filter ?? '', spellcheck: 'false', class: 'mono', 'aria-label': t('mcp.filter_for', { type: k }),
      oninput: (e) => { def.filter = e.target.value; result.textContent = ''; dirty(); } });
    const test = async () => {
      result.textContent = '…';
      try {
        const r = await api('solrFilter', { body: { fq: input.value } });
        result.textContent = r.error ? '⚠ ' + r.error.split('\n')[0] : t('solr.n_records', { n: fmtNumber(r.count) });
        result.className = 'mhint inline ' + (r.error ? 'bad' : r.count ? 'good' : 'bad');
      } catch (e) { result.textContent = e.message; }
    };
    const addFormat = h('select', { 'aria-label': t('mcp.insert_format'), onchange: (e) => {
      if (!e.target.value) return;
      const term = `format:${/\s/.test(e.target.value) ? `"${e.target.value}"` : e.target.value}`;
      const inner = input.value.replace(/^\((.*)\)$/, '$1').trim();
      input.value = def.filter = `(${inner ? inner + ' OR ' : ''}${term})`;
      e.target.value = ''; dirty(); test();
    } }, h('option', { value: '' }, '+ ' + t('mcp.format')), formats.map((f) => h('option', { value: f.value }, `${f.value} (${f.count})`)));
    return h('div', { class: 'mct' },
      h('input', { type: 'text', value: k, class: 'mname', 'aria-label': t('mcp.ct_name'), onchange: (e) => {
        const nk = e.target.value.trim();
        if (nk === k) return;
        if (!NAME_RE.test(nk) || m.ContentTypes[nk]) { toast(t('mcp.name_taken'), 'err'); e.target.value = k; return; }
        moveEntry(m.ContentTypes, m.ContentTypes, k, nk);
        for (const tool of [...Object.values(m.Tools), ...Object.values(m[P].Tools)]) {
          const en = tool.inputSchema?.properties?.contentType?.enum;
          if (en) tool.inputSchema.properties.contentType.enum = en.map((v) => (v === k ? nk : v));
        }
        dirty(); render();
      } }),
      input, addFormat, h('button', { class: 'ghost small', onclick: test }, t('action.check')), result,
      h('button', { class: 'link danger', onclick: () => {
        delete m.ContentTypes[k];
        for (const tool of [...Object.values(m.Tools), ...Object.values(m[P].Tools)]) {
          const p = tool.inputSchema?.properties?.contentType;
          if (p?.enum) p.enum = p.enum.filter((v) => v !== k);
        }
        dirty(); render();
      } }, t('action.delete')));
  });
  return card(t('mcp.content_types'), t('mcp.content_types_hint'),
    formats.length ? h('div', { class: 'chips' }, h('span', { class: 'mhint inline' }, t('mcp.formats_in_index') + ' '),
      formats.map((f) => h('span', { class: 'badge' }, `${f.value} ${f.count}`))) : null,
    ...rows,
    h('button', { class: 'ghost', onclick: () => {
      let k = 'newType'; for (let i = 2; m.ContentTypes[k]; i++) k = 'newType' + i;
      m.ContentTypes[k] = { filter: '' }; dirty(); render();
    } }, '+ ' + t('mcp.add_ct')));
}

// ---------------------------------------------------------------- Response fields
function fieldSection(m, fields, dirty, render) {
  const desc = Object.fromEntries(fields.map((f) => [f.name, f.description]));
  const list = m.ResponseFields;
  const move = (i, d) => { const j = i + d; if (j < 0 || j >= list.length) return; [list[i], list[j]] = [list[j], list[i]]; dirty(); render(); };
  const add = h('select', { 'aria-label': t('action.add_field'), onchange: (e) => {
    if (e.target.value) { list.push(e.target.value); dirty(); render(); }
  } }, h('option', { value: '' }, '+ ' + t('action.add_field')),
  fields.filter((f) => !list.includes(f.name)).map((f) => h('option', { value: f.name, title: f.description }, f.name)));
  return card(t('mcp.response_fields'), t('mcp.response_fields_hint'),
    h('ol', { class: 'mfields' }, list.map((f, i) => h('li', {},
      h('code', {}, f), h('span', { class: 'mhint inline' }, desc[f] === undefined ? '⚠ ' + t('mcp.unknown_field') : desc[f]),
      h('span', { class: 'mfbtn' },
        h('button', { class: 'link', 'aria-label': t('action.up'), onclick: () => move(i, -1) }, '↑'),
        h('button', { class: 'link', 'aria-label': t('action.down'), onclick: () => move(i, 1) }, '↓'),
        h('button', { class: 'link danger', onclick: () => { list.splice(i, 1); dirty(); render(); } }, t('action.remove')))))),
    add);
}

// ================================================================ MCP test
async function renderMcpTest(main) {
  main.replaceChildren(
    h('div', { class: 'filehead' }, h('div', {},
      h('h1', {}, t('mcptest.title')),
      h('p', { class: 'meta' }, h('code', {}, state.mcp.publicUrl), h('span', { class: 'badge local' }, 'Streamable HTTP'))),
    h('div', { class: 'actions' },
      h('button', { class: 'ghost', onclick: () => openFile(MCP_FILE) }, t('mcptest.edit_config')),
      h('button', { class: 'ghost', onclick: () => renderMcpTest(main) }, t('mcptest.reconnect')))),
    h('p', { class: 'note' }, t('mcptest.intro')));
  const status = h('div', { class: 'mcard' }, h('p', { class: 'empty' }, t('mcptest.connecting')));
  const tools = h('div', {});
  const resources = h('div', {});
  const trace = h('details', { class: 'mcard trace' }, h('summary', {}, t('mcptest.trace')), h('pre', {}, '–'));
  main.append(status, tools, resources, trace);

  const showTrace = (r) => { trace.querySelector('pre').textContent = JSON.stringify(r.trace, null, 2); };

  let list;
  try {
    list = await api('mcpRpc', { body: { method: 'tools/list' } });
  } catch (e) {
    status.replaceChildren(h('p', { class: 'note warn' }, e.message));
    return;
  }
  showTrace(list);
  if (!list.ok) {
    status.replaceChildren(h('h3', {}, t('mcptest.no_connection')), h('p', { class: 'note warn' }, list.error));
    return;
  }
  const si = list.server.serverInfo ?? {};
  status.replaceChildren(h('h3', {}, t('mcp.server')),
    h('dl', { class: 'mdl' },
      h('dt', {}, t('mcp.name')), h('dd', {}, si.name ?? '–'),
      h('dt', {}, t('mcptest.version')), h('dd', {}, si.version ?? '–'),
      h('dt', {}, t('mcp.description')), h('dd', {}, si.description ?? '–'),
      h('dt', {}, t('mcptest.protocol')), h('dd', {}, list.server.protocolVersion ?? '–'),
      h('dt', {}, t('mcptest.capabilities')), h('dd', {}, Object.keys(list.server.capabilities ?? {}).join(', '))));

  const toolList = list.response?.result?.tools ?? [];
  tools.append(h('section', { class: 'mcard' }, h('h3', {}, t('mcptest.tools_n', { n: toolList.length })),
    toolList.length ? null : h('p', { class: 'empty' }, t('mcptest.no_tools')),
    ...toolList.map((tool) => toolRunner(tool, showTrace)),
    h('details', {}, h('summary', { class: 'mhint' }, t('mcptest.tools_raw')),
      h('pre', { class: 'json' }, JSON.stringify(toolList, null, 2)))));

  const tpl = await api('mcpRpc', { body: { method: 'resources/templates/list' } }).catch(() => null);
  const templates = tpl?.response?.result?.resourceTemplates ?? [];
  if (templates.length) resources.append(h('section', { class: 'mcard' }, h('h3', {}, t('mcptest.resources_n', { n: templates.length })),
    ...templates.map((r) => resourceRunner(r, showTrace))));
}

function toolRunner(tool, showTrace) {
  const props = tool.inputSchema?.properties ?? {};
  const required = tool.inputSchema?.required ?? [];
  const inputs = {};
  const out = h('div', { class: 'mresult' });
  const form = h('form', { class: 'mform', onsubmit: async (e) => {
    e.preventDefault();
    const args = {};
    for (const [k, el] of Object.entries(inputs)) if (el.value !== '') args[k] = el.value;
    out.replaceChildren(h('p', { class: 'mhint' }, t('mcptest.calling')));
    try {
      const r = await api('mcpRpc', { body: { method: 'tools/call', params: { name: tool.name, arguments: args } } });
      showTrace(r);
      out.replaceChildren(renderToolResult(r, args));
    } catch (err) { out.replaceChildren(h('p', { class: 'note warn' }, err.message)); }
  } },
  Object.entries(props).map(([k, def]) => {
    const el = def.enum
      ? h('select', {}, def.enum.map((v) => h('option', { value: v }, v)))
      : h('input', { type: 'text', placeholder: def.description ?? k, required: required.includes(k) });
    inputs[k] = el;
    return h('label', { class: 'mfield' }, h('span', {}, k + (required.includes(k) ? ' *' : '')), el, def.description ? h('small', {}, def.description) : null);
  }),
  h('button', { class: 'primary', type: 'submit' }, t('mcptest.call')));
  return h('div', { class: 'mitem' },
    h('div', { class: 'mrow' }, h('code', { class: 'mname' }, tool.name), tool.title ? h('strong', {}, tool.title) : null),
    tool.description ? h('p', { class: 'mhint' }, tool.description) : null,
    form, out);
}

function resourceRunner(r, showTrace) {
  const vars = [...(r.uriTemplate.matchAll(/\{(\w+)\}/g))].map((x) => x[1]);
  const inputs = Object.fromEntries(vars.map((v) => [v, h('input', { type: 'text', placeholder: v, required: true })]));
  const out = h('div', { class: 'mresult' });
  return h('div', { class: 'mitem' },
    h('div', { class: 'mrow' }, h('code', { class: 'mname' }, r.uriTemplate), r.title ? h('strong', {}, r.title) : null),
    r.description ? h('p', { class: 'mhint' }, r.description) : null,
    h('form', { class: 'mform', onsubmit: async (e) => {
      e.preventDefault();
      const uri = r.uriTemplate.replace(/\{(\w+)\}/g, (_, v) => encodeURIComponent(inputs[v].value));
      out.replaceChildren(h('p', { class: 'mhint' }, t('mcptest.loading_uri', { uri })));
      try {
        const res = await api('mcpRpc', { body: { method: 'resources/read', params: { uri } } });
        showTrace(res);
        const err = res.error || res.response?.error?.message;
        const c = res.response?.result?.contents?.[0];
        out.replaceChildren(err ? h('p', { class: 'note warn' }, err)
          : h('pre', { class: 'json' }, (() => { try { return JSON.stringify(JSON.parse(c.text), null, 2); } catch { return c?.text ?? ''; } })()));
      } catch (err) { out.replaceChildren(h('p', { class: 'note warn' }, err.message)); }
    } }, vars.map((v) => h('label', { class: 'mfield' }, h('span', {}, v + ' *'), inputs[v])),
    h('button', { class: 'primary', type: 'submit' }, t('mcptest.fetch'))), out);
}

/** Link to the full VuFind result list; warns if the link lacks the content type filter */
function resultsPageLink(url, args) {
  if (!url) return h('p', { class: 'mhint' }, t('mcptest.no_results_link'));
  let filtered = false;
  try { filtered = [...new URL(url).searchParams.keys()].some((k) => k.startsWith('filter')); } catch { /* not an absolute URL */ }
  return h('div', { class: 'mpage' },
    h('a', { href: url, target: 'vufind', class: 'ghost small' }, t('mcptest.full_results')),
    h('code', { class: 'mhint inline' }, url),
    args?.contentType && !filtered ? h('p', { class: 'note warn' }, t('mcptest.filter_missing', { type: args.contentType })) : null);
}

function renderToolResult(r, args = {}) {
  if (!r.ok) return h('p', { class: 'note warn' }, r.error);
  if (r.response?.error) return h('p', { class: 'note warn' }, t('mcptest.error', { code: r.response.error.code, message: r.response.error.message }));
  const res = r.response?.result ?? {};
  const text = (res.content ?? []).map((c) => c.text ?? '').join('\n');
  if (res.isError) return h('p', { class: 'note warn' }, text || t('mcptest.tool_error'));
  let data = res.structuredContent ?? null;
  if (!data) { try { data = JSON.parse(text); } catch { /* not JSON */ } }
  const hits = data?.search_results;
  const raw = h('details', {}, h('summary', { class: 'mhint' }, t('mcptest.raw_answer', { n: fmtNumber(text.length) })),
    h('pre', { class: 'json' }, data ? JSON.stringify(data, null, 2) : text));
  if (!Array.isArray(hits)) return h('div', {}, raw);
  const names = (a) => (a && typeof a === 'object' ? Object.keys(a.primary ?? {}).concat(Object.keys(a.secondary ?? {})).join('; ') : '');
  return h('div', {},
    h('p', { class: 'mhint' }, t('mcptest.n_hits', { n: hits.length })),
    resultsPageLink(data.search_results_page, args),
    h('div', { class: 'tablewrap' }, h('table', { class: 'mtable' },
      h('thead', {}, h('tr', {}, ['col.title', 'col.persons', 'col.format', 'col.year'].map((c) => h('th', {}, t(c))))),
      h('tbody', {}, hits.map((x) => h('tr', {},
        h('td', {}, x.recordPageAbsoluteLink ? h('a', { href: x.recordPageAbsoluteLink, target: 'vufind' }, x.title ?? x.id) : (x.title ?? x.id ?? '–')),
        h('td', {}, names(x.authors)),
        h('td', {}, (x.formats ?? []).join(', ')),
        h('td', {}, (x.publicationDates ?? []).join(', '))))))),
    raw);
}
