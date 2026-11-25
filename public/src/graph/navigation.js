import { get } from 'goblin/api/client.js';
import { t } from 'goblin/core/i18n.js';
import { createGraphModel } from 'goblin/graph/model.js';

export const contextKey = ({ startId, path }) => [startId, ...path].join('.');

function id(value) {
  const number = Number(value);
  return Number.isSafeInteger(number) && number > 0 ? number : null;
}

export function readAddress() {
  const hash = new URLSearchParams(location.hash.slice(1));
  const blockId = id(hash.get('b'));
  const startId = id(hash.get('a'));
  const encodedPath = hash.get('path');
  const path = encodedPath ? encodedPath.split('.').map(id) : [];
  return {
    project: (hash.get('p') || hash.get('s'))?.trim() || '',
    blockId,
    legacyFolder: id(hash.get('f')),
    legacyElement: id(hash.get('e')),
    runId: id(hash.get('run')),
    context: startId && path.every(Boolean) ? { startId, path } : null,
  };
}

export function writeAddress(project, context, replace = false, runId = null, keepLegacy = true) {
  const hash = new URLSearchParams({ p: project });
  const previous = readAddress();
  if (keepLegacy && previous.project === project && previous.legacyFolder) hash.set('f', previous.legacyFolder);
  if (runId) hash.set('run', runId);
  if (context) {
    hash.set('b', context.blockId);
    hash.set('a', context.startId);
    if (context.path.length) hash.set('path', context.path.join('.'));
  }
  const url = '#' + hash;
  if (url !== location.hash) history[replace ? 'replaceState' : 'pushState'](null, '', url);
}

export function readScene(project, parent) {
  return get('graph.scene', { project, parent }, ['not_found']);
}

function sameRevision(model, answer) {
  if (model.rootId !== answer.rootId || model.rev !== answer.rev) {
    throw Object.assign(new Error(t('editor.graph.revision_changed')), { code: 'graph_revision' });
  }
}

async function addScene(model, project, parent) {
  if (model.hasScene(parent)) return;
  const answer = await readScene(project, parent);
  sameRevision(model, answer);
  model.addSnapshot(answer);
}

/** По ссылке с одним ID ищем кратчайший путь до корня по входящим вложениям. */
async function findPath(model, project, blockId) {
  const queue = [blockId];
  const visited = new Set(queue);
  const towardsBlock = new Map();

  for (let index = 0; index < queue.length; index++) {
    const block = queue[index];
    if (block === model.rootId) {
      const path = [];
      let cursor = block;
      while (towardsBlock.has(cursor)) {
        const arrow = towardsBlock.get(cursor);
        path.push(arrow.id);
        cursor = arrow.targetId;
      }
      return { startId: model.rootId, path };
    }

    const neighbors = await get('graph.neighbors', { project, block }, ['not_found']);
    sameRevision(model, neighbors);
    for (const arrow of neighbors.arrows) {
      if (arrow.kind !== 'contains' || arrow.targetId !== block || visited.has(arrow.sourceId)) continue;
      visited.add(arrow.sourceId);
      queue.push(arrow.sourceId);
      towardsBlock.set(arrow.sourceId, arrow);
    }
  }
  return { startId: blockId, path: [] };
}

export async function loadContext(model, project, { startId, path }) {
  let blockId = startId;
  const validPath = [];
  await addScene(model, project, blockId);
  for (const placementId of path) {
    const placement = model.placement(placementId);
    if (placement?.parentId !== blockId) break;
    await addScene(model, project, placement.blockId);
    blockId = placement.blockId;
    validPath.push(placementId);
  }
  return { startId, blockId, path: validPath };
}

export async function readGraph({ project, blockId, legacyFolder, context }) {
  let root;
  try {
    root = await readScene(project);
  } catch (error) {
    if (error.code !== 'not_found') throw error;
    return { model: null, rev: null };
  }
  if (root.rootId === null) return { model: null, rev: root.rev, hasLegacyFolders: root.hasLegacyFolders };

  const model = createGraphModel(root);
  try {
    if (legacyFolder) {
      const legacy = await get('graph.scene', { project, legacyFolder }, ['not_found']);
      sameRevision(model, legacy);
      model.addSnapshot(legacy);
      blockId ??= legacy.parentId;
    }
  } catch (error) {
    if (error.code !== 'not_found') throw error;
  }
  try {
    const desired = context ?? (blockId && blockId !== root.rootId
      ? await findPath(model, project, blockId)
      : model.context);
    let loaded = await loadContext(model, project, desired);
    if (context && blockId && loaded.path.length === context.path.length && loaded.blockId !== blockId) {
      loaded = await loadContext(model, project, await findPath(model, project, blockId));
    }
    model.open(loaded);
  } catch (error) {
    if (error.code !== 'not_found') throw error;
  }
  return { model, rev: model.rev };
}
