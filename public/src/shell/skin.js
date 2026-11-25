import { folderId, hash, post } from 'goblin/api/client.js';
import { setLook } from 'goblin/canvas/looks.js';
import { emit, state } from 'goblin/core/state.js';
import { t } from 'goblin/core/i18n.js';
import { flush } from 'goblin/edit/scene.js';

/** Выбор скина в классическом редакторе. Для «Графа» сохраняем адрес сцены. */
export async function selectClassicSkin(name) {
  if (name !== 'graph') {
    setLook(name);
    return;
  }

  const { p, s, run } = hash();
  const project = p || s;
  const folder = folderId();
  const scene = new URLSearchParams();
  if (project) scene.set('p', project);
  if (project && folder) scene.set('f', folder);
  if (folder && state.folder?.id === folder && state.runs?.some((item) => item.id === Number(run))) {
    scene.set('run', run);
  }
  const page = state.viewOnly ? 'graph.php?view=1' : 'graph.php';
  try {
    if (state.dirty) await flush();
    if (state.dirty) {
      emit('refused', t('editor.looks.saving_before_switch'));
      return;
    }
    if (!state.viewOnly && project === state.project?.key && folder) {
      await post('graph.importLegacy', { project });
    }
  } catch (error) {
    if (error.code !== 'forbidden') return;
  }
  const current = hash();
  if (project !== (current.p || current.s) || folder !== folderId()) return;
  location.assign(scene.size ? `${page}#${scene}` : page);
}
