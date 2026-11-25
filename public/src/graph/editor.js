import { batch, get, post, quietGet } from 'goblin/api/client.js';
import { mountGraphCanvas } from 'goblin/graph/renderer.js';
import { contextKey, loadContext, readAddress, readGraph, writeAddress } from 'goblin/graph/navigation.js';
import { KINDS } from 'goblin/core/kinds.js';
import { mountGraphRun } from 'goblin/graph/run.js';
import { mountGraphProjects } from 'goblin/graph/projects.js';
import { mountGraphAssistant } from 'goblin/graph/assistant.js';
import { blockContentEditor } from 'goblin/graph/content.js';
import { GRAPH_SKIN_STORE, LOOKS } from 'goblin/canvas/looks.js';
import { t } from 'goblin/core/i18n.js';

const ui = (name) => document.getElementById('graph-' + name);
const dialog = ui('dialog');
const panel = ui('panel');
const readOnly = document.body.dataset.viewOnly === 'true';
const skinSelect = ui('skin');
for (const [id, skin] of Object.entries(LOOKS)) {
  skinSelect.add(new Option(skin.title, id));
}
const catalogEnabled = document.body.dataset.catalogEnabled === 'true';
const compactLayout = matchMedia('(max-width: 650px), (pointer: coarse) and (max-width: 900px)');
const layoutControl = ui('layout');
const layoutKey = 'goblin-graph-layout';
const kinds = {
  project: t('editor.settings.project'),
  folder: t('editor.linksview.folder'),
  scheme: t('editor.embed.scheme'),
  block: t('editor.mobile.tab_block'),
  decision: t('editor.graph.kind.decision'),
  gateway: t('editor.graph.kind.gateway'),
  group: t('editor.tools.group_section'),
  area: t('editor.graph.kind.area'),
  note: t('editor.graph.kind.note'),
  table: t('editor.table.section'),
  agent: t('editor.panel.agent'),
  role: t('editor.agent.role'),
  asset: t('editor.graph.kind.material'),
  material: t('editor.graph.kind.material'),
};
const directions = {
  forward: t('editor.graph.direction.forward'),
  reverse: t('editor.graph.direction.reverse'),
  both: t('editor.graph.direction.both'),
  none: t('editor.graph.direction.none'),
};

let project = '';
let legacyWithoutGraph = false;
let pinnedRun = null;
let model = null;
let rev = null;
let ready = false;
let busy = false;
let polling = false;
let panelDirty = false;
let panelTurn = 0;
let expandedMaterials = null;
let expandedContent = null;
let addressChanged = false;
let tool = 'select';
let view = 'canvas';
let runPaint = null;
let expanded = new Set();
let pending = null;
let retry = null;
let projectsStamp = null;

function node(tag, className, text) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (text !== undefined) element.textContent = text;
  return element;
}

function button(text, action, className = 'btn') {
  const result = node('button', className, text);
  result.type = 'button';
  result.addEventListener('click', action);
  return result;
}

function field(label, control) {
  const wrap = node('label', 'field');
  control.classList.add('input');
  wrap.append(node('span', '', label), control);
  return wrap;
}

function input(name, value = '') {
  const result = node('input');
  result.name = name;
  result.value = value;
  return result;
}

function textarea(name, value) {
  const result = node('textarea');
  result.name = name;
  result.value = value;
  return result;
}

function select(name, choices, value) {
  const result = node('select');
  result.name = name;
  for (const [key, label] of Object.entries(choices)) result.add(new Option(label, key));
  result.value = value;
  return result;
}

function title(id) {
  const block = model.block(id);
  return block?.title || kinds[block?.kind] || t('editor.graph.block_id', { id });
}

function status(message = '', action = null) {
  ui('status').textContent = message;
  ui('retry').hidden = !action;
  retry = action;
}

function syncControls() {
  ui('create').disabled = busy || !!pending || !project || !ready || legacyWithoutGraph;
  ui('connect').disabled = busy || !!pending || !ready || !model || !model.placementsOf(model.context.blockId).length;
  ui('fit').disabled = busy || !model;
  ui('fit').hidden = view === 'list';
  ui('save').disabled = busy || !!pending || !model;
  ui('connect').hidden = readOnly || view === 'list';
  ui('create').hidden = readOnly;
  ui('catalog').hidden = readOnly || !catalogEnabled || !project || !ready;
  ui('catalog').disabled = busy || !!pending || legacyWithoutGraph;
  ui('view-list').disabled = busy || !model;
  ui('retry').disabled = busy;
  ui('connect').setAttribute('aria-pressed', tool === 'connect');
  ui('canvas').inert = busy || !!pending;
  ui('assistant').inert = busy || !!pending;
  ui('projects').inert = busy || !!pending;
  ui('dialog-submit').disabled = busy;
  ui('dialog-cancel').disabled = busy;
  panel.inert = busy || !!pending || !ready;
}

async function perform(action) {
  if (busy) return;
  busy = true;
  syncControls();
  try {
    await action();
  } catch (error) {
    status(error.message || t('editor.graph.action_failed'), loadAddress);
  } finally {
    busy = false;
    syncControls();
    if (addressChanged && !pending) {
      addressChanged = false;
      void loadAddress();
    }
  }
}

const canvas = mountGraphCanvas(ui('canvas'), {
  readOnly,
  onSelect({ blockId, additive }) {
    pickBlock(blockId, additive);
  },
  onOpen({ placementId }) {
    if (tool === 'select' && model && model.placement(placementId)?.parentId === model.context.blockId) {
      go({ ...model.context, path: [...model.context.path, placementId] });
    }
  },
  onMove({ placementId, x, y }) {
    if (readOnly) {
      renderCanvas();
      return;
    }
    if (!busy && !pending && model?.placementsOf(model.context.blockId).some(({ id }) => id === placementId)) {
      void save([model.updatePlacement(placementId, { x, y })]);
    }
  },
  onConnect(ends) {
    if (busy || pending) return;
    tool = 'select';
    syncControls();
    renderCanvas();
    status();
    editArrow(ends);
  },
  onEmpty(at) {
    if (tool === 'select') createBlock(at);
  },
});

const runPanel = mountGraphRun(ui('run'), {
  readOnly,
  onPaint(paint) {
    runPaint = paint;
    renderCanvas();
    updateListSelection();
  },
  onFocus(blockId) {
    if (model?.placementsOf(model.context.blockId).some((placement) => placement.blockId === blockId)) pickBlock(blockId);
  },
});
const projectsPanel = mountGraphProjects(ui('projects'), {
  activeKey: readAddress().project,
  async onOpen(key, rootId) {
    if (busy || pending) return;
    writeAddress(key, rootId ? { startId: rootId, blockId: rootId, path: [] } : null, false, null, false);
    await loadAddress();
  },
});
const assistantPanel = mountGraphAssistant(ui('assistant'), {
  readOnly,
  getContext: () => !readOnly && model && ready ? {
    project, parent: model.context.blockId, projectRoot: model.context.blockId === model.rootId,
  } : null,
  onApplied: (_answer, context) => context.project === project ? loadAddress() : undefined,
});

function pickBlock(blockId, additive = false) {
  if (busy || !model) return;
  const ids = new Set(additive ? model.selection : []);
  if (blockId != null) {
    if (ids.has(blockId)) ids.delete(blockId);
    else ids.add(blockId);
  }
  model.select(ids);
  renderCanvas();
  updateListSelection();
  renderPanel();
}

function canvasScene() {
  const scene = model?.scene() ?? { parentId: null, placements: [], arrows: [], previews: {} };
  return {
    ...scene,
    project,
    arrows: scene.arrows.filter((arrow) => arrow.kind !== 'link'
      || !arrow.properties.relation || ['asset', 'agent'].includes(arrow.properties.relation)),
    blocks: scene.placements.map(({ blockId }) => model.block(blockId)),
    selection: model?.selection ?? [],
    tool,
    runPaint,
  };
}

function renderCanvas(fit = false) {
  canvas.render(canvasScene());
  if (fit && view === 'canvas') canvas.fit();
}

function updateListSelection() {
  const selected = new Set(model?.selection);
  for (const row of ui('list').querySelectorAll('[data-block-id]')) {
    const id = Number(row.dataset.blockId);
    row.querySelector('button').setAttribute('aria-pressed', String(selected.has(id)));
    const state = runPaint?.nodes[id]?.state;
    if (state) row.dataset.runState = state;
    else delete row.dataset.runState;
  }
}

function renderBlockList() {
  const list = ui('list');
  ui('canvas').hidden = view === 'list';
  list.hidden = view === 'canvas';
  ui('view-canvas').setAttribute('aria-pressed', view === 'canvas');
  ui('view-list').setAttribute('aria-pressed', view === 'list');
  list.replaceChildren();
  if (view !== 'list' || !model) return;
  const context = model.context;
  const rows = node('ul', 'graph-list-rows');
  for (const { id, blockId } of model.placementsOf(context.blockId)) {
    const block = model.block(blockId);
    const row = node('li', 'graph-list-row');
    row.dataset.blockId = blockId;
    const label = button('', (event) => pickBlock(blockId, event.shiftKey || event.metaKey || event.ctrlKey), 'graph-list-pick');
    label.append(node('span', 'graph-list-icon', KINDS[block.kind]?.icon ?? '◇'));
    const text = node('span', 'graph-list-text');
    text.append(node('strong', '', title(blockId)));
    if (block.description) text.append(node('small', 'muted', block.description));
    label.append(text);
    row.append(label, button(t('editor.files.open'), () => go({ ...context, path: [...context.path, id] })));
    rows.append(row);
  }
  if (rows.childElementCount) list.append(rows);
  else list.append(node('p', 'muted', t('editor.graph.scene_empty')));
  updateListSelection();
}

function renderTree() {
  const crumbs = ui('crumbs');
  const tree = ui('tree');
  crumbs.replaceChildren();
  tree.replaceChildren();
  if (!model) return;

  const path = model.crumbs();
  const currentKey = contextKey(model.context);
  for (const [index, crumb] of path.entries()) {
    if (index) crumbs.append(node('span', 'muted', '›'));
    const name = button(title(crumb.blockId), () => go(crumb), 'btn btn-quiet');
    if (contextKey(crumb) === currentKey) name.setAttribute('aria-current', 'location');
    crumbs.append(name);
  }

  function branch(context) {
    const { blockId } = context;
    const key = contextKey(context);
    const name = title(blockId);
    const kind = model.block(blockId)?.kind ?? 'block';
    const item = node('li');
    const row = node('div', 'graph-tree-row');
    const open = expanded.has(key) && model.hasScene(blockId);
    const toggle = button(open ? '▾' : '▸', () => toggleBranch(context), 'graph-tree-toggle');
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', t(open ? 'editor.graph.tree_collapse' : 'editor.graph.tree_expand', { name }));
    const link = button('', () => go(context), 'graph-tree-open');
    link.dataset.kind = kind;
    const icon = node('span', 'graph-tree-icon', KINDS[kind]?.icon);
    icon.setAttribute('aria-hidden', 'true');
    link.append(icon, node('span', 'graph-tree-name', name));
    link.title = `${name} · ${kinds[kind] ?? kind} · #${blockId}`;
    if (key === currentKey) link.setAttribute('aria-current', 'location');
    row.append(toggle, link);
    item.append(row);
    if (open) {
      const children = node('ul', 'graph-branch');
      for (const placement of model.childrenOf(blockId)) {
        children.append(branch({ startId: context.startId, path: [...context.path, placement.id], blockId: placement.blockId }));
      }
      if (!children.childElementCount) children.append(node('li', 'graph-tree-empty', t('editor.graph.tree_empty')));
      item.append(children);
    }
    return item;
  }

  const roots = node('ul', 'graph-branch');
  roots.append(branch({ startId: model.rootId, blockId: model.rootId, path: [] }));
  if (model.context.startId !== model.rootId) {
    roots.append(branch({ startId: model.context.startId, blockId: model.context.startId, path: [] }));
  }
  tree.append(roots);
}

function expandCurrent() {
  for (const crumb of model?.crumbs() ?? []) expanded.add(contextKey(crumb));
  if (model) expanded.add(contextKey({ startId: model.rootId, path: [] }));
}

function placeProjects() {
  const overview = !project;
  const projects = ui('projects');
  const host = ui(overview ? 'projects-overview' : 'projects-rail');
  if (projects.parentElement === host) return;
  host.append(projects);
  if (overview) projects.querySelector('.graph-projects-modes button:last-child')?.click();
}

function render(fit = false) {
  if (!model) view = 'canvas';
  const parentId = model?.context.blockId;
  const currentScene = model?.scene();
  runPanel.setScene(project, parentId, {
    legacyFolder: currentScene?.legacyFolder ?? null,
    runId: pinnedRun?.parentId === parentId ? pinnedRun?.id : null,
  });
  renderTree();
  renderBlockList();
  renderCanvas(fit);
  renderPanel();
  assistantPanel.refresh();
  const root = model?.block(model.rootId);
  const stamp = JSON.stringify([project, root?.id, root?.title, root?.appearance]);
  if (stamp !== projectsStamp) {
    projectsStamp = stamp;
    if (model || !project) void projectsPanel.refresh(project);
  }
  ui('project-start').hidden = !!project;
  ui('tree-heading').hidden = !project;
  placeProjects();
  syncControls();
}

async function readFresh(address) {
  for (let attempt = 0; ; attempt++) {
    try { return await readGraph(address); }
    catch (error) {
      if (attempt || error.code !== 'graph_revision') throw error;
    }
  }
}

async function refreshGraph(address) {
  const result = await readFresh(address);
  if (!result.model) return result;
  for (const key of expanded) {
    const [startId, ...path] = key.split('.').map(Number);
    try {
      await loadContext(result.model, address.project, { startId, path });
    } catch (error) {
      if (!['not_found', 'graph_revision'].includes(error.code)) throw error;
      if (error.code === 'graph_revision') break;
    }
  }
  return result;
}

function installGraph(result, address, selection = []) {
  model = result.model;
  legacyWithoutGraph = !model && !!result.hasLegacyFolders;
  rev = result.rev;
  ready = true;
  if (model) {
    if (!selection.length && address.legacyElement) {
      const target = model.scene().placements.find(({ blockId }) =>
        Number(model.block(blockId)?.properties.legacyNo) === address.legacyElement);
      if (target) selection = [target.blockId];
    }
    model.select(selection);
    expandCurrent();
    if (pinnedRun?.parentId !== model.context.blockId) pinnedRun = null;
    writeAddress(address.project, model.context, true, pinnedRun?.id);
  }
}

function loadAddress() {
  if (busy || pending) {
    addressChanged = true;
    return;
  }
  return perform(async () => {
    const address = readAddress();
    const before = project;
    const selection = before === address.project ? model?.selection : [];
    project = address.project;
    tool = 'select';
    if (before !== project) {
      expanded = new Set();
      legacyWithoutGraph = false;
      pinnedRun = null;
      model = null;
      rev = null;
      ready = false;
      render();
      dialog.close();
    }
    if (!project) {
      status();
      return;
    }
    status(t('editor.graph.loading'));
    let result = await refreshGraph(address);
    if (!result.model && result.hasLegacyFolders && !readOnly) {
      try {
        const imported = await post('graph.importLegacy', { project: address.project });
        if (imported.rootId) result = await refreshGraph(address);
      } catch (error) {
        if (addressChanged) return;
        installGraph(result, address);
        render();
        status(error.message || t('editor.looks.legacy_only'), loadAddress);
        return;
      }
    }
    if (addressChanged) return;
    pinnedRun = address.runId && result.model ? { id: address.runId, parentId: result.model.context.blockId } : null;
    installGraph(result, address, selection);
    render(true);
    if (pinnedRun) showPanel('run');
    status(model ? '' : legacyWithoutGraph ? t('editor.looks.legacy_only') : t('editor.graph.create_first'));
  });
}

function go(context) {
  if (pending || !model || contextKey(context) === contextKey(model.context)) return;
  return perform(async () => {
    const selection = model.selection;
    try {
      model.open(await loadContext(model, project, context));
    } catch (error) {
      if (error.code !== 'graph_revision') throw error;
      const result = await readFresh({ project, context });
      model = result.model;
      rev = result.rev;
      model?.select(selection);
    }
    if (addressChanged) return;
    tool = 'select';
    if (pinnedRun?.parentId !== model?.context.blockId) pinnedRun = null;
    if (model) writeAddress(project, model.context, false, pinnedRun?.id);
    expandCurrent();
    render(true);
    status();
  });
}

function toggleBranch(context) {
  if (busy || pending) return;
  const key = contextKey(context);
  if (expanded.has(key) && model.hasScene(context.blockId)) {
    expanded.delete(key);
    renderTree();
    return;
  }
  return perform(async () => {
    try {
      await loadContext(model, project, context);
    } catch (error) {
      if (error.code !== 'graph_revision') throw error;
      const selection = model.selection;
      const address = { project, context: model.context };
      installGraph(await readFresh(address), address, selection);
      render();
      if (model) await loadContext(model, project, context);
    }
    expanded.add(key);
    renderTree();
  });
}

function errorInDialog(message) {
  ui('dialog-error').textContent = message;
  ui('dialog-error').hidden = !message;
}

function sendPending() {
  return perform(async () => {
    const write = pending;
    status(t('editor.graph.saving'));
    let answer;
    try {
      answer = await write.submit();
    } catch (error) {
      if (error.ok !== false) {
        const message = t('editor.graph.retry_write');
        status(message, sendPending);
        if (dialog.open) errorInDialog(message);
        return;
      }
      pending = null;
      if (error.code === 'conflict' && !addressChanged) {
        installGraph(await refreshGraph(write.address), write.address, write.selection);
        render();
      } else {
        renderCanvas();
      }
      status(error.message);
      if (dialog.open) errorInDialog(error.message);
      return;
    }
    pending = null;
    dialog.close();
    if (addressChanged) return;

    try {
      const { address, selection, selectResult, openResult } = write;
      const nextSelection = selectResult === null ? selection : [answer.results[selectResult].id];
      if (openResult !== null) {
        const { parentId, placementId } = answer.results[openResult];
        const context = address.context ?? { startId: parentId, path: [] };
        address.context = { startId: context.startId, path: [...context.path, placementId] };
      }
      const result = await refreshGraph(address);
      if (addressChanged) return;
      if (openResult !== null && result.model) writeAddress(address.project, result.model.context);
      installGraph(result, address, nextSelection);
      render(!address.context || selectResult !== null || openResult !== null);
      status(t('editor.graph.saved'));
    } catch (error) {
      ready = false;
      status(t('editor.graph.saved_refresh_failed'), loadAddress);
    }
  });
}

function save(ops, selectResult = null, openResult = null) {
  return queueSave((extra) => () => batch(ops, extra), selectResult, openResult);
}

function queueSave(makeSubmit, selectResult = null, openResult = null) {
  if (readOnly || busy || pending || !ready) return;
  const random = crypto.getRandomValues(new Uint32Array(4)).join('-');
  const extra = { project, opId: 'graph-ui-' + random, ...(rev === null ? {} : { ifRev: rev }) };
  pending = {
    submit: makeSubmit(extra),
    address: { project, context: model?.context },
    selection: model?.selection ?? [],
    selectResult,
    openResult,
  };
  return sendPending();
}

function showDialog(heading, fields, submitText, action) {
  if (readOnly || busy || pending || dialog.open) return;
  ui('dialog-title').textContent = heading;
  ui('dialog-fields').replaceChildren(...fields);
  ui('dialog-submit').textContent = submitText;
  errorInDialog('');
  dialog.querySelector('form').onsubmit = (event) => {
    event.preventDefault();
    errorInDialog('');
    if (pending) return void sendPending();
    try { action(); } catch (error) { errorInDialog(error.message); }
  };
  dialog.showModal();
}

function newPlacement(at, parentId) {
  const slots = model?.placementsOf(parentId) ?? [];
  const x = slots.reduce((max, { placement }) =>
    Math.max(max, (placement?.x ?? 0) + (placement?.width ?? 180)), -30);
  return { ...(at ?? { x: x + 30, y: 30 }), width: 180, height: 94 };
}

function createBlock(at) {
  if (!project || !ready) return;
  const name = input('title');
  name.required = true;
  name.maxLength = 512;
  const kind = select('kind', {
    block: kinds.block, folder: kinds.folder, scheme: kinds.scheme, decision: kinds.decision, gateway: kinds.gateway, note: kinds.note,
  }, model?.block(model.context.blockId)?.kind === 'folder' ? 'scheme' : 'block');
  showDialog(t('editor.graph.new_block'), [field(t('editor.graph.kind_label'), kind), field(t('editor.asset.name'), name)], t('editor.graph.create'), () => {
    const parent = model?.context.blockId ?? 'root';
    const value = name.value.trim();
    if (!value) throw new Error(t('editor.graph.block_name_needed'));
    void save([
      { op: 'block.create', ref: 'child', kind: kind.value, title: value },
      { op: 'placement.create', parentId: parent, blockId: 'child', placement: newPlacement(at, parent) },
    ], 0);
  });
}

async function openCatalog() {
  if (readOnly || busy || pending || !ready) return;
  const key = project;
  const parent = model?.context.blockId ?? 'root';
  ui('catalog').disabled = true;
  try {
    const { categories, templates } = await get('catalog.get', { project: key });
    if (project !== key || parent !== (model?.context.blockId ?? 'root')) return;
    if (!templates.length) {
      status(t('editor.graph.catalog_empty'));
      return;
    }
    const picker = node('select');
    picker.name = 'template';
    const groups = new Map(categories.map((category) => [category.key, category.title]));
    for (const template of templates) {
      const category = groups.get(template.category);
      picker.add(new Option(category ? category + ' · ' + template.title : template.title, template.key));
    }
    const name = input('name');
    name.maxLength = 512;
    const description = node('p', 'muted dialog-hint');
    const update = () => {
      const template = templates[picker.selectedIndex];
      name.placeholder = template.title;
      description.textContent = template.about;
    };
    picker.onchange = update;
    update();
    showDialog(t('editor.settings.catalog'), [
      field(t('editor.graph.catalog_scheme'), picker), description, field(t('editor.graph.catalog_name'), name),
    ], t('editor.graph.catalog_add'), () => {
      void save([{ op: 'graph.template.apply', template: picker.value, parent,
        ...(name.value.trim() ? { name: name.value.trim() } : {}) }], null, 0);
    });
  } catch (error) {
    if (project === key) status(error.message, openCatalog);
  } finally {
    syncControls();
  }
}

function attachBlock(blockId) {
  const parent = input('parent');
  parent.type = 'number';
  parent.min = 1;
  parent.required = true;
  parent.setAttribute('list', 'graph-known-parents');
  const options = node('datalist');
  options.id = 'graph-known-parents';
  const known = new Set([model.rootId, model.context.startId]);
  const queue = [...known];
  for (const id of queue) {
    for (const placement of model.childrenOf(id)) {
      if (known.has(placement.blockId)) continue;
      known.add(placement.blockId);
      queue.push(placement.blockId);
    }
  }
  const existing = new Set(model.parentsOf(blockId).map(({ parentId }) => parentId));
  for (const id of known) {
    if (id === blockId || existing.has(id)) continue;
    options.append(new Option(title(id), id));
  }
  showDialog(t('editor.graph.add_parent'), [
    node('p', 'muted dialog-hint', t('editor.graph.parent_hint')),
    field(t('editor.graph.parent_id'), parent), options,
  ], t('editor.graph.add_placement'), () => {
    const parentId = parent.valueAsNumber;
    void save([{ op: 'placement.create', parentId, blockId, placement: newPlacement(null, parentId) }]);
  });
}

function editArrow({ id, sourceId, targetId, kind = 'link', direction = 'forward', properties = {} }) {
  const kindField = select('kind', { link: t('editor.graph.link'), flow: t('editor.graph.flow') }, kind);
  const directionField = select('direction', directions, direction);
  const key = input('branchKey', properties.branchKey ?? properties.branch ?? '');
  const label = input('branchLabel', properties.branchLabel ?? properties.label ?? '');
  const condition = textarea('branchCondition', properties.branchCondition ?? properties.condition ?? properties.cond ?? '');
  const defaultBranch = input('branchDefault');
  defaultBranch.type = 'checkbox';
  defaultBranch.checked = [true, 1, '1', 'true'].includes(properties.branchDefault ?? properties.default);
  const defaultField = node('label', 'graph-check');
  defaultField.append(defaultBranch, document.createTextNode(t('editor.graph.branch_default')));
  const branchFields = node('div');
  branchFields.append(field(t('editor.graph.branch_key'), key), field(t('editor.graph.branch_label'), label), field(t('editor.graph.branch_condition'), condition), defaultField);

  function syncBranch() {
    for (const option of directionField.options) {
      option.disabled = kindField.value === 'flow' && ['both', 'none'].includes(option.value);
    }
    if (directionField.selectedOptions[0]?.disabled) directionField.value = 'forward';
    const from = directionField.value === 'reverse' ? targetId : sourceId;
    branchFields.hidden = kindField.value !== 'flow' || model.block(from)?.kind !== 'decision';
    key.required = !branchFields.hidden;
  }
  kindField.onchange = directionField.onchange = syncBranch;
  syncBranch();
  showDialog(id ? t('editor.graph.arrow_settings') : t('editor.graph.new_arrow'), [
    node('p', 'dialog-hint muted', `${title(sourceId)} · ${title(targetId)}`),
    field(t('editor.graph.arrow_type'), kindField), field(t('editor.graph.arrow_direction'), directionField), branchFields,
  ], id ? t('common.save') : t('editor.graph.create_arrow'), () => {
    const changes = { kind: kindField.value, direction: directionField.value };
    if (!branchFields.hidden) {
      if (!key.value.trim()) throw new Error(t('editor.graph.branch_key_needed'));
      changes.properties = {
        ...properties, branchKey: key.value.trim(), branchLabel: label.value.trim(),
        branchCondition: condition.value.trim(), branchDefault: defaultBranch.checked,
      };
    }
    const op = id ? { op: 'arrow.update', id, ...changes } : { op: 'arrow.create', sourceId, targetId, ...changes };
    void save([op]);
  });
}

function jsonObject(control) {
  let value;
  try { value = JSON.parse(control.value); }
  catch { throw new Error(t('editor.graph.invalid_json', { field: control.dataset.label })); }
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(t('editor.graph.expected_object', { field: control.dataset.label }));
  }
  return value;
}

function renderPanel() {
  const turn = ++panelTurn;
  panelDirty = false;
  panel.replaceChildren();
  const idLabel = ui('block-id');
  idLabel.hidden = !model;
  if (!model) {
    ui('panel-title').textContent = t('editor.panel.props');
    return;
  }

  const context = model.context;
  const id = model.selection.at(-1) ?? context.blockId;
  const block = model.block(id);
  ui('panel-title').textContent = kinds[block.kind] ?? block.kind;
  idLabel.textContent = '#' + id;

  if (['asset', 'material'].includes(block.kind)) {
    const report = node('p', 'muted', t('editor.graph.properties_loading'));
    panel.append(report);
    void get('block.get', { project, id }).then(({ block: full }) => {
      if (turn !== panelTurn) return;
      if (full.rev !== block.rev) {
        report.textContent = t('editor.graph.block_changed');
        return;
      }
      report.remove();
      drawPanel(context, full);
    }).catch((error) => {
      if (turn !== panelTurn) return;
      report.textContent = error.message;
      panel.append(button(t('editor.graph.properties_reload'), renderPanel));
    });
    return;
  }
  drawPanel(context, block);
}

function drawPanel(context, block) {
  const { id } = block;
  const view = id === context.blockId;
  const form = node('form');
  const name = input('title', block.title);
  name.maxLength = 512;
  const description = textarea('description', block.description);
  const otherProperties = { ...block.properties };
  delete otherProperties.content;
  const properties = textarea('properties', JSON.stringify(otherProperties, null, 2));
  properties.dataset.label = t('editor.graph.properties_json');
  properties.classList.add('graph-json');
  const extra = node('details', 'section');
  extra.append(node('summary', '', t('editor.graph.properties_extra')), field(t('editor.graph.properties_json'), properties));
  const submit = node('button', 'btn btn-accent', t('editor.graph.save_card'));
  for (const control of [name, description, properties]) control.readOnly = readOnly;
  submit.hidden = readOnly;
  form.append(field(t('editor.asset.name'), name), field(t('editor.panel.description'), description), extra, submit);
  const markDirty = () => { panelDirty = true; };
  form.addEventListener('input', markDirty);
  form.onsubmit = (event) => {
    event.preventDefault();
    try {
      const changes = {};
      if (name.value !== block.title) changes.title = name.value;
      if (description.value !== block.description) changes.description = description.value;
      const props = jsonObject(properties);
      if (Object.hasOwn(props, 'content')) throw new Error(t('editor.graph.edit_content_hint'));
      if (JSON.stringify(props) !== JSON.stringify(otherProperties)) {
        changes.properties = props;
      }
      if (Object.keys(changes).length) void save([model.updateBlock(id, changes)]);
      else {
        panelDirty = false;
        status(t('editor.graph.already_saved'));
      }
    } catch (error) { status(error.message); }
  };
  panel.append(form);

  const contentPanel = blockContentEditor(block, {
    readOnly,
    onEdit: markDirty,
    onSave: (entries) => void save([{ op: 'block.content', id, content: entries }]),
  });
  contentPanel.open = expandedContent === project + ':' + id;
  contentPanel.addEventListener('toggle', () => {
    if (contentPanel.isConnected) expandedContent = contentPanel.open ? project + ':' + id : null;
  });
  panel.append(contentPanel);

  const style = node('details', 'section');
  style.append(node('summary', '', t('editor.graph.appearance')));
  const appearance = textarea('appearance', JSON.stringify(block.appearance, null, 2));
  appearance.dataset.label = t('editor.graph.appearance_json');
  appearance.classList.add('graph-json');
  appearance.readOnly = readOnly;
  const styleForm = node('form', 'section-body');
  styleForm.append(field(t('editor.graph.appearance_json'), appearance));
  if (!readOnly) styleForm.append(node('button', 'btn btn-accent', t('editor.graph.save_appearance')));
  styleForm.addEventListener('input', markDirty);
  styleForm.onsubmit = (event) => {
    event.preventDefault();
    try { void save([model.updateBlock(id, { appearance: jsonObject(appearance) })]); }
    catch (error) { status(error.message); }
  };
  style.append(styleForm);
  panel.append(style);

  const placementId = view ? context.path.at(-1) : model.placementsOf(context.blockId).find(({ blockId }) => blockId === id)?.id;
  const actions = node('div', 'graph-actions section');
  if (!view && placementId) {
    actions.append(button(t('editor.graph.open_block'), () => go({ ...context, path: [...context.path, placementId] })));
  }
  if (!readOnly && id !== model.rootId) actions.append(button(t('editor.graph.add_parent'), () => attachBlock(id)));
  if (!readOnly && placementId) actions.append(button(t('editor.graph.remove_placement'), () => {
    showDialog(t('editor.graph.remove_placement'), [
      node('p', '', t('editor.graph.remove_placement_confirm', {
        name: title(id), parent: title(model.placement(placementId).parentId),
      })),
    ], t('editor.dialog.text_drop'), () => void save([{ op: 'placement.delete', id: placementId }]));
  }, 'btn btn-danger'));
  if (actions.childElementCount) panel.append(actions);

  const arrows = model.arrowsOf(id).filter((arrow) => ['link', 'flow'].includes(arrow.kind)
    && (arrow.kind === 'flow' || !arrow.properties.relation));
  if (arrows.length) {
    const section = node('section', 'section graph-edges');
    section.append(node('h3', 'panel-title', t('editor.graph.arrows')));
    for (const arrow of arrows) {
      const caption = arrow.properties.branchLabel || arrow.properties.branchKey || arrow.properties.label || arrow.properties.branch;
      const other = arrow.sourceId === id ? arrow.targetId : arrow.sourceId;
      const type = arrow.kind === 'flow' ? t('editor.graph.transition') : t('editor.graph.link');
      const edit = button(`${type} · ${title(other)}${caption ? ' · ' + caption : ''}`, () => editArrow(arrow));
      edit.disabled = readOnly;
      edit.title = edit.textContent;
      section.append(edit);
    }
    panel.append(section);
  }

  const materials = node('details', 'section');
  materials.append(node('summary', '', t('editor.mobile.tab_assets')));
  materials.open = expandedMaterials === project + ':' + id;
  materials.addEventListener('toggle', () => {
    if (!materials.isConnected) return;
    expandedMaterials = materials.open ? project + ':' + id : null;
    if (materials.open) void showMaterials(id, materials);
  });
  panel.append(materials);
}

function materialFile(material, key) {
  const file = node('a', 'btn', t('editor.graph.open_file'));
  const id = material.materialId ? { material: material.materialId } : { asset: material.legacyAssetId };
  file.href = 'api.php?' + new URLSearchParams({ op: 'graph.asset.file', project: key, ...id });
  file.target = '_blank';
  file.rel = 'noopener';
  return file;
}

function materialText(material, key) {
  const details = node('details');
  const text = node('pre', 'graph-material-text');
  details.append(node('summary', '', t('editor.graph.read')), text);
  if (typeof material.text === 'string') text.textContent = material.text;
  else if (material.materialId) details.addEventListener('toggle', async () => {
    if (!details.open || text.textContent) return;
    text.textContent = t('editor.graph.text_loading');
    try {
      const { block } = await get('block.get', { project: key, id: material.materialId });
      text.textContent = block.properties.body ?? '';
    } catch (error) {
      text.textContent = error.message;
    }
  });
  return details;
}

async function showMaterials(block, section) {
  const key = project;
  const body = node('div', 'section-body graph-materials');
  body.append(node('p', 'muted', t('editor.graph.materials_loading')));
  section.querySelector('.graph-materials')?.remove();
  section.append(body);
  try {
    const { assets } = await get('graph.asset.list', { project: key, block });
    if (!body.isConnected) return;
    body.replaceChildren();
    if (!assets.length) body.append(node('p', 'muted', t('editor.graph.materials_empty')));
    for (const material of assets) {
      const item = node('div', 'graph-material');
      item.append(node('strong', '', material.title || material.name || t('editor.graph.kind.material')));
      if (material.file) item.append(materialFile(material, key));
      if (material.text !== undefined) item.append(materialText(material, key));
      if (material.url && /^https?:\/\//i.test(material.url)) {
        const link = node('a', 'btn', t('editor.graph.open_link'));
        link.href = material.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        item.append(link);
      }
      if (material.missing) item.append(node('span', 'muted', t('editor.graph.file_missing')));
      body.append(item);
    }
    if (!readOnly) {
      const actions = node('div', 'graph-actions');
      actions.append(
        button(t('editor.graph.add_text'), () => addMaterialText(block)),
        button(t('editor.graph.upload_image'), () => uploadMaterialImage(block)),
      );
      body.append(actions);
    }
  } catch (error) {
    body.replaceChildren(node('p', 'muted', error.message), button(t('editor.newscheme.retry'), () => showMaterials(block, section)));
  }
}

function addMaterialText(block) {
  const name = input('title');
  const text = textarea('text', '');
  name.required = text.required = true;
  name.maxLength = 512;
  showDialog(t('editor.graph.text_material'), [field(t('editor.graph.material_title'), name), field(t('editor.graph.material_text'), text)], t('editor.graph.add'), () => {
    if (!name.value.trim() || !text.value.trim()) throw new Error(t('editor.graph.material_required'));
    void save([{ op: 'graph.asset.add', block, title: name.value.trim(), text: text.value }]);
  });
}

function uploadMaterialImage(block) {
  const file = input('image');
  file.type = 'file';
  file.required = true;
  file.accept = 'image/png,image/jpeg,image/gif,image/webp,image/bmp,image/avif';
  showDialog(t('editor.graph.image_upload'), [field(t('editor.graph.image_file'), file)], t('editor.graph.upload'), () => {
    const image = file.files[0];
    if (!image) return;
    void queueSave((extra) => {
      const form = new FormData();
      form.append('payload', JSON.stringify({ ...extra, block }));
      form.append('file', image);
      return async () => {
        const response = await fetch('api.php?op=graph.asset.upload', { method: 'POST', body: form });
        const answer = await response.json();
        if (!answer.ok) throw Object.assign(new Error(answer.error), answer);
        return answer;
      };
    });
  });
}

ui('create').onclick = () => createBlock();
ui('catalog').onclick = () => void openCatalog();
ui('connect').onclick = () => {
  tool = tool === 'connect' ? 'select' : 'connect';
  renderCanvas();
  syncControls();
  status(tool === 'connect' ? t('editor.graph.pick_arrow_blocks') : '');
};
ui('fit').onclick = () => canvas.fit();
const saveDialog = ui('save-dialog');
const saveForm = ui('save-form');
ui('save').onclick = () => saveDialog.showModal();
ui('save-close').onclick = () => saveDialog.close();
saveForm.elements.namedItem('style').onchange = (event) => {
  ui('save-pdf').textContent = event.target.value === 'flat' ? t('editor.files.format_pdf_vector') : 'PDF';
};
saveForm.onsubmit = async (event) => {
  event.preventDefault();
  const format = event.submitter?.value ?? 'html';
  const style = new FormData(event.currentTarget).get('style');
  const scene = canvasScene();
  const name = title(scene.parentId);
  saveDialog.close();
  try {
    const { saveGraphPicture } = await import('goblin/graph/export.js');
    await saveGraphPicture({ format, style, title: name, scene });
  } catch (error) {
    status(error.message || t('editor.files.save_failed'));
  }
};
function showCanvas() {
  const switched = view !== 'canvas';
  view = 'canvas';
  renderBlockList();
  renderCanvas(switched);
  syncControls();
}
ui('view-canvas').onclick = showCanvas;
ui('view-list').onclick = () => {
  view = 'list';
  tool = 'select';
  renderBlockList();
  renderCanvas();
  syncControls();
  status();
};
function showPanel(name) {
  const wasRun = !ui('run').hidden;
  document.body.dataset.graphPanel = name;
  for (const tab of ['properties', 'run', 'assistant']) ui(tab + '-tab').setAttribute('aria-pressed', tab === name);
  ui('run').hidden = name !== 'run';
  ui('assistant').hidden = name !== 'assistant';
  ui('panel-head').hidden = panel.hidden = name !== 'properties';
  const runOpen = name === 'run';
  ui('run-toggle').setAttribute('aria-expanded', runOpen);
  ui('run-toggle-icon').textContent = runOpen ? '←' : '▶';
  ui('run-toggle-label').textContent = t(runOpen ? 'editor.variants.canvas' : 'editor.variants.run');
  if (runOpen) runPanel.refresh();
  if (name === 'assistant') assistantPanel.activate();
  if ((runOpen || wasRun) && document.body.dataset.layout === 'mobile') document.body.scrollTop = 0;
}
ui('run-tab').onclick = () => showPanel('run');
ui('run-toggle').onclick = () => {
  if (ui('run').hidden) showPanel('run');
  else {
    showPanel('properties');
    showCanvas();
  }
};
ui('properties-tab').onclick = () => showPanel('properties');
ui('assistant-tab').hidden = readOnly;
ui('assistant-tab').onclick = () => showPanel('assistant');
ui('retry').onclick = () => retry?.();
function applyLayout() {
  document.body.dataset.layout = layoutControl.value === 'auto'
    ? (compactLayout.matches ? 'mobile' : 'desktop') : layoutControl.value;
}
try {
  const savedLayout = localStorage.getItem(layoutKey);
  if (savedLayout === 'mobile' || savedLayout === 'desktop') layoutControl.value = savedLayout;
} catch {}
layoutControl.onchange = () => {
  try { localStorage.setItem(layoutKey, layoutControl.value); } catch {}
  applyLayout();
};
compactLayout.addEventListener('change', applyLayout);
applyLayout();
skinSelect.onchange = () => {
  const skin = skinSelect.value;
  skinSelect.value = 'graph';
  if (!Object.hasOwn(LOOKS, skin)) return;
  if (busy || pending || panelDirty || dialog.open) {
    status(t('editor.looks.saving_before_switch'));
    return;
  }
  const visual = new URL('index.php', location.href);
  visual.search = location.search;
  visual.searchParams.set('skin', skin);
  visual.hash = location.hash;
  try { localStorage.setItem(GRAPH_SKIN_STORE, skin); } catch {}
  location.assign(visual);
};
ui('dialog-cancel').onclick = () => dialog.close();
dialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });
ui('project-form').onsubmit = (event) => {
  event.preventDefault();
  const key = new FormData(event.currentTarget).get('project').trim();
  if (!key) return;
  writeAddress(key, null);
  void loadAddress();
};
window.addEventListener('hashchange', () => void loadAddress());
render();
void loadAddress();

setInterval(async () => {
  if (!project || !ready || busy || pending || polling || panelDirty || dialog.open || document.hidden) return;
  const key = project;
  const known = rev;
  polling = true;
  try {
    const answer = await quietGet('graph.rev', { project: key });
    if (!answer || answer.rev <= known || busy || pending || panelDirty || dialog.open || key !== project || known !== rev) return;
    await perform(async () => {
      const address = { project, context: model?.context };
      const selection = model?.selection ?? [];
      const result = await refreshGraph(address);
      if (addressChanged) return;
      const pathChanged = result.model && (!address.context || contextKey(result.model.context) !== contextKey(address.context));
      installGraph(result, address, selection);
      render(pathChanged);
      status(t('editor.graph.refreshed'));
    });
  } finally {
    polling = false;
  }
}, 2000);
