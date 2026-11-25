import { t } from 'goblin/core/i18n.js';

const directions = { forward: '→', reverse: '←', both: '↔', none: '—' };
const arrowType = { flow: 'editor.graph.flow', link: 'editor.graph.link', contains: 'editor.graph.add_placement' };

function refuse(key = 'editor.api.refused', code = 'not_found') {
  throw Object.assign(new Error(t(key)), { ok: false, code });
}

function previewOf(ops) {
  const names = new Map(ops.filter(op => op.op === 'block.create').map(op => [op.ref, op.title]));
  const name = id => typeof id === 'number' ? `#${id}` : names.get(id) || id;
  return ops.map(op => {
    switch (op.op) {
      case 'block.create':
        return [t('editor.graph.create'), t(op.kind === 'folder' ? 'editor.linksview.folder' : `editor.kinds.${op.kind}`),
          op.title, op.description].filter(Boolean).join(' · ');
      case 'block.update': {
        const title = typeof op.title === 'string' ? `${t('editor.asset.name')}: ${op.title || '—'}` : '';
        const description = typeof op.description === 'string' ? `${t('editor.panel.description')}: ${op.description || '—'}` : '';
        return [t('editor.mobile.tab_block') + ' ' + name(op.id), title, description].filter(Boolean).join(' · ');
      }
      case 'arrow.create': {
        const path = [name(op.sourceId), directions[op.direction] ?? '→', name(op.targetId)].join(' ');
        const { placement, branchLabel, branchKey, title } = op.properties || {};
        const point = placement?.x != null || placement?.y != null ? `(${placement.x ?? '–'}, ${placement.y ?? '–'})` : '';
        const size = placement?.width != null || placement?.height != null ? `${placement.width ?? '–'} × ${placement.height ?? '–'}` : '';
        return [t(arrowType[op.kind]), path, branchLabel ?? title, branchKey, point, size].filter(Boolean).join(' · ');
      }
      case 'arrow.delete':
        return `${t('common.delete')} · ${t('editor.kinds.arrow')} #${op.id}`;
      default:
        return refuse('editor.graph.action_failed', 'invalid_proposal');
    }
  });
}

/** The original chat polls these temporary conversations in its active scene. */
export function mountCanonicalAssistant({ post, currentFolder }) {
  const chats = new Map();
  let nextId = 0;

  function scope(fields) {
    const parent = Number(currentFolder());
    if (!fields.project || !Number.isSafeInteger(parent) || parent < 1 || (fields.folder != null && Number(fields.folder) !== parent)) {
      refuse('editor.settings.open_folder_first');
    }
    return { project: fields.project, parent };
  }

  function belongs(chat, scene) {
    return chat?.project === scene.project && chat.parent === scene.parent;
  }

  function findChat(id, scene) {
    const chat = chats.get(Number(id));
    if (!belongs(chat, scene)) refuse();
    return chat;
  }

  function read(fields) {
    const scene = scope(fields);
    if (!fields.chat) {
      const list = [...chats.values()].filter(chat => belongs(chat, scene)).sort((a, b) => b.job.id - a.job.id);
      return { ok: true, chats: list.map(chat => chat.summary) };
    }
    const { summary, messages, job } = findChat(fields.chat, scene);
    const { id, state, preview, error } = job;
    return { ok: true, chat: summary, messages: [...messages], jobs: [{ id, state, preview, error, kind: 'reply' }] };
  }

  async function propose(chat, job, scene, text) {
    try {
      const answer = await post('ai.graph.propose', { ...scene, text, history: chat.history.slice(-8) });
      if (job.state !== 'running') return;
      if (answer.parentId !== scene.parent || !Array.isArray(answer.ops) || typeof answer.message !== 'string') {
        refuse('editor.graph.action_failed', 'invalid_proposal');
      }
      if (answer.ops.length) {
        const { proposal, opId, opScope, ifRev } = answer.approval || {};
        if (![proposal, opId, opScope].every(value => typeof value === 'string' && value)
            || !Number.isSafeInteger(ifRev) || ifRev < 0 || ifRev !== answer.rev) {
          refuse('editor.graph.action_failed', 'invalid_proposal');
        }
        job.preview = previewOf(answer.ops);
        job.approval = { proposal, opId, opScope, ifRev };
      }
      if (answer.message) chat.messages.push({ role: 'assistant', body: answer.message });
      chat.history.push({ role: 'user', content: text });
      if (answer.message) chat.history.push({ role: 'assistant', content: answer.message });
      chat.history = chat.history.slice(-8);
      job.state = job.approval ? 'proposal' : 'done';
    } catch (error) {
      if (job.state !== 'running') return;
      job.state = 'failed';
      job.error = error.message || t('editor.graph.action_failed');
    }
  }

  function send(fields, scene) {
    const text = typeof fields.text === 'string' ? fields.text.trim() : '';
    if (!text || [...text].length > 4000) refuse('editor.ai.ask_first', 'invalid_question');
    if ([...chats.values()].some(chat => belongs(chat, scene) && (chat.job.state === 'running' || chat.job.applying))) {
      refuse('editor.ai.thinking', 'conflict');
    }
    const chat = fields.chat ? findChat(fields.chat, scene) : {
      ...scene, messages: [], history: [], summary: { id: ++nextId, title: [...text].slice(0, 60).join(''), state: 'open' },
    };
    chat.summary.at = new Date().toISOString();
    chat.messages.push({ role: 'user', body: text });
    chats.set(chat.summary.id, chat);
    const job = { id: ++nextId, state: 'running' };
    chat.job = job;
    void propose(chat, job, scene, text);
    return { ok: true, chat: chat.summary.id, job: job.id };
  }

  async function action(op, fields) {
    const scene = scope(fields);
    if (op === 'ai.chat.send') return send(fields, scene);
    if (op === 'ai.chat.delete') {
      const chat = findChat(fields.chat, scene);
      if (chat.job.applying) refuse('editor.graph.saving', 'conflict');
      chat.job.state = 'cancelled';
      chats.delete(chat.summary.id);
      return { ok: true, chat: chat.summary.id, deleted: true };
    }

    const id = Number(fields.job);
    const job = [...chats.values()].find(chat => belongs(chat, scene) && chat.job.id === id)?.job;
    if (!job) refuse();
    if (job.applying) refuse('editor.graph.saving', 'conflict');
    if (op === 'ai.chat.cancel') {
      if (['running', 'proposal'].includes(job.state)) {
        job.state = 'cancelled';
        delete job.approval;
      }
      return { ok: true, job: id, cancelled: true };
    }
    if (op !== 'ai.chat.apply' || job.state !== 'proposal' || !job.approval) refuse();
    job.applying = true;
    try {
      const answer = await post('ai.graph.apply', { project: scene.project, ...job.approval, confirm: true });
      job.state = 'applied';
      delete job.approval;
      return answer;
    } catch (error) {
      if (error.code) {
        job.state = 'failed';
        job.error = error.message;
        delete job.approval;
      }
      throw error;
    } finally {
      job.applying = false;
    }
  }

  return { read, action };
}
