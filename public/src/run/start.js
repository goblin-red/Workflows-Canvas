/* Окно агента: ссылка для ведущего прогона и подключение редактора схем. */

import { emit, state } from 'goblin/core/state.js';
import { t } from 'goblin/core/i18n.js';
import { taskLink, taskOrder } from 'goblin/api/client.js';
import { toast } from 'goblin/shell/topbar.js';
import { el } from 'goblin/panel/parts.js';

export function openStartDialog() {
  if (!state.folder) return;
  const dialog = document.getElementById('dialog-run');
  document.getElementById('run-check').replaceChildren(
    el('p', 'start-note', t('editor.start.intro')),
    runLink(),
    ...(!state.viewOnly ? [editorLink(dialog)] : []),
  );
  for (const button of dialog.querySelectorAll('[data-close]')) button.onclick = () => dialog.close();
  dialog.showModal();
}

/* Карточка ссылки: что это, куда отдать, сама ссылка и «Копировать». */
function runLink() {
  const card = el('section', 'start-link');
  card.dataset.role = 'lead';

  const field = el('input', 'start-link-url mono');
  field.readOnly = true;
  field.value = taskLink('lead', state.folder.id);
  field.onfocus = () => field.select();

  const copy = el('button', 'btn btn-accent', t('editor.start.copy'));
  copy.onclick = () => {
    navigator.clipboard?.writeText(taskOrder('lead', state.folder.id));
    toast(t('editor.topbar.run_link_copied'));
  };

  const row = el('div', 'start-link-row');
  row.append(field, copy);
  card.append(el('h4', 'start-link-name', '▶ ' + t('editor.topbar.link_run')), el('p', 'start-note', t('editor.start.run_how')), row);
  return card;
}

function editorLink(dialog) {
  const card = el('section', 'start-link');
  card.dataset.role = 'draw';
  const connect = el('button', 'btn btn-accent', t('editor.ai_agent.connect'));
  connect.onclick = () => {
    dialog.close();
    emit('ai-agent');
  };
  card.append(el('h4', 'start-link-name', '✎ ' + t('editor.topbar.link_draw')),
    el('p', 'start-note', t('editor.start.draw_how')), connect);
  return card;
}
