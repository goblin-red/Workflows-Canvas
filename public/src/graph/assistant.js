import { post } from 'goblin/api/client.js';
import { mountAiAgent } from 'goblin/shell/ai-agent.js';

const words = {
  heading: 'Assistant', question: 'Your question', ask: 'Suggest changes',
  apply: 'Apply changes', discard: 'Discard', updates: 'Proposed changes',
  unavailable: 'Open a scene to ask the assistant.', changed: 'Scene changed. Ask a new question.',
  asking: 'Waiting for the assistant…', applying: 'Applying changes…', applied: 'Changes applied.',
  discarded: 'Proposal discarded.', noChanges: 'No changes proposed.',
  partial: 'The assistant received part of the scene.', invalid: 'The assistant returned an unreadable proposal.',
  stale: 'This proposal is out of date. Ask for a new one.',
  failed: 'The assistant could not answer.', applyFailed: 'Could not confirm the save. You can retry this proposal.',
  refreshFailed: 'Changes applied. Could not refresh the scene.',
  add: 'Create', edit: 'Edit block', place: 'Place in scene', remove: 'Remove arrow',
  flow: 'Flow', link: 'Link', title: 'Title', description: 'Description', branch: 'Branch',
  position: 'Position', size: 'Size', you: 'You',
  kinds: { block: 'block', decision: 'decision', gateway: 'gateway', group: 'group',
    area: 'area', note: 'note', table: 'table', folder: 'folder' },
};

const direction = { forward: '→', reverse: '←', both: '↔', none: '—' };

function element(tag, className = '', text = '') {
  const node = document.createElement(tag);
  node.className = className;
  node.textContent = text;
  return node;
}

function button(text, className, type = 'button') {
  const node = element('button', className, text);
  node.type = type;
  return node;
}

function sceneOf(value) {
  const parent = typeof value?.parent === 'string' && /^\d+$/.test(value.parent) ? Number(value.parent) : value?.parent;
  return typeof value?.project === 'string' && value.project && Number.isSafeInteger(parent) && parent > 0
    ? { project: value.project, parent }
    : null;
}

function operationLabel(op, refs) {
  const name = (id) => typeof id === 'number' ? '#' + id : refs.get(id) || id;
  const label = (value) => typeof value === 'string' ? value : '';
  const detail = [];
  let heading;
  switch (op?.op) {
    case 'block.create':
      heading = [words.add, words.kinds[op.kind] || op.kind, op.title].filter(Boolean).join(' ');
      if (op.description) detail.push(op.description);
      break;
    case 'block.update':
      heading = words.edit + ' ' + name(op.id);
      if (typeof op.title === 'string') detail.push(words.title + ': ' + op.title);
      if (typeof op.description === 'string') detail.push(words.description + ': ' + op.description);
      break;
    case 'arrow.create': {
      const source = name(op.sourceId);
      const target = name(op.targetId);
      if (op.kind === 'contains') {
        heading = words.place + ' ' + source + ': ' + target;
        const { x, y, width, height } = op.properties?.placement || {};
        if (x != null || y != null) detail.push(words.position + ': ' + [x ?? '–', y ?? '–'].join(', '));
        if (width != null || height != null) detail.push(words.size + ': ' + [width ?? '–', height ?? '–'].join(' × '));
      } else if (op.kind === 'flow' || op.kind === 'link') {
        heading = (op.kind === 'flow' ? words.flow : words.link) + ': ' + [source, direction[op.direction], target].join(' ');
        const text = label(op.properties?.branchLabel ?? op.properties?.title);
        if (text) detail.push(text);
        if (op.properties?.branchKey) detail.push(words.branch + ': ' + op.properties.branchKey);
      }
      break;
    }
    case 'arrow.delete':
      heading = words.remove + ' #' + op.id;
      break;
  }
  if (!heading) throw new Error(words.invalid);
  const row = element('li', 'graph-assistant-op', heading);
  if (detail.length) row.append(element('small', 'graph-assistant-detail', detail.join('\n')));
  return row;
}

function proposalOf(answer, context) {
  if (answer.parentId !== context.parent || !Array.isArray(answer.ops) || typeof answer.message !== 'string') {
    throw new Error(words.invalid);
  }
  const refs = new Map(answer.ops.filter((op) => op?.op === 'block.create')
    .map((op) => [op.ref, op.title ? '«' + op.title + '»' : op.ref]));
  const rows = answer.ops.map((op) => operationLabel(op, refs));
  if (!rows.length) return { rows, approval: null };
  const { proposal, ifRev, opId, opScope } = answer.approval || {};
  if (!proposal || typeof proposal !== 'string' || !opId || typeof opId !== 'string'
    || !opScope || typeof opScope !== 'string' || !Number.isSafeInteger(ifRev) || ifRev < 0 || ifRev !== answer.rev) {
    throw new Error(words.invalid);
  }
  return { rows, approval: { proposal, ifRev, opId, opScope } };
}

/**
 * getContext returns { project, parent } for a ready scene or null. Call refresh()
 * on every navigation. onApplied(answer, context) also identifies saves begun before navigation.
 */
export function mountGraphAssistant(host, { getContext, onApplied, onError, readOnly = false } = {}) {
  let context = null;
  let generation = 0;
  let busy = false;
  let proposal = null;
  let destroyed = false;
  let history = [];

  const panel = element('section', 'graph-assistant');
  panel.setAttribute('aria-label', words.heading);
  const provider = element('div');
  const agent = readOnly ? null : mountAiAgent(provider, { getTarget: () => {
    const current = getContext?.();
    return current?.projectRoot ? null : sceneOf(current);
  } });
  const form = element('form', 'graph-assistant-form');
  const field = element('label', 'field');
  const text = element('textarea', 'input');
  text.name = 'question';
  text.rows = 3;
  text.maxLength = 4000;
  text.required = true;
  field.append(element('span', '', words.question), text);
  const ask = button(words.ask, 'btn btn-accent', 'submit');
  form.append(field, ask);

  const status = element('p', 'graph-assistant-status', words.unavailable);
  status.setAttribute('role', 'status');
  const errorBox = element('p', 'graph-assistant-error');
  errorBox.setAttribute('role', 'alert');
  errorBox.hidden = true;
  const conversation = element('ol', 'graph-assistant-history');
  conversation.hidden = true;
  const partial = element('p', 'graph-assistant-status', words.partial);
  partial.hidden = true;
  const review = element('div', 'graph-assistant-review');
  const rows = element('ol', 'graph-assistant-ops');
  const actions = element('div', 'graph-assistant-actions');
  const confirm = button(words.apply, 'btn btn-accent');
  const discard = button(words.discard, 'btn btn-quiet');
  actions.append(confirm, discard);
  review.append(element('h3', 'panel-title', words.updates), rows, actions);
  panel.append(element('h2', 'panel-title graph-assistant-heading', words.heading));
  if (agent) panel.append(provider);
  panel.append(conversation, form, status, errorBox, partial, review);
  host.replaceChildren(panel);

  function controls() {
    const ready = context !== null && !busy;
    text.disabled = !ready;
    ask.disabled = !ready || !text.value.trim();
    confirm.disabled = !ready || !proposal;
    discard.disabled = !ready || !proposal;
    review.hidden = !proposal;
    panel.setAttribute('aria-busy', String(busy));
  }

  function showHistory() {
    conversation.replaceChildren(...history.map(({ role, content }) => {
      const row = element('li', 'graph-assistant-turn');
      row.append(element('small', 'muted', role === 'user' ? words.you : words.heading),
        element('p', 'graph-assistant-answer', content));
      return row;
    }));
    conversation.hidden = history.length === 0;
    conversation.scrollTop = conversation.scrollHeight;
  }

  function error(value = '') {
    errorBox.textContent = value;
    errorBox.hidden = !value;
  }

  function clear() {
    proposal = null;
    rows.replaceChildren();
    error();
    partial.hidden = true;
  }

  function refresh() {
    if (destroyed) return;
    const next = sceneOf(getContext?.());
    if (next?.project !== context?.project || next?.parent !== context?.parent) {
      const hadWork = Boolean(text.value || history.length || proposal || busy);
      context = next;
      ++generation;
      busy = false;
      clear();
      history = [];
      showHistory();
      text.value = '';
      status.textContent = next ? (hadWork ? words.changed : '') : words.unavailable;
    }
    controls();
  }

  function isCurrent(request) {
    refresh();
    return !destroyed && request.generation === generation;
  }

  function start(label) {
    const request = { context: { ...context }, generation: ++generation };
    busy = true;
    status.textContent = label;
    error();
    controls();
    return request;
  }

  function failed(request, cause, label) {
    if (!isCurrent(request)) return;
    status.textContent = '';
    error(label);
    onError?.(cause, request.context);
  }

  function finish(request) {
    if (isCurrent(request)) {
      busy = false;
      controls();
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    refresh();
    const question = text.value.trim();
    if (destroyed || !context || busy || !question) return;
    clear();
    const request = start(words.asking);
    try {
      const params = { ...request.context, text: question };
      if (history.length) params.history = history.map(({ role, content }) => ({ role, content: content.slice(0, 4000) }));
      const response = await post('ai.graph.propose', params);
      if (!isCurrent(request)) return;
      const draft = proposalOf(response, request.context);
      proposal = draft.approval;
      rows.replaceChildren(...draft.rows);
      history.push({ role: 'user', content: question });
      if (response.message) history.push({ role: 'assistant', content: response.message });
      history = history.slice(-8);
      showHistory();
      partial.hidden = response.truncated !== true;
      status.textContent = proposal ? '' : words.noChanges;
    } catch (cause) {
      const label = cause.code || cause.message === words.invalid ? cause.message || words.failed : words.failed;
      failed(request, cause, label);
    } finally {
      finish(request);
    }
  });

  confirm.addEventListener('click', async () => {
    refresh();
    if (destroyed || busy || !context || !proposal) return;
    const approval = proposal;
    const request = start(words.applying);
    try {
      const response = await post('ai.graph.apply', { project: request.context.project, ...approval, confirm: true });
      if (isCurrent(request)) {
        proposal = null;
        rows.replaceChildren();
        status.textContent = words.applied;
      }
      try {
        if (!destroyed) await onApplied?.(response, request.context);
      } catch (cause) {
        failed(request, cause, words.refreshFailed);
      }
    } catch (cause) {
      if (!isCurrent(request)) return;
      if (cause.code) {
        proposal = null;
        rows.replaceChildren();
      }
      const label = cause.code === 'conflict' ? words.stale : (cause.code && cause.message) || words.applyFailed;
      failed(request, cause, label);
    } finally {
      finish(request);
    }
  });

  discard.addEventListener('click', () => {
    refresh();
    if (!proposal || busy) return;
    proposal = null;
    rows.replaceChildren();
    status.textContent = words.discarded;
    controls();
  });

  text.addEventListener('input', () => {
    if (proposal || !errorBox.hidden) clear();
    if (context && !busy) status.textContent = '';
    controls();
  });

  refresh();
  return {
    refresh,
    activate() {
      refresh();
      void agent?.refresh();
    },
    destroy() {
      destroyed = true;
      ++generation;
      agent?.destroy();
      panel.remove();
    },
  };
}
