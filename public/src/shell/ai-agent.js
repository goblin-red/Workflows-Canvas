import { post, quietGet } from 'goblin/api/client.js';
import { lang, t } from 'goblin/core/i18n.js';

const label = (name, vars) => t('editor.ai_agent.' + name, vars);
const accountParams = { project: '' };

function element(tag, className, text) {
  const node = document.createElement(tag);
  node.className = className;
  if (text) node.textContent = text;
  return node;
}

function button(name, action, className = 'btn') {
  const control = element('button', className, label(name));
  control.type = 'button';
  control.onclick = action;
  return control;
}

export function mountAiAgent(host, { manage = false, getTarget } = {}) {
  let status = null;
  let invitation = null;
  let destinations = [];
  let targetStatus = '';
  let busy = false;
  let checkFailed = false;
  let request = 0;
  let polling = null;
  const open = button('connect', show);
  function show() {
    if (dialog.open) return;
    if (!invitation) scope.value = 'scheme';
    targetStatus = '';
    dialog.showModal();
    void refresh();
    polling = setInterval(() => {
      if (!busy && !document.hidden) void refresh();
    }, 4000);
  }
  const badge = element('span', 'muted ai-agent-badge');
  host.classList.add('ai-agent-control');
  host.append(open, badge);

  const dialog = element('dialog', 'dialog ai-agent-dialog');
  dialog.setAttribute('aria-label', label('title'));
  dialog.addEventListener('close', () => {
    clearInterval(polling);
    polling = null;
  });

  const heading = element('h3', 'dialog-title', label('title'));
  const note = element('p', 'muted', label('shared'));
  const connection = element('p', 'ai-agent-status', label('loading'));
  connection.setAttribute('role', 'status');
  const alert = element('p', 'ai-agent-error');
  alert.setAttribute('role', 'alert');

  const guest = element('p', 'ai-agent-guest', label('login') + ' ');
  const login = element('a', '', label('account'));
  login.href = 'account.php?section=ai';
  guest.append(login);
  const retry = button('retry', () => void refresh());

  const field = element('label', 'field');
  const provider = element('select', 'input');
  provider.setAttribute('aria-label', label('provider'));
  const builtin = new Option(label('builtin'), 'builtin');
  const own = new Option(label('own'), 'agent');
  provider.append(builtin, own);
  field.append(element('span', '', label('provider')), provider);
  provider.onchange = () => void change('select', { provider: provider.value });

  const issuedScope = element('p', 'muted');
  const scopeField = element('label', 'field');
  const scope = element('select', 'input');
  scope.setAttribute('aria-label', label('scope'));
  scope.append(new Option(label('scheme_scope'), 'scheme'), new Option(label('account_scope'), 'account'));
  scopeField.append(element('span', '', label('scope')), scope);
  const targetField = element('label', 'field');
  const targetPicker = element('select', 'input');
  targetPicker.setAttribute('aria-label', label('target'));
  targetPicker.append(new Option(label('choose_target'), ''));
  targetField.append(element('span', '', label('target')), targetPicker);
  const targetNote = element('p', 'muted');
  const retryTargets = button('retry', () => void loadTargets());
  function changeScope() {
    clearInvite();
    render();
    maybeLoadTargets();
  }
  scope.onchange = targetPicker.onchange = changeScope;

  function makeInvite(response) {
    invitation = response;
    const link = new URL('ai-agent.php', location.href);
    link.hash = new URLSearchParams({ code: response.code });
    instruction.value = link.href;
    const time = new Date(response.expiresAt);
    expires.textContent = label('expires', {
      time: Number.isFinite(time.getTime()) ? time.toLocaleString(lang) : response.expiresAt,
    });
    feedback.textContent = '';
  }

  const invite = button('invite', () => {
    if (scope.value === 'account') {
      void change('invite', { scope: 'account' }, makeInvite);
      return;
    }
    const target = selectedTarget();
    if (target) {
      const { project, parent, folder } = target;
      void change('invite', { scope: 'scheme', project, ...(parent ? { parent } : { folder }) }, makeInvite);
    }
  }, 'btn btn-accent');
  const visibleCode = element('section', 'ai-agent-invite');
  const instruction = element('input', 'input ai-agent-instruction');
  instruction.type = 'text';
  instruction.readOnly = true;
  instruction.spellcheck = false;
  instruction.setAttribute('aria-label', label('instruction'));
  const expires = element('small', 'muted');
  const feedback = element('span', 'muted');
  feedback.setAttribute('role', 'status');
  const copy = button('copy', async () => {
    try {
      await navigator.clipboard.writeText(instruction.value);
      feedback.textContent = label('copied');
    } catch {
      instruction.focus();
      instruction.select();
      feedback.textContent = label('select_copy');
    }
  });
  visibleCode.append(element('p', '', label('paste')), expires, instruction, copy, feedback);

  const nameField = element('label', 'field');
  const name = element('input', 'input');
  name.autocomplete = 'off';
  name.maxLength = 80;
  name.required = true;
  const saveName = element('button', 'btn', label('save_name'));
  saveName.type = 'submit';
  nameField.append(element('span', '', label('rename')), name);
  const rename = element('form', 'ai-agent-rename');
  rename.onsubmit = (event) => {
    event.preventDefault();
    const value = name.value.trim();
    if (value) void change('rename', { name: value });
  };
  rename.append(nameField, saveName);
  const disconnect = button('disconnect', () => void change('disconnect', {}, clearInvite), 'btn btn-quiet btn-danger');
  const close = button('close', () => dialog.close(), 'btn btn-quiet');
  dialog.append(heading, note, connection, issuedScope, alert, guest, retry, field, scopeField,
    targetField, targetNote, retryTargets, invite, visibleCode, rename, disconnect, close);
  document.body.append(dialog);

  function clearInvite() {
    invitation = null;
    instruction.value = '';
    feedback.textContent = '';
  }

  function selectedTarget() {
    return getTarget?.() ?? destinations.find(({ key }) => key === targetPicker.value);
  }

  function targetNeeded() {
    return dialog.open && status?.account === true && !status.connected && scope.value === 'scheme' && !getTarget?.();
  }

  async function loadTargets() {
    targetStatus = 'loading_targets';
    render();
    const answer = await quietGet('ai.agent.targets', accountParams);
    const before = targetPicker.value;
    destinations = (answer?.targets ?? []).map(target => ({
      ...target, key: [target.project, target.parent ?? '', target.folder ?? ''].join('/'),
    }));
    targetPicker.replaceChildren(new Option(label('choose_target'), ''), ...destinations.map(target =>
      new Option([target.projectTitle, target.title].filter(Boolean).join(' · '), target.key)));
    const current = destinations.find(target => target.key === before);
    if (current) targetPicker.value = current.key;
    else if (destinations.length === 1) targetPicker.value = destinations[0].key;
    targetStatus = !answer ? 'failed_targets' : destinations.length ? 'ready' : 'no_targets';
    render();
  }

  function maybeLoadTargets() {
    if (targetNeeded() && !targetStatus) void loadTargets();
  }

  function render() {
    const account = status?.account === true;
    const connected = status?.connected === true;
    const active = status?.provider === 'agent' ? (status.name || label('own')) : label('builtin');
    open.textContent = label(connected ? 'manage' : 'connect');
    badge.textContent = account ? label('mode', { provider: active }) : '';
    const state = checkFailed ? 'unavailable' : !status ? 'loading' : !account ? ''
      : connected ? (status.online ? 'online' : 'offline') : invitation ? 'pending' : 'unpaired';
    const stateText = state ? label(state) : '';
    if (connection.textContent !== stateText) connection.textContent = stateText;
    connection.hidden = !stateText;
    const targetName = status?.target?.title;
    issuedScope.textContent = status?.scope === 'scheme' && targetName
      ? label('scope_name', { name: targetName }) : status?.scope === 'scheme' ? label('scheme_scope') : label('account_scope');
    issuedScope.hidden = !connected || !status.scope;
    alert.hidden = !alert.textContent;
    guest.hidden = !status || account;
    retry.hidden = !checkFailed;
    field.hidden = !account;
    invite.hidden = !account || connected;
    scopeField.hidden = invite.hidden;
    const chooseTarget = !invite.hidden && scope.value === 'scheme' && !getTarget?.();
    targetField.hidden = !chooseTarget;
    targetNote.hidden = !chooseTarget || !targetStatus || targetStatus === 'ready';
    targetNote.textContent = targetNote.hidden ? '' : label(targetStatus);
    retryTargets.hidden = !chooseTarget || targetStatus !== 'failed_targets';
    invite.textContent = label(invitation ? 'new_code' : 'invite');
    visibleCode.hidden = !account || !invitation || connected;
    own.disabled = !connected;
    provider.value = status?.provider === 'agent' ? 'agent' : 'builtin';
    disconnect.hidden = !account || !connected;
    rename.hidden = !manage || !account || !connected;
    for (const control of [provider, scope, disconnect, name, saveName]) control.disabled = busy;
    targetPicker.disabled = busy || targetStatus !== 'ready';
    invite.disabled = busy || (scope.value === 'scheme' && (!selectedTarget() || (chooseTarget && targetStatus !== 'ready')));
  }

  async function refresh() {
    const turn = ++request;
    const next = await quietGet('ai.agent.status', accountParams);
    if (turn !== request) return;
    checkFailed = next === null;
    if (next) {
      if (next.connected || !next.account) clearInvite();
      if (status?.name !== next.name) name.value = next.name || '';
      status = next;
    }
    render();
    maybeLoadTargets();
  }

  async function change(action, args, onSuccess) {
    if (busy) return;
    busy = true;
    ++request;
    alert.textContent = '';
    render();
    try {
      const response = await post('ai.agent.' + action, { ...accountParams, ...args });
      onSuccess?.(response);
      await refresh();
    } catch (error) {
      alert.textContent = error.message || label('error');
    } finally {
      busy = false;
      render();
    }
  }

  render();
  return {
    open: show,
    refresh,
    destroy() {
      ++request;
      clearInterval(polling);
      dialog.remove();
      open.remove();
      badge.remove();
    },
  };
}
