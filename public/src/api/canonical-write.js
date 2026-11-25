const geometryKeys = new Set(['x', 'y', 'width', 'height']);
const localStyleKeys = new Set(['z', 'collapsed']);
const folderSettings = ['workDir', 'workUrl', 'shareScheme', 'runEnv', 'roleScheme', 'camera'];
const own = Object.hasOwn;
const listing = (rows) => typeof rows?.values === 'function' ? [...rows.values()] : Object.values(rows ?? {});
const indexed = (rows) => new Map(listing(rows).filter(Boolean).map((row) => [Number(row.id), row]));
const has = (record, key) => own(record, key);

function rejected(operation, missing = false) {
  const message = missing
    ? 'Reload the scene before editing it.'
    : 'This action is unavailable in this view.';
  throw Object.assign(new Error(message), { ok: false, code: missing ? 'conflict' : 'unsupported_mode', operation });
}

function positiveId(value) {
  return (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value)))
    && Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null;
}

function changes(source, patch, empty = false) {
  const next = { ...source };
  for (const [key, value] of Object.entries(patch ?? {})) {
    if (value === null || value === undefined || (empty && value === '')) delete next[key];
    else next[key] = value;
  }
  return next;
}

function styleParts(style) {
  const placement = {};
  const appearance = {};
  for (const [key, value] of Object.entries(style ?? {})) {
    if (geometryKeys.has(key)) {
      if (Number.isFinite(value) && (!(key === 'width' || key === 'height') || value > 0)) placement[key] = value;
    } else {
      appearance[key] = value;
    }
  }
  return { placement, appearance };
}

function writeProperties(properties, patch) {
  const next = { ...properties };
  const inherited = { ...next.legacyProps };
  for (const key of Object.keys(patch ?? {})) {
    if (key === 'content') rejected('element.update');
    delete next[key];
  }
  const merged = changes(inherited, patch, true);
  if (Object.keys(merged).length) next.legacyProps = merged;
  else delete next.legacyProps;
  return next;
}

function isStart(value) {
  return value === true || value === 1 || value === '1' || value === 'true' || value === 'yes';
}

function resultFor(answer, index, synthetic) {
  return index === null ? { rev: answer.rev, ...synthetic } : { ...answer.results[index], ...synthetic };
}

/** Translate one immutable classic-editor batch using the canonical snapshot it was edited against. */
export function prepareCanonicalWrite(operations, snapshot = {}) {
  const folders = indexed(snapshot.folders);
  if (snapshot.folder?.id) folders.set(Number(snapshot.folder.id), snapshot.folder);
  const elements = indexed(snapshot.elements);
  const agents = indexed(snapshot.agents);
  const roles = new Map(listing(snapshot.roles).map((row) => [row.role, row.id]));
  const assets = indexed(snapshot.assets);
  const assetLinks = listing(snapshot.assetLinks);
  const projected = { element: new Map(), folder: new Map(), agent: new Map(), material: new Map() };
  const originalDetached = new Map(snapshot.detached ?? []);
  const detached = new Map(originalDetached);
  const native = [];
  const results = [];
  const removedArrows = new Set();
  const root = positiveId(snapshot.rootId) ?? 'root';
  let operation = '';
  let serial = 0;

  for (const row of elements.values()) {
    for (const asset of listing(row.assets)) {
      if (asset?.asset) assets.set(Number(asset.asset), asset);
      if (asset?.link) assetLinks.push({ ...asset, ownerId: row._graph?.blockId, folder: row.folder ?? row.id });
    }
  }
  for (const row of folders.values()) {
    for (const asset of listing(row.assets)) {
      if (asset?.asset) assets.set(Number(asset.asset), asset);
      if (asset?.link) assetLinks.push({ ...asset, ownerId: row._graph?.blockId, folder: row.folder ?? row.id });
    }
  }

  const references = new Map();
  function add(op) {
    const index = native.push(op) - 1;
    if (op.ref) references.set(op.ref, index);
    return index;
  }

  function acknowledged(value, answer) {
    return references.has(value) ? answer.results[references.get(value)]?.id : value;
  }

  function elementResult(answer, index, record, extra = {}) {
    const id = acknowledged(record.arrowId ?? record.placementId, answer);
    const no = record.no ?? acknowledged(record.blockId ?? record.arrowId, answer);
    return resultFor(answer, index, { id, no, ...extra });
  }

  function temporary(kind) {
    return 'visual-' + kind + '-' + (++serial);
  }

  function remember(kind, alias, row) {
    const entries = projected[kind];
    if (alias !== undefined && alias !== null) entries.set(alias, row);
    if (row.id !== undefined && row.id !== null) entries.set(row.id, row);
  }

  function folderFor(value, fallback = false) {
    if (value === null || value === undefined || value === 0) return { blockId: root, id: root };
    const draft = projected.folder.get(value) ?? projected.folder.get(positiveId(value));
    if (draft?.folder) return draft;
    const id = positiveId(value);
    const row = folders.get(id);
    if (row) return { ...row, ...row._graph, id, blockId: row._graph?.blockId ?? id, folder: true };
    if (id && (fallback || id === root)) return { id, blockId: id, folder: true };
    return rejected(operation, true);
  }

  function elementFor(value, edges = true) {
    const id = positiveId(value);
    let item = projected.element.get(value) ?? projected.element.get(id);
    if (!item) {
      const row = elements.get(id);
      if (row?._graph) {
        item = { ...row, ...row._graph, id };
        remember('element', id, item);
      }
    }
    if (!item || item.deleted || item.folder === true || (!edges && !item.blockId)) rejected(operation, true);
    return item;
  }

  function placementId(record) {
    if (record.placementId === undefined || record.placementId === null) rejected(operation, true);
    return record.placementId;
  }

  function arrowDelete(id) {
    if (!id) rejected(operation, true);
    if (removedArrows.has(id)) return null;
    removedArrows.add(id);
    return add({ op: 'arrow.delete', id });
  }

  function relation(record, key, target, targetValue) {
    const current = record[key];
    if ((current?.blockId ?? null) === target && (current || !record[targetValue])) return null;
    if (current?.arrowId) arrowDelete(current.arrowId);
    else if (!current && record[targetValue]) rejected(operation, true);
    if (target === null) {
      record[key] = null;
      record[targetValue] = null;
      return null;
    }
    const ref = temporary(key);
    record[key] = { arrowId: ref, blockId: target };
    record[targetValue] = target;
    return add({ op: 'arrow.create', ref, kind: 'link', sourceId: record.blockId, targetId: target,
      properties: { relation: key === 'assignment' ? 'agent' : 'gatewayTarget' } });
  }

  function members(record, values) {
    if (!Array.isArray(values)) rejected(operation);
    const wanted = new Set(values.map((value) => elementFor(value, false).blockId));
    const current = new Map(listing(record.memberOf).map((item) => [item.blockId, item.placementId]));
    if (record.in?.length && !record.memberOf) rejected(operation, true);
    for (const [blockId, id] of current) {
      if (!wanted.has(blockId)) {
        if (!id) rejected(operation, true);
        add({ op: 'placement.delete', id });
        current.delete(blockId);
      }
    }
    for (const parentId of wanted) {
      if (current.has(parentId)) continue;
      const ref = temporary('member');
      add({ op: 'placement.create', ref, parentId, blockId: record.blockId });
      current.set(parentId, ref);
    }
    record.memberOf = [...current].map(([blockId, id]) => ({ blockId, placementId: id }));
    record.in = [...values];
  }

  function setLinks(record, input) {
    const props = input.props ?? {};
    if (has(input, 'agent') || isStart(props.start)) {
      const id = isStart(props.start) ? null : input.agent;
      const agent = id === null ? null : projected.agent.get(id) ?? projected.agent.get(positiveId(id)) ?? agents.get(positiveId(id));
      const target = agent?._graph?.blockId ?? agent?.blockId ?? positiveId(agent?.id);
      if (id !== null && !target) rejected(operation, true);
      relation(record, 'assignment', target ?? null, 'agent');
    }
    if (has(input, 'target')) {
      const target = input.target == null ? null : folderFor(input.target).blockId;
      relation(record, 'gatewayTarget', target, 'target');
    }
    if (has(input, 'in')) members(record, input.in);
  }

  function createElement(input) {
    const { type, ref } = input;
    const no = input.no == null ? null : positiveId(input.no);
    if (input.no != null && !no) rejected(operation);
    if (type === 'arrow' || type === 'link') {
      const descriptor = temporary('edge');
      const source = elementFor(input.from, false);
      const target = elementFor(input.to, false);
      const properties = writeProperties({}, input.props);
      if (no !== null) properties.no = no;
      if (has(input, 'title')) properties.title = input.title;
      if (has(input, 'description')) properties.description = input.description;
      if (type === 'arrow') {
        properties.branchKey = input.branch ?? 'flow';
        if (has(input, 'back')) properties.back = Boolean(input.back);
      }
      const appearance = changes({}, input.style);
      const index = add({ op: 'arrow.create', ref: descriptor, kind: type === 'arrow' ? 'flow' : 'link',
        sourceId: source.blockId, targetId: target.blockId, properties, appearance });
      remember('element', ref, { id: ref, arrowId: descriptor, type, no, properties, appearance, from: source.id, to: target.id,
        sourceId: source.blockId, targetId: target.blockId, folder: source.folder, direction: 'forward' });
      return (answer) => resultFor(answer, index, { no: no ?? answer.results[index].id });
    }

    if (typeof type !== 'string' || ['project', 'folder', 'scheme'].includes(type)) rejected(operation);
    const parent = folderFor(input.folder ?? snapshot.folder?.id);
    const key = parent.blockId + ':' + no;
    const archived = input.op === 'element.restore' ? detached.get(key) : null;
    const blockRef = archived?.blockId ?? temporary('block');
    const placementRef = temporary('placement');
    const { placement, appearance } = styleParts(input.style);
    const local = {};
    for (const field of localStyleKeys) {
      if (has(appearance, field)) {
        local[field] = appearance[field];
        delete appearance[field];
      }
    }
    const properties = writeProperties(archived?.properties, input.props);
    if (no !== null && !archived) properties.no = no;
    const card = { kind: type, title: input.title ?? '', description: input.description ?? '', properties,
      appearance: changes(archived?.appearance, appearance) };
    const blockIndex = archived ? null : add({ op: 'block.create', ref: blockRef, ...card });
    const index = add({ op: 'placement.create', ref: placementRef, parentId: parent.blockId, blockId: blockRef,
      placement, appearance: changes(archived?.placementAppearance, local) });
    const record = { ...archived, id: ref, blockId: blockRef, placementId: placementRef, folder: parent.blockId, type,
      no, properties, appearance: card.appearance, placementAppearance: changes(archived?.placementAppearance, local),
      title: card.title, description: card.description, in: [], memberOf: [] };
    if (archived) {
      detached.delete(key);
      record.deleted = false;
    }
    remember('element', ref, record);
    setLinks(record, input);
    if (!archived) for (const asset of input.assets ?? []) attachMaterial(blockRef, asset.asset, asset);
    return (answer) => resultFor(answer, index, { no: no ?? answer.results[blockIndex]?.id,
      blockId: blockIndex === null ? blockRef : answer.results[blockIndex]?.id });
  }

  function updateElement(input) {
    const record = elementFor(input.id);
    if (record.arrowId) {
      if (has(input, 'type') || has(input, 'in') || has(input, 'target') || has(input, 'agent') || has(input, 'folder')) rejected(operation);
      const write = { op: 'arrow.update', id: record.arrowId };
      const properties = writeProperties(record.properties, input.props);
      for (const key of ['title', 'description', 'back']) if (has(input, key)) properties[key] = input[key];
      if (has(input, 'branch')) properties.branchKey = input.branch ?? 'flow';
      if (record.type === 'arrow' && (has(input, 'branch') || has(input, 'title'))) delete properties.branchLabel;
      if (has(input, 'title') || has(input, 'description') || has(input, 'back') || has(input, 'branch') || has(input, 'props')) {
        write.properties = record.properties = properties;
      }
      if (has(input, 'style')) write.appearance = record.appearance = changes(record.appearance, input.style);
      for (const side of ['from', 'to']) {
        if (!has(input, side)) continue;
        const end = elementFor(input[side], false);
        const source = (side === 'from') !== (record.direction === 'reverse');
        const field = source ? 'sourceId' : 'targetId';
        write[field] = record[field] = end.blockId;
        record[side] = end.id;
      }
      const index = Object.keys(write).length > 2 ? add(write) : null;
      return (answer) => elementResult(answer, index, record);
    }

    const block = { op: 'block.update', id: record.blockId };
    const place = { op: 'placement.update', id: placementId(record) };
    for (const field of ['title', 'description']) if (has(input, field)) block[field] = record[field] = input[field];
    if (has(input, 'type')) {
      if (['arrow', 'link', 'project', 'folder', 'scheme'].includes(input.type)) rejected(operation);
      block.kind = record.type = input.type;
    }
    if (has(input, 'props')) block.properties = record.properties = writeProperties(record.properties, input.props);
    if (has(input, 'folder')) {
      const target = folderFor(input.folder);
      place.parentId = record.folder = target.blockId;
    }
    if (has(input, 'style')) {
      const { placement, appearance } = styleParts(input.style);
      if (Object.keys(placement).length) place.placement = placement;
      const local = {};
      for (const key of Object.keys(appearance)) {
        if (localStyleKeys.has(key) || has(record.placementAppearance ?? {}, key)) {
          local[key] = appearance[key];
          delete appearance[key];
        }
      }
      if (Object.keys(local).length) place.appearance = record.placementAppearance = { ...record.placementAppearance, ...local };
      if (Object.keys(appearance).length) block.appearance = record.appearance = changes(record.appearance, appearance);
    }
    let index = Object.keys(block).length > 2 ? add(block) : null;
    if (Object.keys(place).length > 2) index = add(place);
    setLinks(record, input);
    return (answer) => elementResult(answer, index, record);
  }

  function deleteElement(input) {
    const id = positiveId(input.id);
    const prior = projected.element.get(input.id) ?? projected.element.get(id);
    if (prior?.deleted) return (answer) => ({ id: prior.id, no: prior.no, rev: answer.rev, deleted: true });
    const record = elementFor(input.id);
    let index;
    if (record.arrowId) {
      index = arrowDelete(record.arrowId);
    } else {
      for (const row of [...elements.values(), ...new Set(projected.element.values())]) {
        const meta = row._graph ?? row;
        if (!meta.arrowId || !(row.from === record.id || row.to === record.id
          || (record.id == null && [meta.sourceId, meta.targetId].includes(record.blockId)))) continue;
        if (row.folder !== record.folder) continue;
        arrowDelete(meta.arrowId);
        if (row.id != null) remember('element', row.id, { ...row, ...meta, deleted: true });
      }
      for (const member of listing(record.memberOf)) add({ op: 'placement.delete', id: member.placementId });
      index = add({ op: 'placement.delete', id: placementId(record) });
      if (record.no != null && positiveId(record.blockId)) detached.set(record.folder + ':' + record.no, record);
    }
    record.deleted = true;
    return (answer) => elementResult(answer, index, record, { deleted: true });
  }

  function writeFolder(input) {
    if (input.op === 'folder.create') {
      const blockId = temporary('folder');
      const parent = folderFor(input.parent);
      const properties = Object.fromEntries(folderSettings.filter((key) => has(input, key)).map((key) => [key, input[key]]));
      const appearance = changes({}, input.style);
      const index = add({ op: 'block.create', ref: blockId, kind: 'scheme', title: input.name ?? '', properties, appearance });
      const containing = temporary('placement');
      add({ op: 'placement.create', ref: containing, parentId: parent.blockId, blockId, ...(has(input, 'sort') ? { sort: input.sort } : {}) });
      remember('folder', input.ref, { blockId, placementId: containing, properties, appearance, folder: true });
      return (answer) => resultFor(answer, index, { folder_id: answer.results[index].id });
    }
    const record = folderFor(input.id);
    if (record.blockId === root) rejected(operation);
    if (input.op === 'folder.delete') {
      const index = add({ op: 'placement.delete', id: placementId(record) });
      return (answer) => resultFor(answer, index, { id: record.id, deleted: 1 });
    }
    const block = { op: 'block.update', id: record.blockId };
    if (has(input, 'name')) block.title = input.name;
    if (has(input, 'style')) block.appearance = record.appearance = changes(record.appearance, input.style);
    const settings = Object.fromEntries(folderSettings.filter((key) => has(input, key)).map((key) => [key, input[key]]));
    if (Object.keys(settings).length) block.properties = record.properties = { ...record.properties, ...settings };
    let index = Object.keys(block).length > 2 ? add(block) : null;
    if (has(input, 'parent') || has(input, 'sort')) {
      const write = { op: 'placement.update', id: placementId(record) };
      if (has(input, 'parent')) write.parentId = folderFor(input.parent).blockId;
      if (has(input, 'sort')) write.sort = input.sort;
      index = add(write);
    }
    remember('folder', input.id, record);
    return (answer) => resultFor(answer, index, { id: record.id });
  }

  function materialId(value) {
    const id = positiveId(value);
    const record = projected.material.get(value) ?? assets.get(id);
    return record?._graph?.materialId ?? record?.materialId ?? record?.asset ?? id ?? rejected(operation, true);
  }

  function materialOwner(input) {
    if (input.element != null) return elementFor(input.element, false).blockId;
    return folderFor(input.folder ?? snapshot.folder?.id ?? root).blockId;
  }

  function attachMaterial(block, asset, input) {
    const role = input.role ?? 'attachment';
    if (role === 'result' || role === 'preview') rejected(operation);
    return add({ op: 'graph.asset.add', block, material: materialId(asset), role,
      ...(has(input, 'output') ? { output: input.output } : {}), ...(has(input, 'sort') ? { sort: input.sort } : {}) });
  }

  function editableMaterialLink(row) {
    return (row?._graph?.arrowId ?? row?.arrowId ?? row?.link) && !row.fromRun && !row.madeByRun
      && !row._graph?.properties?.madeByRun && !['result', 'preview'].includes(row.role);
  }

  function materialLink(input) {
    const asked = positiveId(input.id);
    const row = asked && assetLinks.find((entry) => Number(entry._graph?.arrowId ?? entry.arrowId ?? entry.link) === asked);
    if (!editableMaterialLink(row)) rejected(operation, true);
    return row;
  }

  function updateAssetRole(row, role) {
    if (!['attachment', 'cover', 'spec', 'input', 'reference', 'code'].includes(role)) rejected(operation);
    const id = row._graph?.arrowId ?? row.arrowId ?? row.link;
    return add({ op: 'arrow.update', id, properties: { ...row._graph?.properties,
      relation: 'asset', role, output: row.output ?? '' } });
  }

  function webUrl(value) {
    if (typeof value !== 'string' || !/^https?:\/\/[^\s]+$/i.test(value)) rejected(operation);
    let url;
    try { url = new URL(value); } catch { rejected(operation); }
    if (!url.hostname || url.username || url.password) rejected(operation);
    return value;
  }

  function writeAsset(input) {
    let index = null;
    if (input.op === 'asset.create') {
      const ref = temporary('material');
      const material = { materialId: ref };
      remember('material', ref, material);
      const title = input.title ?? '';
      const link = input.link;
      const owner = link ? materialOwner(link) : null;
      if (typeof input.text === 'string' && !input.uri) {
        if (link) {
          const role = link.role ?? 'attachment';
          if (role === 'result' || role === 'preview') rejected(operation);
          index = add({ op: 'graph.asset.add', ref, block: owner, role,
            text: input.text, title, ...(has(link, 'output') ? { output: link.output } : {}) });
        } else {
          index = add({ op: 'block.create', ref, kind: 'asset', title,
            properties: { kind: 'text', body: input.text, mime: input.mime ?? 'text/markdown' } });
        }
      } else if (input.kind === 'link' && input.uri && !has(input, 'text')) {
        index = add({ op: 'block.create', ref, kind: 'asset', title,
          properties: { kind: 'link', uri: webUrl(input.uri) } });
        if (link) attachMaterial(owner, ref, link);
      } else rejected(operation);
      remember('material', input.ref, material);
      return (answer) => resultFor(answer, index);
    }
    if (input.op === 'asset.link') {
      index = attachMaterial(materialOwner(input), input.asset, input);
      return (answer) => resultFor(answer, index, { id: answer.results[index].arrowId, asset: answer.results[index].materialId });
    }
    if (input.op === 'asset.unlink' && input.asset && input.folder) {
      const matching = assetLinks.filter((row) => Number(row.asset) === Number(input.asset)
        && Number(row.folder) === Number(input.folder) && editableMaterialLink(row));
      if (!matching.length) rejected(operation, true);
      for (const row of matching) index = arrowDelete(row._graph?.arrowId ?? row.arrowId ?? row.link);
      return (answer) => resultFor(answer, index, { id: positiveId(input.asset), count: matching.length });
    }
    if (input.op === 'asset.role' || input.op === 'asset.unlink') {
      const row = materialLink(input);
      const id = row._graph?.arrowId ?? row.arrowId ?? row.link;
      if (input.op === 'asset.unlink') index = arrowDelete(id);
      else {
        if (row.ownerId && ['cover', 'spec'].includes(input.role)) {
          for (const sibling of assetLinks) {
            if (sibling.ownerId === row.ownerId && sibling.role === input.role && sibling.link !== row.link
                && editableMaterialLink(sibling)) updateAssetRole(sibling, 'attachment');
          }
        }
        index = updateAssetRole(row, input.role);
      }
      return (answer) => resultFor(answer, index, { id });
    }
    if (input.op === 'asset.update') {
      const id = materialId(input.id);
      const record = assets.get(positiveId(input.id));
      if (has(input, 'kind') || has(input, 'mime')) rejected(operation);
      const write = { op: 'block.update', id };
      if (has(input, 'title')) write.title = input.title;
      if (has(input, 'text') || has(input, 'uri')) {
        if (!record?._graph?.materialProperties) rejected(operation, true);
        write.properties = { ...record._graph.materialProperties };
        if (has(input, 'uri')) {
          if (record.text && !has(input, 'text') && !has(write.properties, 'body')) rejected(operation, true);
          if ((write.properties.kind ?? record.kind) !== 'link') rejected(operation);
          write.properties.uri = webUrl(input.uri);
        }
        if (has(input, 'text')) write.properties.body = input.text;
      }
      index = Object.keys(write).length > 2 ? add(write) : null;
      return (answer) => resultFor(answer, index, { id });
    }
    return rejected(operation);
  }

  function writeAgent(input) {
    const existing = input.id == null ? null : projected.agent.get(input.id) ?? projected.agent.get(positiveId(input.id)) ?? agents.get(positiveId(input.id));
    if (input.id != null && !existing) rejected(operation, true);
    const record = existing ? { ...existing, ...existing._graph } : {};
    const id = record.blockId ?? temporary('agent');
    const patch = Object.fromEntries(['cli', 'model', 'effort', 'permission', 'sandbox', 'closeAfter']
      .filter((key) => has(input, key)).map((key) => [key, input[key]]));
    const write = existing ? { op: 'block.update', id } : { op: 'block.create', ref: id, kind: 'agent' };
    if (has(input, 'name')) write.title = input.name;
    if (Object.keys(patch).length) write.properties = record.properties = changes(record.properties, patch);
    if (has(input, 'style')) write.appearance = record.appearance = changes(record.appearance, input.style);
    const index = Object.keys(write).length > 2 ? add(write) : null;
    if (!existing) add({ op: 'placement.create', parentId: root, blockId: id });
    const role = input.role ?? record.role ?? 'worker';
    if (!existing || has(input, 'role')) {
      if (typeof role !== 'string' || !role) rejected(operation);
      if (!roles.has(role)) {
        const reference = temporary('role');
        add({ op: 'block.create', ref: reference, kind: 'role', title: role, properties: { role } });
        add({ op: 'placement.create', parentId: root, blockId: reference });
        roles.set(role, reference);
      }
      const target = roles.get(role);
      if (target !== record.roleLink?.blockId) {
        if (record.roleLink?.arrowId) arrowDelete(record.roleLink.arrowId);
        const ref = temporary('agent-role');
        add({ op: 'arrow.create', ref, kind: 'link', sourceId: id, targetId: target, properties: { relation: 'role' } });
        record.roleLink = { arrowId: ref, blockId: target };
      }
      record.role = role;
    }
    remember('agent', input.ref ?? input.id, { ...record, blockId: id });
    return (answer) => resultFor(answer, index, { id: acknowledged(id, answer) });
  }

  function writeOne(input) {
    operation = input.op;
    switch (operation) {
      case 'element.create': case 'element.restore': return createElement(input);
      case 'element.update': return updateElement(input);
      case 'element.delete': return deleteElement(input);
      case 'folder.create': case 'folder.update': case 'folder.delete': return writeFolder(input);
      case 'asset.create': case 'asset.update': case 'asset.link': case 'asset.role': case 'asset.unlink': return writeAsset(input);
      case 'agent.save': return writeAgent(input);
      case 'project.update': {
        const index = add(input);
        if (has(input, 'title')) add({ op: 'block.update', id: root, title: input.title });
        return (answer) => resultFor(answer, index);
      }
      case 'project.delete': {
        const index = add(input);
        return (answer) => resultFor(answer, index);
      }
      case 'template.apply': {
        if (input.folder != null) rejected(operation);
        const index = add({ op: 'graph.template.apply', template: input.template,
          parent: folderFor(input.parent).blockId, ...(has(input, 'name') ? { name: input.name } : {}) });
        return (answer) => resultFor(answer, index, { folder: answer.results[index].id });
      }
      default: return rejected(operation);
    }
  }

  for (const input of operations) results.push(writeOne(input));
  return {
    ops: native,
    map(answer) {
      if (!Array.isArray(answer.results) || answer.results.length !== native.length) rejected('results', true);
      const mapped = results.map((read) => read(answer));
      if (snapshot.detached instanceof Map) {
        for (const key of originalDetached.keys()) if (!detached.has(key)) snapshot.detached.delete(key);
        for (const [key, value] of detached) {
          if (originalDetached.get(key) !== value) snapshot.detached.set(key, value);
        }
      }
      return { ...answer, results: mapped };
    },
  };
}
