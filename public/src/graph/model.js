/** Концы стрелки, на которых рисуются наконечники. Порядок концов берётся из API. */
export function arrowHeads(direction) {
  switch (direction) {
    case 'forward': return { source: false, target: true };
    case 'reverse': return { source: true, target: false };
    case 'both': return { source: true, target: true };
    case 'none': return { source: false, target: false };
    default: throw new RangeError('Unknown arrow direction: ' + direction);
  }
}

function freezeJson(value) {
  if (value && typeof value === 'object') {
    for (const child of Object.values(value)) freezeJson(child);
    Object.freeze(value);
  }
  return value;
}

function indexBy(map, id, arrow) {
  if (!map.has(id)) map.set(id, []);
  map.get(id).push(arrow);
}

function ordered(arrows = []) {
  return [...arrows].sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0) || a.id - b.id);
}

/**
 * Кеш сцен одного проекта на одной ревизии.
 * Записи принадлежат модели. Представления читают их по ID.
 */
export function createGraphModel(snapshot) {
  const { rootId, rev } = snapshot;
  const blocks = new Map();
  const arrows = new Map();
  const placements = new Map();
  const parents = new Map();
  const children = new Map();
  const incident = new Map();
  const loadedScenes = new Map();
  let current;
  let selection = new Set();

  function addSnapshot(answer) {
    if (answer.rootId !== rootId || answer.rev !== rev) {
      throw new RangeError('Graph snapshots must have the same root and revision');
    }

    for (const block of answer.blocks) {
      if (!blocks.has(block.id)) blocks.set(block.id, freezeJson(structuredClone(block)));
    }
    // Стенды и старый graph.get ещё могут отдавать вложения вместе со стрелками.
    const incomingPlacements = answer.placements ?? answer.arrows.filter(({ kind }) => kind === 'contains')
      .map(({ id, sourceId, targetId, sort, properties }) => ({
        id, parentId: sourceId, blockId: targetId, sort, placement: properties?.placement ?? null,
      }));
    for (const row of incomingPlacements) {
      if (placements.has(row.id)) continue;
      const placement = freezeJson(structuredClone(row));
      placements.set(row.id, placement);
      indexBy(children, placement.parentId, placement);
      indexBy(parents, placement.blockId, placement);
    }
    for (const row of answer.arrows) {
      if (row.kind === 'contains') continue;
      if (arrows.has(row.id)) continue;
      const arrow = freezeJson(structuredClone(row));
      arrows.set(arrow.id, arrow);
      indexBy(incident, arrow.sourceId, arrow);
      if (arrow.targetId !== arrow.sourceId) indexBy(incident, arrow.targetId, arrow);
    }
    loadedScenes.set(answer.parentId, {
      previews: freezeJson(structuredClone(answer.previews ?? {})),
      legacyFolder: answer.legacyFolder ?? null,
      hasLegacyFolders: answer.hasLegacyFolders ?? false,
    });
  }

  function placementsOf(parentId) {
    return ordered(children.get(parentId));
  }

  /** Сцена в мировых координатах. Размер кадра задаёт рендерер. */
  function scene(parentId = current.blockId) {
    if (!loadedScenes.has(parentId)) return null;
    const placements = placementsOf(parentId);
    const visibleBlocks = new Set(placements.map(({ blockId }) => blockId));
    const edges = new Map();
    for (const blockId of visibleBlocks) {
      for (const arrow of incident.get(blockId) ?? []) {
        if (arrow.kind !== 'contains' && visibleBlocks.has(arrow.sourceId) && visibleBlocks.has(arrow.targetId)) {
          edges.set(arrow.id, arrow);
        }
      }
    }
    return { parentId, placements, arrows: ordered(edges.values()), ...loadedScenes.get(parentId) };
  }

  /** Путь состоит из ID вложений, от начального блока к открытому. */
  function crumbs({ startId, path } = current) {
    if (!blocks.has(startId)) return null;
    const result = [{ startId, blockId: startId, path: [], viaId: null }];
    let blockId = startId;
    for (const [index, id] of path.entries()) {
      const placement = placements.get(id);
      if (placement?.parentId !== blockId || !blocks.has(placement.blockId)) return null;
      blockId = placement.blockId;
      result.push({ startId, blockId, path: path.slice(0, index + 1), viaId: id });
    }
    return result;
  }

  function open(context) {
    const target = crumbs(context)?.at(-1);
    if (!target || !loadedScenes.has(target.blockId)) return false;
    current = Object.freeze({ startId: target.startId, blockId: target.blockId, path: Object.freeze(target.path) });
    select(selection);
    return true;
  }

  function select(ids) {
    const placed = new Set(placementsOf(current.blockId).map(({ blockId }) => blockId));
    selection = new Set([...ids].filter((id) => placed.has(id)));
    return [...selection];
  }

  /** Команда редактирования общих данных блока для очереди batch. */
  function updateBlock(id, changes) {
    if (!blocks.has(id)) throw new RangeError('Unknown block: ' + id);
    for (const key of Object.keys(changes)) {
      if (!['kind', 'title', 'description', 'properties', 'appearance'].includes(key)) {
        throw new RangeError('Cannot edit block field: ' + key);
      }
    }
    return { ...structuredClone(changes), op: 'block.update', id };
  }

  /** Команда изменения геометрии или вида одного вложения. */
  function updatePlacement(id, changes) {
    if (!placements.has(id)) throw new RangeError('Unknown placement: ' + id);
    return { op: 'placement.update', id, placement: structuredClone(changes) };
  }

  addSnapshot(snapshot);
  if (!open({ startId: snapshot.parentId, path: [] })) {
    throw new RangeError('Graph snapshot has no parent block');
  }

  return {
    rootId,
    rev,
    addSnapshot,
    block: (id) => blocks.get(id),
    arrow: (id) => arrows.get(id),
    placement: (id) => placements.get(id),
    parentsOf: (id) => ordered(parents.get(id)),
    childrenOf: (id) => ordered(children.get(id)),
    arrowsOf: (id) => ordered(incident.get(id)),
    placementsOf,
    hasScene: (id) => loadedScenes.has(id),
    scene,
    get context() { return current; },
    open,
    crumbs,
    select,
    get selection() { return [...selection]; },
    updateBlock,
    updatePlacement,
  };
}
