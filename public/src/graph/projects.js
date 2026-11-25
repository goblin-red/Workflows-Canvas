import { get, quietGet } from 'goblin/api/client.js';
import { inkFor, paintOf } from 'goblin/canvas/palette.js';
import { t } from 'goblin/core/i18n.js';
import { PROJECT_ICON } from 'goblin/left/parts.js';

const STORE = 'goblin-guest-projects';
const LIMIT = 50;
const validKey = (key) => typeof key === 'string' && /^[a-z0-9]{6,32}$/.test(key);
const words = {
  list: 'List', overview: 'Overview', view: 'Project view', empty: 'No projects yet.',
  uninitialized: 'No root block', failed: 'Could not load projects.', retry: 'Retry',
};

function savedKeys() {
  try {
    const stored = JSON.parse(localStorage.getItem(STORE) || '[]');
    if (Array.isArray(stored)) return [...new Set(stored.filter(validKey))].slice(0, LIMIT);
  } catch { /* Хранилище может быть отключено. */ }
  return [];
}

function remember(key) {
  try {
    const keys = [key, ...savedKeys().filter((saved) => saved !== key)].slice(0, LIMIT);
    localStorage.setItem(STORE, JSON.stringify(keys));
  } catch { /* Открытый проект доступен по ссылке. */ }
}

function element(tag, className, text = '') {
  const node = document.createElement(tag);
  node.className = className;
  node.textContent = text;
  return node;
}

function button(text, action) {
  const node = element('button', 'btn btn-quiet', text);
  node.type = 'button';
  node.addEventListener('click', action);
  return node;
}

function projectLink(project, activeKey) {
  const title = project.title?.trim() || t('editor.embed.untitled');
  const link = element('a', 'graph-projects-card');
  const hash = new URLSearchParams({ p: project.key });
  if (project.rootId != null) hash.set('b', project.rootId);
  link.href = 'graph.php#' + hash;
  link.title = title;
  if (project.key === activeKey) link.setAttribute('aria-current', 'page');

  const color = project.appearance?.color;
  if (typeof color === 'string') {
    const paint = paintOf(color);
    const ink = inkFor(color);
    if (paint) {
      link.style.setProperty('--project-fill', paint.fill);
      link.style.setProperty('--project-edge', paint.edge);
    }
    if (ink) link.style.setProperty('--project-ink', ink.ink);
  }

  const swatch = element('span', 'graph-projects-swatch');
  swatch.setAttribute('aria-hidden', 'true');
  const icon = element('span', 'graph-projects-icon');
  icon.setAttribute('aria-hidden', 'true');
  icon.innerHTML = PROJECT_ICON;
  const labels = element('span', 'graph-projects-labels');
  labels.append(element('span', 'graph-projects-title', title));
  if (project.rootId == null) labels.append(element('span', 'graph-projects-legacy', words.uninitialized));
  link.append(swatch, icon, labels);
  return link;
}

/** Монтирует панель. После внешнего перехода или правки вызовите refresh с текущим ключом. */
export function mountGraphProjects(host, { activeKey = '', onOpen, onError } = {}) {
  let active = validKey(activeKey) ? activeKey : '';
  let projects = [];
  let generation = 0;
  let destroyed = false;
  let viewer;
  const panel = element('section', 'graph-projects');
  const heading = t('editor.folders.projects');
  panel.setAttribute('aria-label', heading);
  const toolbar = element('div', 'graph-projects-toolbar');
  const modes = element('div', 'graph-projects-modes');
  modes.setAttribute('role', 'group');
  modes.setAttribute('aria-label', words.view);
  const status = element('p', 'graph-projects-status', t('editor.projects.loading'));
  status.setAttribute('role', 'status');
  const cards = element('ul', 'graph-projects-cards');
  const retry = button(words.retry, () => void refresh());
  retry.hidden = true;
  const list = button('', () => setView('list'));
  const overview = button('', () => setView('overview'));
  for (const [control, title, drawing] of [
    [list, words.list, '<path d="M8 5h12M8 10h12M8 15h12M4 5h.01M4 10h.01M4 15h.01"/>'],
    [overview, words.overview, '<rect x="3" y="3" width="7" height="6" rx="1"/><rect x="14" y="3" width="7" height="6" rx="1"/><rect x="3" y="12" width="7" height="6" rx="1"/><rect x="14" y="12" width="7" height="6" rx="1"/>'],
  ]) {
    control.title = title;
    control.setAttribute('aria-label', title);
    control.innerHTML = `<svg viewBox="0 0 24 21" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">${drawing}</svg>`;
  }
  modes.append(list, overview);
  toolbar.append(element('h2', 'panel-title graph-projects-heading', heading), modes);
  panel.append(toolbar, status, retry, cards);
  host.replaceChildren(panel);
  setView('list');

  function setView(view) {
    panel.dataset.view = view;
    list.setAttribute('aria-pressed', view === 'list');
    overview.setAttribute('aria-pressed', view === 'overview');
  }

  function render() {
    cards.replaceChildren(...projects.map((project) => {
      const item = element('li', 'graph-projects-item');
      const link = projectLink(project, active);
      if (onOpen) link.addEventListener('click', async (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        const pending = generation;
        try {
          await onOpen(project.key, project.rootId);
          if (pending === generation) await refresh(project.key);
        } catch (error) {
          if (!destroyed) onError?.(error);
        }
      });
      item.append(link);
      return item;
    }));
    cards.hidden = !projects.length;
  }

  async function refresh(key = active) {
    if (destroyed) return [];
    active = validKey(key) ? key : '';
    const current = ++generation;
    const keys = savedKeys();
    panel.setAttribute('aria-busy', 'true');
    retry.hidden = true;
    if (!projects.length) status.textContent = t('editor.projects.loading');
    try {
      const answer = await get('graph.projects', { project: '', keys: keys.join(',') });
      if (current !== generation) return [];
      const next = answer.projects;
      if (active && !next.some((project) => project.key === active)) {
        // Ключ из адреса подтверждает доступ к одной карточке. Список сам его не раскрывает.
        const card = await get('graph.projects', { project: active }, ['not_found']).catch((error) => {
          if (error.code !== 'not_found') throw error;
          return null;
        });
        if (current !== generation) return [];
        if (card) next.unshift(...card.projects);
      }
      projects = next;
      render();
      status.textContent = projects.length ? '' : words.empty;

      if (active && projects.some((project) => project.key === active)) {
        if (keys.includes(active)) {
          remember(active);
        } else {
          // Проверяем сессию без передачи ключей, иначе прежний API сменит владельца гостевых проектов.
          viewer ??= quietGet('project.get', { project: '' });
          const account = await viewer;
          if (!account) viewer = undefined;
          if (current === generation && account?.me === null) remember(active);
        }
      }
      return current === generation ? projects : [];
    } catch (error) {
      if (current === generation) {
        status.textContent = words.failed;
        retry.hidden = false;
        onError?.(error);
      }
      return [];
    } finally {
      if (current === generation) panel.removeAttribute('aria-busy');
    }
  }

  const ready = refresh();
  return {
    ready,
    refresh,
    destroy() {
      destroyed = true;
      ++generation;
      panel.remove();
    },
  };
}
