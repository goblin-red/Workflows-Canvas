import { pickSides, portPoint } from 'goblin/canvas/geometry-legacy.js';
import { route, pathOf } from 'goblin/canvas/routing.js';
import { edgeColor, inkFor, paintOf } from 'goblin/canvas/palette.js';
import { SKINS } from 'goblin/canvas/looks.js';
import { arrowHeads } from 'goblin/graph/model.js';
import { t } from 'goblin/core/i18n.js';
import { STEP_WORDS, stepMark } from 'goblin/core/kinds.js';

const SVG = 'http://www.w3.org/2000/svg';
const SIDES = ['t', 'r', 'b', 'l'];
const VIEW_MARGIN = 96;
const ROUTE_MARGIN = 120;
const LABEL_SIZE = 11;
const EMPTY_PAINT = Object.freeze({});
const EDGE_PAINT_STATES = new Set(['lit', 'done', 'live']);
const CONTAINERS = new Set(['project', 'folder', 'scheme', 'group']);
const CONTAINER_SIZES = new Map([['project', [300, 150]], ['folder', [260, 135]], ['scheme', [230, 125]]]);
const MATERIALS = new Set(['asset', 'material']);
const RASTER_MIME = /^image\/(?:png|jpeg|gif|webp|bmp|avif)$/i;
const STARTER_LABEL = t('editor.panel.starter_caps');
const CONTENT_LIMIT = 6;
const EMPTY_SUMMARY = Object.freeze({
  count: '', type: '', rows: [], material: '', materialNames: [], cover: false, filename: '',
});
const CARD_WORDS = {project: 'Project', folder: 'Folder', scheme: 'Scheme', items: 'Items', materials: 'Materials',
      data: 'Data', image: 'Image', file: 'File', material: 'Material', cover: 'Cover'};
const WAIT_LABELS = {
  pause: 'editor.canvas.wait_pause', decision: 'editor.canvas.wait_decision',
  pass: 'editor.canvas.wait_gateway', person: 'editor.canvas.wait_person',
};
let markerSerial = 0;

const svg = (tag, attributes = {}) => {
  const node = document.createElementNS(SVG, tag);
  for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
  return node;
};
const div = className => Object.assign(document.createElement('div'), {className});
const bounded = (value, min, max) => Math.min(max, Math.max(min, value));
function finite(value, fallback) {
  if (typeof value === 'string' && value.trim()) value = Number(value);
  return Number.isFinite(value) ? value : fallback;
}
const text = value => typeof value === 'string' ? value : '';
const enlarge = (box, margin) => ({l: box.l - margin, t: box.t - margin, r: box.r + margin, b: box.b + margin});
const intersects = (a, b) => a.l <= b.r && a.r >= b.l && a.t <= b.b && a.b >= b.t;
const boxOf = ({x, y, width, height}) => ({l: x, t: y, r: x + width, b: y + height});
const union = (a, b) => ({l: Math.min(a.l, b.l), t: Math.min(a.t, b.t), r: Math.max(a.r, b.r), b: Math.max(a.b, b.b)});
const frameKind = kind => kind === 'group' || kind === 'area';
const sameRect = (a, b) => a && a.x === b.x && a.y === b.y && a.width === b.width && a.height === b.height;
const interactive = 'button, input, select, textarea, a, [contenteditable]:not([contenteditable="false"])';

function summaryText(value) {
  return text(value).slice(0, 160).replace(/\s+/gu, ' ').trim();
}

function materialType(block) {
  const {kind, mime} = block?.properties ?? {};
  if (kind === 'image' || text(mime).startsWith('image/')) return CARD_WORDS.image;
  if (kind === 'text' || text(mime).startsWith('text/markdown')) return 'Markdown';
  return kind === 'file' || mime ? CARD_WORDS.file : CARD_WORDS.material;
}

function imageMaterial(block) {
  if (!MATERIALS.has(block?.kind)) return false;
  const {mime, kind} = block.properties ?? {};
  return mime ? RASTER_MIME.test(mime) : kind === 'image';
}

function cardSummary(block, preview, linkedMaterials) {
  const content = block.properties?.content;
  const materialCard = MATERIALS.has(block.kind);
  const type = materialCard ? materialType(block)
    : ['project', 'folder', 'scheme'].includes(block.kind) ? CARD_WORDS[block.kind] : '';
  if (!type && !preview && !linkedMaterials && !content?.length) return EMPTY_SUMMARY;
  const entries = Array.isArray(preview?.items) ? preview.items : [];
  const container = CONTAINERS.has(block.kind);
  const total = container ? Math.max(0, Math.trunc(finite(preview?.total, entries.length))) : 0;
  const rows = [];
  if (container) {
    // Сначала схемы, затем папки. Внутри каждого вида сохраняем порядок выдачи.
    for (const kind of ['scheme', 'folder']) {
      for (const item of entries) {
        if (rows.length >= CONTENT_LIMIT) break;
        if (item?.kind !== kind) continue;
        const title = summaryText(item.title);
        if (title) rows.push({kind, title});
      }
    }
  } else if (!MATERIALS.has(block.kind) && block.kind !== 'decision') {
    for (const field of Array.isArray(content) ? content : []) {
      if (rows.length >= CONTENT_LIMIT) break;
      if (!field || !['heading', 'text', 'markdown', 'data'].includes(field.kind)) continue;
      const label = summaryText(field.label);
      const value = summaryText(field.value);
      const title = field.kind === 'data' ? [CARD_WORDS.data, label].filter(Boolean).join(' · ')
        : [label, value].filter(Boolean).join(' · ');
      if (title) rows.push({kind: field.kind, title});
    }
  }
  const materials = new Map(linkedMaterials);
  for (const item of entries) {
    if (MATERIALS.has(item?.kind) && !materials.has(item.id)) {
      materials.set(item.id, {title: summaryText(item.title)});
    }
  }
  const linked = [...materials.values()];
  const filename = materialCard ? summaryText(block.properties?.originalName) : '';
  return {
    count: container && preview && (block.kind !== 'scheme' || total > 0) ? `${CARD_WORDS.items} · ${total}` : '',
    type,
    rows,
    material: linked.length ? `${CARD_WORDS.materials} · ${linked.length}` : '',
    materialNames: linked.slice(0, CONTENT_LIMIT).map(item => item.title).filter(Boolean),
    cover: linked.some(item => item.cover),
    filename: block.title && summaryText(block.title) !== filename ? filename : '',
  };
}

function edgeRunLook(runPaint, id) {
  const entry = runPaint?.edges?.[id] ?? EMPTY_PAINT;
  const active = !runPaint?.state || runPaint.state === 'running';
  const state = entry.pulse && entry.state === 'lit' && active ? 'live'
    : EDGE_PAINT_STATES.has(entry.state) ? entry.state : '';
  const count = state === 'live' ? entry.pass ?? entry.passed : entry.passed ?? entry.pass;
  return {state, chosen: entry.chosen === true, passes: state ? Math.max(0, finite(count, 0)) : 0};
}

function geometry(placement, index, kind) {
  const {x, y, width, height} = placement ?? {};
  const [defaultWidth, defaultHeight] = CONTAINER_SIZES.get(kind) ?? (frameKind(kind) ? [280, 160] : [220, 100]);
  return {
    x: finite(x, (index % 5) * 300),
    y: finite(y, Math.floor(index / 5) * 180),
    width: Math.max(1, finite(width, defaultWidth)),
    height: Math.max(1, finite(height, defaultHeight)),
  };
}

function lineBounds(a, b) {
  return {l: Math.min(a.x, b.x), t: Math.min(a.y, b.y), r: Math.max(a.x, b.x), b: Math.max(a.y, b.y)};
}

function lineMiddle(points) {
  const lengths = points.slice(1).map((p, i) => Math.hypot(p.x - points[i].x, p.y - points[i].y));
  let rest = lengths.reduce((total, length) => total + length, 0) / 2;
  for (let i = 0; i < lengths.length; i++) {
    if (rest <= lengths[i]) {
      const part = lengths[i] ? rest / lengths[i] : 0;
      const a = points[i], b = points[i + 1];
      return {x: a.x + (b.x - a.x) * part, y: a.y + (b.y - a.y) * part};
    }
    rest -= lengths[i];
  }
  return points[0];
}

function routeEdge(edge, cards, preview, snapshot) {
  const from = {type: edge.from.kind, style: edge.from.rect};
  const to = {type: edge.to.kind, style: edge.to.rect};
  const influence = enlarge(union(boxOf(from.style), boxOf(to.style)), ROUTE_MARGIN + Math.abs(edge.lane) * 3);
  const sides = pickSides(from, to);
  const manual = [edge.appearance.fromSide, edge.appearance.toSide];
  manual.forEach((side, index) => { if (SIDES.includes(side)) sides[index] = side; });
  const shifts = [edge.lane, edge.lane];
  let points;
  if (edge.from === edge.to) {
    const a = portPoint(from, 'r', edge.lane), b = portPoint(from, 't', edge.lane);
    const reach = 38 + Math.abs(edge.lane);
    points = [a, {x: a.x + reach, y: a.y}, {x: a.x + reach, y: b.y - reach}, {x: b.x, y: b.y - reach}, b];
  } else if (preview) {
    const a = portPoint(from, sides[0], shifts[0]), b = portPoint(to, sides[1], shifts[1]);
    points = [a, b];
  } else {
    const obstacles = [];
    for (const card of cards.values()) {
      if (card !== edge.from && card !== edge.to && !frameKind(card.kind) && intersects(boxOf(card.rect), influence)) {
        const {x, y, width: w, height: h} = card.rect;
        obstacles.push({x, y, w, h});
      }
    }
    const arrow = {branch: edge.branch, style: {fromSide: manual[0], toSide: manual[1]}};
    const options = {obstacles, shifts, simple: edge.appearance.route === 'line-simple'};
    // Пробуем очевидные порты первыми. При преграде проверяем и остальные стороны.
    const direct = route(from, to, arrow, {...options, sides});
    const planned = direct.points.length ? direct : route(from, to, arrow, options);
    points = planned.points.length ? planned.points
      : [portPoint(from, sides[0], shifts[0]), portPoint(to, sides[1], shifts[1])];
  }
  const segments = points.slice(1).map((p, i) => lineBounds(points[i], p));
  const bounds = segments.reduce(union);
  return {influence: union(influence, enlarge(bounds, 22)), segments, bounds, points: snapshot ? points : null,
    label: lineMiddle(points), d: pathOf(points)};
}

function wrapLabel(value) {
  const rows = [];
  let line = '';
  const words = value.trim().slice(0, 256).split(/\s+/);
  for (const word of words) {
    if (line && line.length + 1 + word.length > 22) {
      rows.push(line);
      if (rows.length === 3) {
        rows[2] += '…';
        return rows;
      }
      line = '';
    }
    const letters = Array.from(word);
    line += (line ? ' ' : '') + (letters.length > 22 ? letters.slice(0, 21).join('') + '…' : word);
  }
  if (line) rows.push(line);
  if (value.trim().length > 256 && rows.length) rows[rows.length - 1] += '…';
  return rows;
}

export function mountGraphCanvas(host, handlers = {}) {
  const staticHost = getComputedStyle(host).position === 'static';
  if (staticHost) host.classList.add('graph-canvas-host');
  const viewport = div('graph-canvas');
  const world = div('graph-canvas-world');
  const frames = div('graph-canvas-frames');
  const links = svg('svg', {class: 'graph-canvas-links', 'aria-hidden': 'true'});
  const nodes = div('graph-canvas-nodes');
  const marker = `graph-canvas-head-${++markerSerial}`;
  const defs = svg('defs');
  const head = svg('marker', {id: marker, viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 7, markerHeight: 7, orient: 'auto-start-reverse'});
  head.append(svg('path', {d: 'M0,0 L10,5 L0,10 z', fill: 'context-stroke'}));
  defs.append(head);
  links.append(defs);
  world.append(frames, links, nodes);
  viewport.append(world);
  host.append(viewport);

  const controller = new AbortController();
  const listen = (name, callback, options) => viewport.addEventListener(name, callback, {...options, signal: controller.signal});
  const cards = new Map(), edges = new Map(), incident = new Map();
  const cardViews = new Map(), edgeViews = new Map();
  const dirtyRoutes = new Set(), dirtyBoxes = [];
  let parent, runPaint = null, selection = new Set(), tool = 'select', connectSource = null;
  let camera = {x: 32, y: 24, zoom: 1}, cameraDirty = true, edgesDirty = true, lastZoom;
  let renderFrame = null, gesture = null, pending = null, skipClick = false;
  let skinRules, skinVersion = 0, arrowTitles = true;
  const touches = new Map();
  let pinch = null, multiTouch = false;
  let size = {width: viewport.clientWidth, height: viewport.clientHeight};

  const observer = new ResizeObserver(([{contentRect}]) => {
    size = {width: contentRect.width, height: contentRect.height};
    edgesDirty = true;
    schedule();
  });
  observer.observe(viewport);
  setSkin('graph');

  function schedule() {
    if (renderFrame === null && !controller.signal.aborted) renderFrame = requestAnimationFrame(draw);
  }

  function setSkin(id) {
    const skin = Object.hasOwn(SKINS, id) ? id : 'graph';
    if (viewport.dataset.skin === skin) return;
    const {show, uppercase} = SKINS[skin];
    viewport.dataset.skin = skin;
    viewport.dataset.skinHide = Object.keys(show ?? {}).filter(key => show[key] === false).join(' ');
    viewport.classList.toggle('graph-skin-uppercase', Boolean(uppercase));
    skinRules = show;
    skinVersion++;
    const nextArrowTitles = show?.arrowTitle !== false;
    if (arrowTitles !== nextArrowTitles) edgesDirty = true;
    arrowTitles = nextArrowTitles;
    schedule();
  }

  function invalidate(card) {
    if (!frameKind(card.kind)) dirtyBoxes.push(boxOf(card.rect));
    for (const id of incident.get(card.id) ?? []) dirtyRoutes.add(id);
  }

  function render({parentId, project, blocks, placements, arrows, selection: nextSelection, tool: nextTool, runPaint: nextPaint = null, previews = {}}) {
    skinVersion++;
    // Снимок покраски может прийти до подтверждения записи позиции.
    const paintChanged = parent === parentId && nextPaint !== runPaint;
    runPaint = nextPaint;
    if (parent !== parentId) {
      stopGesture(false);
      cards.clear();
      edges.clear();
      edgesDirty = true;
      dirtyBoxes.length = 0;
      dirtyRoutes.clear();
      connectSource = null;
      parent = parentId;
    }
    if (tool !== nextTool) {
      stopGesture(false);
      connectSource = null;
    }
    tool = nextTool;
    selection = new Set(nextSelection);
    const byBlock = new Map(blocks.map(block => [block.id, block]));
    const attachments = new Map(), agents = new Map();
    for (const row of arrows) {
      if (row.kind !== 'link') continue;
      const target = byBlock.get(row.targetId);
      if (row.properties?.relation === 'agent') agents.set(row.sourceId, summaryText(target?.title));
      if (row.properties?.relation !== 'asset') continue;
      if (!attachments.has(row.sourceId)) attachments.set(row.sourceId, new Map());
      const items = attachments.get(row.sourceId);
      const linked = items.get(row.targetId);
      items.set(row.targetId, {
        title: summaryText(target?.title ?? target?.properties?.originalName),
        cover: row.properties.role === 'cover' || Boolean(linked?.cover),
        spec: row.properties.role === 'spec' || Boolean(linked?.spec),
        image: imageMaterial(target),
      });
    }
    const active = new Map();
    const retained = new Set();

    placements.forEach(({id, blockId, placement}, index) => {
      const block = byBlock.get(blockId);
      if (!block) return;
      retained.add(id);
      let card = cards.get(id);
      if (!card) {
        card = {id, blockId};
        cards.set(id, card);
      }
      const rect = geometry(placement, index, block.kind);
      const kindChanged = card.kind !== block.kind;
      const geometryChanged = !sameRect(card.base, rect);
      if (card.rect && (kindChanged || geometryChanged)) invalidate(card);
      card.blockId = blockId;
      card.kind = block.kind;
      card.title = block.title || (MATERIALS.has(block.kind) ? summaryText(block.properties?.originalName) : '');
      card.description = MATERIALS.has(block.kind) ? '' : block.description ?? '';
      const related = attachments.get(blockId);
      card.block = block;
      card.starter = card.kind === 'block' && (block.properties?.start === true || block.properties?.legacyProps?.start === true);
      card.project = project;
      card.agent = agents.get(blockId) ?? '';
      card.attachments = related;
      const summary = cardSummary(block, previews?.[blockId], related);
      const summaryKey = summary === EMPTY_SUMMARY ? '' : JSON.stringify(summary);
      if (card.summaryKey !== summaryKey) {
        card.summary = summary;
        card.summaryKey = summaryKey;
      }
      card.color = placement?.appearance?.color ?? block.appearance?.color ?? '';
      card.base = rect;
      if (geometryChanged || kindChanged || (!paintChanged && gesture?.card !== card && !sameRect(card.rect, rect))) {
        if (card.rect && !kindChanged && !geometryChanged) invalidate(card);
        card.rect = rect;
        invalidate(card);
      }
      active.set(blockId, card);
    });
    for (const [id, card] of cards) {
      if (retained.has(id)) continue;
      invalidate(card);
      cards.delete(id);
      if (gesture?.card === card) stopGesture(false);
    }
    if (!active.has(connectSource)) connectSource = null;

    const families = new Map(), retainedEdges = new Set();
    incident.clear();
    for (const row of arrows) {
      if (row.kind === 'contains' || (row.kind === 'link' && ['asset', 'agent'].includes(row.properties?.relation))) continue;
      const from = active.get(row.sourceId), to = active.get(row.targetId);
      if (!from || !to) continue;
      retainedEdges.add(row.id);
      const appearance = row.appearance ?? {};
      const branch = text(row.properties?.branchKey ?? row.properties?.branch);
      const key = [row.sourceId, row.targetId].sort((a, b) => a - b).join(':');
      const family = families.get(key) ?? [];
      families.set(key, family);
      const edge = edges.get(row.id) ?? {id: row.id};
      if (edge.from !== from || edge.to !== to || edge.branch !== branch ||
          edge.appearance?.fromSide !== appearance.fromSide || edge.appearance?.toSide !== appearance.toSide ||
          edge.appearance?.route !== appearance.route) dirtyRoutes.add(row.id);
      const branchLabel = branch === 'yes' || branch === 'no' ? t(`editor.canvas.${branch}_caps`) : branch === 'flow' ? '' : branch;
      const label = text(row.properties?.branchLabel || row.properties?.label || row.properties?.title) || branchLabel;
      const back = row.properties?.back;
      const look = edgeRunLook(runPaint, row.id);
      if (edge.label !== label || edge.branch !== branch || edge.direction !== row.direction || edge.back !== back ||
          edge.appearance?.color !== appearance.color || edge.appearance?.width !== appearance.width ||
          edge.appearance?.dash !== appearance.dash || edge.appearance?.back !== appearance.back ||
          edge.look?.state !== look.state || edge.look?.chosen !== look.chosen || edge.look?.passes !== look.passes) {
        edge.visualVersion = (edge.visualVersion ?? 0) + 1;
        edgesDirty = true;
      }
      if (edge.label !== label) {
        edge.label = label;
        edge.labelRows = wrapLabel(label);
        edge.labelWidth = Math.max(0, ...edge.labelRows.map(row => row.length * LABEL_SIZE));
      }
      Object.assign(edge, {from, to, appearance, direction: row.direction, branch, branchLabel, back, look});
      edges.set(row.id, edge);
      family.push(edge);
      for (const id of [from.id, to.id]) {
        if (!incident.has(id)) incident.set(id, new Set());
        incident.get(id).add(row.id);
      }
    }
    for (const family of families.values()) {
      family.sort((a, b) => a.id - b.id);
      family.forEach((edge, index) => {
        const lane = (index - (family.length - 1) / 2) * 14;
        if (edge.lane !== lane) dirtyRoutes.add(edge.id);
        edge.lane = lane;
      });
    }
    for (const id of edges.keys()) {
      if (retainedEdges.has(id)) continue;
      edges.delete(id);
      edgesDirty = true;
    }
    schedule();
  }

  function draw() {
    renderFrame = null;
    if (pending && gesture?.moved) {
      if (gesture.card) {
        const {card, x, y} = gesture;
        const rect = {...card.rect, x: x + pending.x / camera.zoom, y: y + pending.y / camera.zoom};
        if (!sameRect(card.rect, rect)) {
          card.rect = rect;
          for (const id of incident.get(card.id) ?? []) dirtyRoutes.add(id);
        }
      } else {
        camera = {...camera, x: gesture.x + pending.x, y: gesture.y + pending.y};
        cameraDirty = true;
      }
    }
    const preview = gesture?.moved ? gesture.card : null;
    if (dirtyBoxes.length) {
      for (const edge of edges.values()) {
        if (edge.route && dirtyBoxes.some(box => intersects(box, edge.route.influence))) dirtyRoutes.add(edge.id);
      }
      dirtyBoxes.length = 0;
    }
    const showEdges = edgesDirty || cameraDirty || dirtyRoutes.size > 0;
    for (const id of dirtyRoutes) {
      const edge = edges.get(id);
      if (edge) edge.route = routeEdge(edge, cards, preview === edge.from || preview === edge.to, handlers.snapshot);
    }
    dirtyRoutes.clear();

    if (cameraDirty) {
      world.style.transform = `translate(${camera.x}px, ${camera.y}px) scale(${camera.zoom})`;
      viewport.style.setProperty('--graph-grid-x', `${camera.x}px`);
      viewport.style.setProperty('--graph-grid-y', `${camera.y}px`);
      if (lastZoom !== camera.zoom) {
        viewport.style.setProperty('--graph-grid-step', `${16 * camera.zoom}px`);
        viewport.style.setProperty('--graph-label-scale', 1 / camera.zoom);
        viewport.style.setProperty('--graph-overview-scale', Math.max(1, .65 / camera.zoom));
        viewport.classList.toggle('graph-canvas-overview', camera.zoom < .5);
        viewport.classList.toggle('graph-canvas-far', camera.zoom < .25);
        viewport.classList.toggle('graph-canvas-near', camera.zoom >= 1.25);
        lastZoom = camera.zoom;
      }
      cameraDirty = false;
    }
    const runState = runPaint ? (runPaint.state || 'running') : '';
    if (viewport.dataset.runState !== runState) viewport.dataset.runState = runState;
    viewport.classList.toggle('graph-canvas-connect', tool === 'connect');
    viewport.classList.toggle('graph-canvas-panning', Boolean(pinch || (gesture && !gesture.card)));
    const view = {
      l: -camera.x / camera.zoom, t: -camera.y / camera.zoom,
      r: (size.width - camera.x) / camera.zoom,
      b: (size.height - camera.y) / camera.zoom,
    };
    const buffer = enlarge(view, VIEW_MARGIN / camera.zoom);

    for (const [id, card] of cards) {
      const mounted = cardViews.get(id)?.node;
      if (intersects(boxOf(card.rect), buffer) || gesture?.card === card || mounted?.contains(document.activeElement)) {
        updateCard(card);
      } else if (mounted) {
        mounted.remove();
        cardViews.delete(id);
      }
    }
    removeMissing(cardViews, cards);
    if (showEdges) {
      drawEdges(view, buffer);
      edgesDirty = false;
    }
    if (parent !== undefined && viewport.dataset.parentId !== String(parent)) viewport.dataset.parentId = parent;
  }

  function drawEdges(view, buffer) {
    for (const [id, edge] of edges) {
      const path = edge.route;
      const width = bounded(finite(edge.appearance.width, 2), .5, 16);
      const pathArea = enlarge(buffer, 16 + width * 7);
      const label = path.label;
      const labelWidth = (edge.labelWidth / 2 + VIEW_MARGIN) / camera.zoom;
      const labelHeight = (edge.labelRows.length * 7 + 16 + VIEW_MARGIN) / camera.zoom;
      const nearLabel = camera.zoom >= .5 && edge.labelRows.length &&
        intersects({l: label.x - labelWidth, r: label.x + labelWidth, t: label.y - labelHeight, b: label.y + labelHeight}, view);
      const visible = nearLabel || (intersects(path.bounds, pathArea) && path.segments.some(segment => intersects(segment, pathArea)));
      const mounted = edgeViews.get(id);
      if (visible) {
        if (mounted?.edge !== edge || mounted.route !== path || mounted.visualVersion !== edge.visualVersion ||
            mounted.arrowTitles !== arrowTitles) updateEdge(edge);
      } else if (mounted) {
        mounted.node.remove();
        edgeViews.delete(id);
      }
    }
    removeMissing(edgeViews, edges);
  }

  function removeMissing(views, data) {
    for (const [id, view] of views) {
      if (data.has(id)) continue;
      view.node.remove();
      views.delete(id);
    }
  }

  function updateCard(card) {
    let view = cardViews.get(card.id);
    if (!view) {
      const node = div('graph-card');
      node.dataset.placementId = card.id;
      node.dataset.blockId = card.blockId;
      node.tabIndex = 0;
      node.setAttribute('role', 'button');
      const shell = div('graph-card-shell'), inner = div('graph-card-inner');
      const title = div('graph-card-title'), description = div('graph-card-description');
      const badge = document.createElement('span');
      badge.className = 'graph-card-run-badge';
      badge.hidden = true;
      const type = div('graph-card-type'), count = div('graph-card-count'), summary = div('graph-card-summary');
      const material = div('graph-card-material'), names = div('graph-card-material-names');
      inner.append(type, title, count, description, summary, material, names);
      shell.append(inner);
      node.append(shell, badge);
      view = {node, inner, title, description, badge, type, count, summary, material, names};
      cardViews.set(card.id, view);
    }
    const {node, title, description} = view;
    if (node.dataset.blockId !== String(card.blockId)) node.dataset.blockId = card.blockId;
    if (view.kind !== card.kind) {
      node.dataset.kind = card.kind;
      (frameKind(card.kind) ? frames : nodes).append(node);
      view.kind = card.kind;
    }
    if (!sameRect(view.rect, card.rect)) {
      const {x, y, width, height} = card.rect;
      if (view.rect?.x !== x) node.style.left = `${x}px`;
      if (view.rect?.y !== y) node.style.top = `${y}px`;
      if (view.rect?.width !== width) node.style.width = `${width}px`;
      if (view.rect?.height !== height) node.style.height = `${height}px`;
      view.rect = card.rect;
    }
    if (view.color !== card.color) {
      const color = String(card.color);
      const paint = paintOf(color), ink = inkFor(color);
      node.style.setProperty('--graph-card-fill', paint?.fill ?? '');
      node.style.setProperty('--graph-card-edge', paint?.edge ?? '');
      node.style.setProperty('--graph-card-ink', ink?.ink ?? '');
      node.style.setProperty('--graph-card-dim', ink?.dim ?? '');
      view.color = card.color;
    }
    // Если редактор вложил нативное поле, обновление сцены не сбивает фокус и каретку.
    if (view.label !== card.title && !title.isContentEditable && !title.querySelector(interactive)) {
      title.textContent = card.title;
      node.setAttribute('aria-label', card.title || card.kind);
      view.label = card.title;
    }
    if (view.body !== card.description && !description.isContentEditable && !description.querySelector(interactive)) {
      description.textContent = card.description;
      description.hidden = !card.description;
      view.body = card.description;
    }
    if (view.summaryKey !== card.summaryKey) {
      const {type, count, rows, material, materialNames, cover, filename} = card.summary;
      view.type.textContent = type;
      view.type.hidden = !type;
      view.count.textContent = count;
      view.count.hidden = !count;
      view.material.textContent = [material, cover ? CARD_WORDS.cover : '', filename]
        .filter(Boolean).join(' · ');
      view.material.hidden = !view.material.textContent;
      view.names.textContent = materialNames.join(' · ');
      view.names.hidden = !materialNames.length;
      view.summary.replaceChildren(...rows.map(({kind, title}) => {
        const row = div('graph-card-summary-row');
        row.dataset.kind = kind;
        row.textContent = title;
        return row;
      }));
      view.summaryKey = card.summaryKey;
    }
    const selected = selection.has(card.blockId);
    if (view.selected !== selected) {
      node.setAttribute('aria-pressed', selected);
      node.classList.toggle('graph-card-selected', selected);
      view.selected = selected;
    }
    node.classList.toggle('graph-card-source', connectSource === card.blockId);
    node.classList.toggle('graph-card-dragging', gesture?.card === card && gesture.moved);
    if (view.skinVersion !== skinVersion) {
      updateSkinDetails(view, card);
      view.skinVersion = skinVersion;
    }
    paintCard(view, card);
  }

  function updateSkinDetails(view, card) {
    const {block, attachments, starter} = card;
    const props = block.properties ?? {};
    const legacy = props.legacyProps ?? {};
    view.node.toggleAttribute('data-starter', starter);
    if (starter && !view.starter) {
      view.starter = div('graph-card-starter');
      view.starter.textContent = STARTER_LABEL;
      view.inner.prepend(view.starter);
    }
    if (view.starter) view.starter.hidden = !starter;
    if (!skinRules) return;

    const related = [...(attachments?.entries() ?? [])];
    const cover = related.find(([, item]) => item.cover && item.image)?.[0] ?? (imageMaterial(block) ? block.id : null);
    const picture = skinRules.cover && !starter && card.project && cover
      ? 'api.php?' + new URLSearchParams({op: 'graph.asset.file', project: card.project, material: cover}) : '';
    if (view.picture !== picture) {
      if (picture && !view.cover) {
        view.cover = document.createElement('img');
        Object.assign(view.cover, {className: 'graph-card-cover', alt: '', loading: 'lazy', decoding: 'async', draggable: false});
        view.cover.addEventListener('error', () => { view.cover.hidden = true; });
        view.description.after(view.cover);
      }
      if (view.cover) {
        view.cover.hidden = !picture;
        if (picture) view.cover.src = picture;
        else view.cover.removeAttribute('src');
      }
      view.picture = picture;
    }

    const details = [];
    if (skinRules.seq && props.legacyNo) details.push(['seq', `№ ${props.legacyNo}`]);
    if (skinRules.id) details.push(['id', `#${block.id}`]);
    if (skinRules.md && (related.some(([, item]) => item.spec) ||
        (Array.isArray(props.content) && props.content.some(field => field?.kind === 'markdown')))) details.push(['md', 'MD']);
    if (skinRules.agent && card.agent) details.push(['agent', card.agent]);
    if (skinRules.props) {
      for (const [key, value] of Object.entries(legacy).filter(([key, value]) => key !== 'start' &&
          ['boolean', 'number', 'string'].includes(typeof value)).slice(0, 2)) {
        details.push(['prop', summaryText(`${key}: ${value}`)]);
      }
    }
    const key = JSON.stringify(details);
    if (view.detailsKey === key) return;
    if (details.length && !view.details) {
      view.details = div('graph-card-details');
      view.inner.append(view.details);
    }
    if (view.details) {
      view.details.hidden = !details.length;
      view.details.replaceChildren(...details.map(([kind, value]) => {
        const chip = document.createElement('span');
        chip.dataset.detail = kind;
        chip.textContent = value;
        return chip;
      }));
    }
    view.detailsKey = key;
  }

  function paintCard(view, card) {
    const entry = runPaint?.nodes?.[card.blockId] ?? EMPTY_PAINT;
    const state = Object.hasOwn(STEP_WORDS, entry.state) && entry.state !== 'none' ? entry.state : '';
    const wait = state === 'ready' && Object.hasOwn(WAIT_LABELS, entry.wait) ? entry.wait : '';
    const lap = state ? Math.max(1, finite(entry.lap, 1)) : 1;
    const detail = state ? text(entry.error || entry.result) : '';
    if (view.runStep === state && view.runWait === wait && view.runLap === lap &&
        view.runKind === card.kind && view.runDetail === detail) return;
    const {node, badge} = view;
    if (view.runStep !== state) {
      node.dataset.runStep = state;
      badge.hidden = !state;
    }
    if (view.runWait !== wait) node.dataset.runWait = wait;
    const mark = state ? stepMark({type: card.kind}, state, wait) : '';
    const badgeText = mark + (lap > 1 ? ` ↻${lap}` : '');
    if (badge.textContent !== badgeText) badge.textContent = badgeText;
    badge.title = state ? [
      wait ? t(WAIT_LABELS[wait]) : STEP_WORDS[state],
      lap > 1 ? t('editor.canvas.round_n', {n: lap}) : '',
      detail,
    ].filter(Boolean).join(' · ') : '';
    if (detail && !view.result) {
      view.result = div('graph-card-result');
      view.inner.append(view.result);
    }
    if (view.result) {
      view.result.hidden = !detail;
      view.result.textContent = summaryText(detail);
    }
    Object.assign(view, {runStep: state, runWait: wait, runLap: lap, runKind: card.kind, runDetail: detail});
  }

  function updateEdge(edge) {
    let view = edgeViews.get(edge.id);
    if (!view) {
      const node = svg('g', {'data-arrow-id': edge.id, class: 'graph-arrow'});
      const hit = svg('path', {class: 'graph-arrow-hit'});
      const tooltip = svg('title');
      hit.append(tooltip);
      const line = svg('path', {class: 'graph-arrow-line'});
      const anchor = svg('g', {class: 'graph-arrow-label-anchor'});
      const text = svg('text', {class: 'graph-arrow-label', 'text-anchor': 'middle'});
      anchor.append(text);
      node.append(hit, line, anchor);
      links.append(node);
      view = {node, hit, line, anchor, text, tooltip};
      edgeViews.set(edge.id, view);
    }
    if (view.d !== edge.route.d) {
      view.line.setAttribute('d', edge.route.d);
      view.hit.setAttribute('d', edge.route.d);
      view.d = edge.route.d;
    }
    const {x, y} = edge.route.label;
    if (view.point?.x !== x || view.point?.y !== y) {
      view.anchor.setAttribute('transform', `translate(${x} ${y})`);
      view.point = edge.route.label;
    }
    const label = arrowTitles ? edge.label : edge.branchLabel;
    if (view.label !== label || view.hint !== edge.label) {
      const rows = arrowTitles ? edge.labelRows : wrapLabel(label);
      view.tooltip.textContent = edge.label;
      view.text.replaceChildren(...rows.map((row, index) => {
        const line = svg('tspan', {x: 0, y: (index - (rows.length - 1) / 2) * 13});
        line.textContent = row;
        return line;
      }));
      Object.assign(view, {label, hint: edge.label, labelRows: rows.length});
    }
    if (view.direction !== edge.direction) {
      const heads = arrowHeads(edge.direction);
      for (const [attribute, show] of [['marker-start', heads.source], ['marker-end', heads.target]]) {
        if (show) view.line.setAttribute(attribute, `url(#${marker})`);
        else view.line.removeAttribute(attribute);
      }
      view.direction = edge.direction;
    }
    if (view.branch !== edge.branch) {
      view.node.dataset.branch = edge.branch;
      view.branch = edge.branch;
    }
    const {color, dash, width, back} = edge.appearance;
    if (view.color !== color) {
      view.node.style.setProperty('--graph-arrow-color', color ? edgeColor(String(color)) : '');
      view.color = color;
    }
    if (view.width !== width) {
      view.node.style.setProperty('--graph-arrow-width', bounded(finite(width, 2), .5, 16));
      view.width = width;
    }
    view.line.classList.toggle('graph-arrow-dashed', Boolean(dash || back || edge.back));
    paintEdge(view, edge);
    Object.assign(view, {edge, route: edge.route, visualVersion: edge.visualVersion, arrowTitles});
  }

  function paintEdge(view, edge) {
    const {state, chosen, passes} = edge.look;
    if (view.runState !== state) {
      view.node.dataset.runEdge = state;
      view.runState = state;
    }
    if (view.runChosen !== chosen) {
      view.node.toggleAttribute('data-run-chosen', chosen);
      view.runChosen = chosen;
    }
    if (passes > 1 && !view.passMark) {
      view.passMark = svg('text', {class: 'graph-arrow-pass', 'text-anchor': 'middle'});
      view.anchor.append(view.passMark);
    }
    if (view.passMark && (view.passes !== passes || view.passRows !== view.labelRows)) {
      view.passMark.setAttribute('y', view.labelRows ? (view.labelRows + 1) * 6.5 : 0);
      view.passMark.textContent = passes > 1 ? `×${passes}` : '';
      view.passes = passes;
      view.passRows = view.labelRows;
    }
  }

  function fit() {
    stopGesture(false);
    const bounds = [...cards.values()].map(card => boxOf(card.rect)).reduce((area, box) => area ? union(area, box) : box, null);
    if (bounds && viewport.clientWidth && viewport.clientHeight) {
      const zoom = bounded(Math.min(
        viewport.clientWidth / (bounds.r - bounds.l + 160),
        viewport.clientHeight / (bounds.b - bounds.t + 160),
      ), .15, 1.2);
      camera = {
        x: (viewport.clientWidth - (bounds.l + bounds.r) * zoom) / 2,
        y: (viewport.clientHeight - (bounds.t + bounds.b) * zoom) / 2,
        zoom,
      };
    } else {
      camera = {x: 32, y: 24, zoom: 1};
    }
    cameraDirty = true;
    schedule();
  }

  function exportFrame(vector = false) {
    if (!cards.size) return null;
    if (renderFrame !== null) cancelAnimationFrame(renderFrame);
    draw();
    let bounds = null;
    const include = box => { bounds = bounds ? union(bounds, box) : box; };
    for (const card of cards.values()) {
      if (!vector) updateCard(card);
      include(boxOf(card.rect));
    }
    for (const edge of edges.values()) {
      if (!edge.route) continue;
      if (!vector) updateEdge(edge);
      include(edge.route.bounds);
      const {x, y} = edge.route.label;
      const half = edge.labelWidth / 2, height = edge.labelRows.length * 7 + 12;
      include({l: x - half, t: y - height, r: x + half, b: y + height});
    }
    const {l, t, r, b} = enlarge(bounds, 40);
    const width = Math.ceil(r - l), height = Math.ceil(b - t);
    if (vector) {
      return {width, height, left: l, top: t, cards: [...cards.values()],
        edges: [...edges.values()].filter(edge => edge.route)};
    }
    const frame = viewport.cloneNode(true);
    frame.classList.remove('graph-canvas-overview', 'graph-canvas-far', 'graph-canvas-near');
    frame.style.width = `${width}px`;
    frame.style.height = `${height}px`;
    frame.querySelector('.graph-canvas-world').style.transform = `translate(${-l}px, ${-t}px)`;
    frame.style.setProperty('--graph-label-scale', 1);
    frame.style.setProperty('--graph-grid-x', `${-l}px`);
    frame.style.setProperty('--graph-grid-y', `${-t}px`);
    frame.style.setProperty('--graph-grid-step', '16px');
    for (const card of frame.querySelectorAll('.graph-card')) {
      for (const attr of ['tabindex', 'role', 'aria-pressed']) card.removeAttribute(attr);
    }
    return {frame, width, height};
  }

  function cardAt(target) {
    const node = target.closest('.graph-card');
    return node ? cards.get(Number(node.dataset.placementId)) : null;
  }

  function cardEvent(card) {
    return {blockId: card.blockId, placementId: card.id};
  }

  function choose(card, event) {
    if (tool === 'connect') {
      if (connectSource === null) connectSource = card.blockId;
      else {
        const sourceId = connectSource;
        connectSource = null;
        handlers.onConnect?.({sourceId, targetId: card.blockId});
      }
      schedule();
    } else {
      handlers.onSelect?.({...cardEvent(card), additive: event.shiftKey || event.metaKey || event.ctrlKey});
    }
  }

  function local(event) {
    const {left, top} = viewport.getBoundingClientRect();
    return {x: event.clientX - left, y: event.clientY - top};
  }

  function touchMetrics(ids) {
    const [a, b] = ids.map(id => touches.get(id));
    return {x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, distance: Math.hypot(a.x - b.x, a.y - b.y)};
  }

  function startPinch() {
    stopGesture(false);
    multiTouch = skipClick = true;
    const ids = [...touches.keys()];
    const start = touchMetrics(ids);
    pinch = {ids, start, camera};
    for (const id of ids) viewport.setPointerCapture(id);
    schedule();
  }

  function movePinch() {
    const {ids, start, camera: initial} = pinch;
    const point = touchMetrics(ids);
    const zoom = bounded(initial.zoom * point.distance / Math.max(1, start.distance), .15, 3);
    const ratio = zoom / initial.zoom;
    camera = {
      x: point.x - (start.x - initial.x) * ratio,
      y: point.y - (start.y - initial.y) * ratio,
      zoom,
    };
    cameraDirty = true;
    schedule();
  }

  function endTouch(event) {
    if (!touches.delete(event.pointerId)) return;
    if (pinch?.ids.includes(event.pointerId)) {
      pinch = null;
      schedule();
    }
    if (!touches.size) multiTouch = false;
  }

  function stopGesture(commit) {
    if (!gesture) return;
    const {id, card, moved, x, y} = gesture;
    const delta = pending;
    gesture = pending = null;
    skipClick = moved;
    if (viewport.hasPointerCapture(id)) viewport.releasePointerCapture(id);
    if (card && moved) {
      invalidate(card);
      if (!frameKind(card.kind)) dirtyBoxes.push(boxOf(card.base));
      card.rect = commit && delta ? {...card.base, x: x + delta.x / camera.zoom, y: y + delta.y / camera.zoom} : card.base;
      invalidate(card);
      schedule();
      if (commit) handlers.onMove?.({placementId: card.id, x: card.rect.x, y: card.rect.y});
    } else if (!card) {
      if (commit && delta) {
        camera = {...camera, x: x + delta.x, y: y + delta.y};
        cameraDirty = true;
      }
      schedule();
    }
  }

  listen('wheel', event => {
    if (event.target.closest(interactive)) return;
    event.preventDefault();
    if (gesture || multiTouch) return;
    const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? 100 : 1;
    if (!event.ctrlKey && !event.metaKey) {
      camera = {...camera, x: camera.x - event.deltaX * unit, y: camera.y - event.deltaY * unit};
      cameraDirty = true;
      schedule();
      return;
    }
    const zoom = bounded(camera.zoom * Math.exp(-event.deltaY * unit * .01), .15, 3);
    const point = local(event);
    const ratio = zoom / camera.zoom;
    camera = {x: point.x - (point.x - camera.x) * ratio, y: point.y - (point.y - camera.y) * ratio, zoom};
    cameraDirty = true;
    schedule();
  }, {passive: false});

  listen('pointerdown', event => {
    if (event.target.closest(interactive)) return;
    if (event.pointerType === 'touch') {
      touches.set(event.pointerId, local(event));
      if (!multiTouch && touches.size === 2) startPinch();
      if (multiTouch) return;
    }
    if (gesture) return;
    skipClick = false;
    const card = cardAt(event.target);
    const pan = event.button === 1 || (event.button === 0 && (event.altKey || !card || (handlers.readOnly && event.pointerType === 'touch')));
    if (!pan && (!card || event.button !== 0 || tool === 'connect' || handlers.readOnly)) return;
    if (pan && event.button === 1) event.preventDefault();
    const {x, y} = pan ? camera : card.rect;
    gesture = {id: event.pointerId, sx: event.clientX, sy: event.clientY, x, y, card: pan ? null : card, moved: false};
    if (event.button === 1) viewport.setPointerCapture(event.pointerId);
  });

  listen('pointermove', event => {
    if (touches.has(event.pointerId)) {
      touches.set(event.pointerId, local(event));
      if (pinch?.ids.includes(event.pointerId)) movePinch();
      if (multiTouch) return;
    }
    if (gesture?.id !== event.pointerId) return;
    const x = event.clientX - gesture.sx, y = event.clientY - gesture.sy;
    if (!gesture.moved && Math.hypot(x, y) < 4) return;
    if (!gesture.moved) {
      gesture.moved = true;
      viewport.setPointerCapture(event.pointerId);
    }
    pending = {x, y};
    schedule();
  });

  window.addEventListener('pointerup', event => {
    endTouch(event);
    if (gesture?.id !== event.pointerId) return;
    if (gesture.moved) pending = {x: event.clientX - gesture.sx, y: event.clientY - gesture.sy};
    stopGesture(true);
  }, {signal: controller.signal});
  for (const name of ['pointercancel', 'lostpointercapture']) {
    listen(name, event => {
      if (name === 'pointercancel') endTouch(event);
      if (gesture?.id === event.pointerId) stopGesture(false);
    });
  }

  listen('click', event => {
    if (skipClick || event.target.closest(interactive)) return;
    const card = cardAt(event.target);
    if (card) choose(card, event);
    else if (tool === 'select' && !event.target.closest('.graph-arrow')) {
      handlers.onSelect?.({blockId: null, placementId: null, additive: event.shiftKey || event.metaKey || event.ctrlKey});
    }
  });
  listen('dblclick', event => {
    if (skipClick || event.target.closest(interactive) || tool === 'connect') return;
    const card = cardAt(event.target);
    if (card) handlers.onOpen?.(cardEvent(card));
    else if (!event.target.closest('.graph-arrow')) {
      const {x, y} = local(event);
      handlers.onEmpty?.({x: (x - camera.x) / camera.zoom, y: (y - camera.y) / camera.zoom});
    }
  });
  listen('keydown', event => {
    if (event.target.closest(interactive)) return;
    if (event.key === 'Escape' && connectSource !== null) {
      connectSource = null;
      schedule();
    }
    const card = cardAt(event.target);
    if (!card || event.repeat) return;
    if (event.key === ' ' || (event.key === 'Enter' && tool === 'connect')) {
      event.preventDefault();
      choose(card, event);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      handlers.onOpen?.(cardEvent(card));
    }
  });

  function destroy() {
    controller.abort();
    observer.disconnect();
    stopGesture(false);
    if (renderFrame !== null) cancelAnimationFrame(renderFrame);
    viewport.remove();
    if (staticHost) host.classList.remove('graph-canvas-host');
  }

  return {render, setSkin, fit, destroy, exportFrame: handlers.snapshot ? exportFrame : undefined};
}
