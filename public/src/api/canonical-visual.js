/* Исходные скины читают проекцию графа. Любая правка уходит в API графа. */
import { state } from 'goblin/core/state.js';
import { t } from 'goblin/core/i18n.js';
import { prepareCanonicalWrite } from 'goblin/api/canonical-write.js';
import { mountCanonicalAssistant } from 'goblin/api/canonical-assistant.js';

function refuse(code = 'visual_unsupported') {
  const message = code === 'visual_unsupported' ? t('editor.api.visual_unsupported') : t('editor.api.refused');
  throw Object.assign(new Error(message), { ok: false, code, visual: true });
}

function asParent({ folder, ...fields }) {
  return folder === undefined ? fields : { ...fields, parent: folder };
}

function visualRun(run, folder) {
  if (!run) return run;
  return { ...run, folder: run.parent ?? folder ?? run.folder };
}

function materialView(row, project) {
  const id = row.materialId ?? row.legacyAssetId;
  const file = row.materialId ? { material: row.materialId } : { asset: row.legacyAssetId };
  return {
    id, asset: id, link: row.arrowId ?? row.legacyLinkId, material: row.materialId,
    kind: row.kind, title: row.title, mime: row.mime, name: row.name, originalName: row.name,
    role: row.role, output: row.output, body: row.text, text: row.text, uri: row.url,
    fromRun: row.madeByRun, bytes: row.bytes, missing: row.missing, agent: row.agent,
    fileUrl: row.file ? 'api.php?' + new URLSearchParams({ op: 'graph.asset.file', project, ...file }) : null,
  };
}

export function mountCanonicalVisual({ get, post, batch, upload, projectKey }) {
  const models = new Map();
  const attempts = new Map();
  const assistant = mountCanonicalAssistant({ post, currentFolder: () => state.folder?.id });

  function hydrateAssets(owner, project) {
    for (const asset of owner?.assets ?? []) {
      const material = asset._graph?.materialId;
      if (!material || asset.uri || (!asset.file && asset.kind !== 'image')) continue;
      asset.fileUrl = 'api.php?' + new URLSearchParams({ op: 'graph.asset.file', project, material });
    }
  }

  function model(key) {
    if (!models.has(key)) models.set(key, { rootId: null, folders: [], agents: [], scenes: new Map(), detached: new Map() });
    return models.get(key);
  }

  async function projectRead(fields, quiet) {
    const data = await get('graph.visual.project', fields, quiet);
    if (!fields.project) return data;
    const stored = model(fields.project);
    Object.assign(stored, { rootId: data.rootId, folders: data.folders, agents: data.agents ?? [], roles: data.roles ?? [] });
    return data;
  }

  async function sceneRead({ folder, legacyFolder, ...fields }, quiet) {
    const location = legacyFolder ? { legacyFolder } : { parent: folder };
    const data = await get('graph.visual.scene', { ...fields, ...location }, quiet);
    hydrateAssets(data.folder, fields.project);
    for (const element of data.scheme) hydrateAssets(element, fields.project);
    const stored = model(fields.project);
    if (data.folder) stored.scenes.set(data.folder.id, data);
    stored.rootId ??= data.rootId;
    return data;
  }

  async function changes(fields, quiet) {
    const { project, since } = fields;
    const stored = model(project);
    const { rev } = await get('graph.rev', { project }, quiet);
    if (rev <= since && !stored.dirty) return { ok: true, rev, changed: {} };
    const folder = state.folder?.id;
    const remembered = model(project).scenes.get(folder);
    const before = new Set(remembered?.scheme.map(element => element.id) ?? []);
    const updated = await projectRead({ project }, quiet);
    const scene = folder && await sceneRead({ project, folder }, quiet).catch(error => {
      if (error.code !== 'not_found') throw error;
      return null;
    });
    // Применяем снимки одной ревизии. Если параллельно пришла запись, опрос повторится.
    if (scene && scene.rev !== updated.project.rev) return { ok: true, rev: since, changed: {} };
    stored.dirty = false;
    const elements = scene?.scheme ?? [];
    for (const element of elements) before.delete(element.id);
    return {
      ok: true, rev: updated.project.rev,
      changed: { project: updated.project, folders: updated.folders, elements, agents: updated.agents, runs: true, visual: true },
      deleted: [...before].map(id => ({ entity: 'element', id })),
    };
  }

  function classicElement(project, id) {
    for (const scene of model(project).scenes.values()) {
      const element = scene.scheme.find(one => one.id === Number(id));
      if (element) return element;
    }
    refuse('not_found');
  }

  async function elementAssets(project, element, { role, text }, quiet) {
    const { blockId, arrowId } = element._graph;
    const owner = blockId ? { block: blockId } : { arrow: arrowId };
    const { assets } = await get('graph.asset.list', { project, ...owner, text }, quiet);
    return assets.filter(row => !role || row.role === role).map(row => materialView(row, project));
  }

  async function read(op, params, quiet) {
    const fields = { ...params, project: params.project ?? projectKey() };
    const { project, folder, run } = fields;
    switch (op) {
      case 'project.get':
        return projectRead(fields, quiet);
      case 'folder.get':
        return sceneRead(fields, quiet);
      case 'changes':
        return changes(fields, quiet);
      case 'ai.chat.get':
        return assistant.read(fields);
      case 'run.get': {
        const answer = await get(op, asParent(fields), quiet);
        if (answer.runs) answer.runs = answer.runs.map(item => visualRun(item));
        if (answer.run) {
          const owner = model(project).scenes.get(state.folder?.id)?.folder;
          const legacy = owner?._graph?.legacyFolder;
          answer.run = visualRun(answer.run, !answer.run.parent && legacy === answer.run.folder ? owner.id : undefined);
        }
        if (folder && !run) {
          const legacy = model(project).scenes.get(folder)?.folder._graph?.legacyFolder;
          if (legacy) {
            const past = await get(op, { project, folder: legacy }, quiet);
            answer.runs.push(...past.runs.map(item => visualRun(item, folder)));
            answer.runs.sort((a, b) => b.id - a.id);
          }
        }
        return answer;
      }
      case 'run.check':
      case 'folder.embed':
      case 'asset.workfiles':
        return get(op, asParent(fields), quiet);
      case 'run.state': {
        const answer = await get(op, fields, quiet);
        answer.run = visualRun(answer.run);
        return answer;
      }
      case 'run.paint': {
        const answer = await get(op, fields, quiet);
        if (state.run?.parent) {
          const scene = model(project).scenes.get(state.run.parent);
          const placements = new Map(scene?.scheme.filter(e => e._graph?.blockId)
            .map(e => [e._graph.blockId, e.id]));
          answer.blocks = answer.blocks.flatMap(block => placements.has(block.id)
            ? [{ ...block, id: placements.get(block.id) }] : []);
        }
        return answer;
      }
      case 'element.get': {
        const element = classicElement(project, fields.element ?? fields.id);
        const assets = await elementAssets(project, element, { text: 1 }, quiet);
        return { ok: true, element: { ...element, assets } };
      }
      case 'asset.get': {
        if (fields.element !== undefined) {
          const element = classicElement(project, fields.element);
          const assets = await elementAssets(project, element, fields, quiet);
          return { ok: true, assets };
        }
        const id = fields.asset ?? fields.id;
        const { block } = await get('block.get', { project, id }, quiet);
        if (!['asset', 'material'].includes(block.kind)) refuse('not_found');
        const props = block.properties || {};
        return { ok: true, asset: materialView({
          materialId: block.id, kind: props.kind, title: block.title, mime: props.mime,
          name: props.originalName, text: props.body, file: true,
        }, project) };
      }
      default:
        return get(op, fields, quiet);
    }
  }

  async function write(ops, extra, keepalive) {
    const project = extra.project ?? projectKey();
    const opId = extra.opId;
    const key = opId && project + ':' + opId;
    const stored = model(project);
    let request = key && attempts.get(key);
    if (!request) {
      const requestedFolder = ops.find(op => op.folder)?.folder ?? (state.project?.key === project ? state.folder?.id : null);
      if (stored.dirty) {
        await projectRead({ project });
        if (requestedFolder) await sceneRead({ project, folder: requestedFolder }, ['not_found']).catch(error => {
          if (error.code !== 'not_found') throw error;
          stored.scenes.delete(requestedFolder);
        });
      }
      const scene = stored.scenes.get(requestedFolder);
      const elements = new Map();
      for (const snapshot of stored.scenes.values()) for (const element of snapshot.scheme) elements.set(element.id, element);
      const prepared = prepareCanonicalWrite(ops, {
        rootId: stored.rootId, folder: scene?.folder ?? stored.folders.find(folder => folder.id === requestedFolder),
        folders: stored.folders, agents: stored.agents, roles: stored.roles, elements, detached: stored.detached,
      });
      const rev = state.project?.key === project ? state.rev : scene?.rev;
      request = { prepared, fields: { project, opId, ifRev: extra.ifRev ?? rev } };
      if (key) attempts.set(key, request);
    }
    try {
      const answer = await batch(request.prepared.ops, request.fields, keepalive);
      if (key) attempts.delete(key);
      const mapped = request.prepared.map(answer);
      // Следующий опрос перечитает серверный результат, даже когда ревизию
      // уже продвинул обработчик очереди старого интерфейса.
      if (mapped.rev !== undefined) stored.dirty = true;
      return mapped;
    } catch (error) {
      if (key && error.ok === false) attempts.delete(key);
      throw error;
    }
  }

  async function action(op, fields) {
    const project = fields.project ?? projectKey();
    switch (op) {
      case 'run.start':
      case 'run.prepare':
      case 'folder.open':
        return post(op, asParent({ ...fields, project }));
      case 'ai.chat.send':
      case 'ai.chat.apply':
      case 'ai.chat.cancel':
      case 'ai.chat.delete':
        return assistant.action(op, { ...fields, project });
      case 'folder.transfer':
      case 'asset.workfile.rename':
      case 'asset.workfile.delete':
      case 'template.save':
      case 'ai.build.start':
      case 'ai.build.answer':
      case 'ai.build.create':
      case 'ai.play':
        refuse();
      default:
        if (/^(element|folder|asset|agent|project)\.(create|update|delete|restore|save|detach|link|role|unlink)$/.test(op)
            || op === 'template.apply') {
          const { results, ...answer } = await write([{ op, ...fields }], { project, opId: fields.opId }, false);
          return { ...answer, ...results[0] };
        }
        return post(op, { ...fields, project });
    }
  }

  return {
    get: read, post: action, batch: write,
    async upload(file, link, folder) {
      const project = projectKey();
      const block = link?.element ? classicElement(project, link.element)._graph.blockId : link?.folder ?? folder;
      if (!block) refuse();
      const answer = await upload(file, { project, block, role: link?.role ?? 'attachment' });
      model(project).dirty = true;
      return answer;
    },
  };
}
