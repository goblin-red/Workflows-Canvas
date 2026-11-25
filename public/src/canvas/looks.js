/* Виды карточки: одна схема, разные способы её показать.
   Отдаёт: LOOKS, currentLook(), setLook(), lookRule().
   Поле left — вид левой плашки: разделами или вкладками, папки строками
   или плитками, инструменты строками или сеткой (ставит left/left.js).
   Не делает: не меняет данные — только то, что видно на карточке.

   Вид выбирает человек под свою работу: дизайнеру нужны обложки,
   разработчику — номера, поля и ТЗ, leader прогона — состояние шага.
   Данные у всех одни, и правки из любого вида уходят одинаково. */

import { state, emit } from 'goblin/core/state.js';
import { t } from 'goblin/core/i18n.js';

export const LOOKS = {
  work: {
    icon: '<rect x="3.5" y="5" width="17" height="14" rx="2.5"/><path d="M7 9.5h10M7 13h6"/>',
    title: t('editor.looks.work'),
    about: t('editor.looks.work_about'),
    show: { cover: false, desc: true, badges: true, seq: true, md: true, agent: true, props: false, id: false, result: true },
    uppercase: false,
    left: { layout: 'sections', folders: 'rows', tools: 'rows' },
  },
  classic: {
    icon: '<rect x="3.5" y="5" width="17" height="14" rx="2.5"/><path d="m5 16 4-4 3.5 3.5L16 12l3 3.5"/><circle cx="9" cy="9" r="1.4"/>',
    title: t('editor.looks.classic'),
    about: t('editor.looks.classic_about'),
    show: { cover: true, desc: true, badges: true, seq: true, md: true, agent: true, props: false, id: false, result: true },
    uppercase: true,
    left: { layout: 'tabs', folders: 'tiles', tools: 'grid' },
  },
  design: {
    icon: '<path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.4 0 1.8-1 1.2-1.8-.7-1-.2-2.2 1-2.2h1.6A4.7 4.7 0 0 0 20.5 12c0-4.7-3.8-8.5-8.5-8.5z"/><circle cx="8" cy="11" r="1"/><circle cx="12" cy="8" r="1"/><circle cx="16" cy="11" r="1"/>',
    title: t('editor.looks.designer'),
    about: t('editor.looks.designer_about'),
    // Текста на карточке нет, зато значки сбоку есть все: номер, ТЗ,
    // материалы и исполнитель — они не мешают картинке. Ответ исполнителя
    // тоже прячем: он длинный и перекрывает снимок; смотреть его — в панели.
    show: { cover: true, desc: false, badges: true, seq: true, md: true, agent: true, props: false, id: false, result: false },
    uppercase: false,
    left: { layout: 'sections', folders: 'rows', tools: 'rows' },
  },
  dev: {
    icon: '<path d="m9 8-4.5 4L9 16M15 8l4.5 4L15 16M13.5 5.5l-3 13"/>',
    title: t('editor.looks.developer'),
    about: t('editor.looks.developer_about'),
    show: { cover: false, desc: true, badges: true, seq: true, md: true, agent: true, props: true, id: true, result: true },
    uppercase: false,
    left: { layout: 'sections', folders: 'rows', tools: 'rows' },
  },
  lead: {
    icon: '<path d="M6 3.5v17M6 5h11l-2 3.5L17 12H6"/>',
    title: 'leader',
    about: t('editor.looks.leader_about'),
    show: { cover: false, desc: false, badges: false, seq: true, md: true, agent: true, props: false, id: false, result: true },
    uppercase: true,
    left: { layout: 'sections', folders: 'rows', tools: 'rows' },
  },
  show: {
    icon: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
    title: t('editor.looks.show'),
    about: t('editor.looks.show_about'),
    show: { cover: true, desc: false, badges: false, seq: false, md: false, agent: false, props: false, id: false, result: false },
    uppercase: false,
    left: { layout: 'sections', folders: 'rows', tools: 'rows' },
  },
  studio: {
    icon: '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M8 9v11M16 13h2M16 16h2"/>',
    title: t('editor.looks.studio'),
    about: t('editor.looks.studio_about'),
    show: { cover: true, desc: true, badges: true, seq: true, md: true, agent: true, props: false, id: false, result: true },
    uppercase: false,
    left: { layout: 'tabs', folders: 'tiles', tools: 'grid' },
  },
  // Компьютер, но карточка как в мобильном: рамка, название по центру и стрелки.
  // Общие правила карточки обоих — css/looks.css («Минимальный и Мобильный»).
  minimal: {
    icon: '<rect x="4" y="6" width="16" height="12" rx="2.5"/><path d="M9 12h6"/>',
    title: t('editor.looks.minimal'),
    about: t('editor.looks.minimal_about'),
    show: { cover: false, desc: false, badges: false, seq: false, md: false, agent: false, props: false, id: false, result: false,
            arrowTitle: false },
    uppercase: false,
    left: { layout: 'sections', folders: 'rows', tools: 'rows' },
  },
  // Телефон и планшет: на карточке только рамка и название; раскладку экрана,
  // шторки и жесты пальцами добавляют css/mobile.css и src/mobile/.
  mobile: {
    icon: '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
    title: t('editor.looks.mobile'),
    about: t('editor.looks.mobile_about'),
    // arrowTitle: false — у стрелки только ветка и «×N», без длинной подписи.
    show: { cover: false, desc: false, badges: false, seq: false, md: false, agent: false, props: false, id: false, result: false,
            arrowTitle: false },
    uppercase: false,
    left: { layout: 'sections', folders: 'rows', tools: 'rows' },
  },
};

export const SKINS = {
  ...LOOKS,
  graph: {
    icon: '<rect x="3" y="4" width="7" height="6" rx="1"/><rect x="14" y="14" width="7" height="6" rx="1"/><path d="M6.5 10v4a3 3 0 0 0 3 3H14"/>',
    title: t('editor.looks.graph'),
    about: t('editor.looks.graph_about'),
  },
};

const STORE = 'goblin-look';
export const GRAPH_SKIN_STORE = 'goblin-graph-skin';

/** Сенсорное устройство — телефон или планшет: палец, а не мышь. */
export function isTouchDevice() {
  try { return matchMedia('(pointer: coarse)').matches; } catch { return false; }
}

export function currentLook() {
  const active = document.body.dataset.look;
  if (Object.hasOwn(LOOKS, active)) return active;
  if (globalThis.GOBLIN_CANONICAL_VISUAL) return 'studio';
  try {
    const saved = localStorage.getItem(STORE);
    if (saved && LOOKS[saved]) return saved;
  } catch {}
  // Скин ещё не выбирали: на телефоне и планшете сразу мобильный, иначе — скин установки для первого
  // захода (look_default, lib/web/page.php). То же решение принимает строка в views/editor/layout.html.
  if (isTouchDevice()) return 'mobile';
  const first = globalThis.GOBLIN_DEFAULTS?.look;
  return LOOKS[first] ? first : 'work';
}

export function setLook(name) {
  if (!Object.hasOwn(LOOKS, name)) return;
  state.look = name;
  document.body.dataset.look = name;
  const address = new URL(location.href);
  const canonical = globalThis.GOBLIN_CANONICAL_VISUAL === true;
  if ((canonical || address.searchParams.get('visual') === '1') && address.searchParams.get('skin') !== name) {
    address.searchParams.set('skin', name);
    history.replaceState(history.state, '', address);
  }
  try { localStorage.setItem(canonical ? GRAPH_SKIN_STORE : STORE, name); } catch {}
  emit('look', name);
  emit('steps');   // перерисовать карточки целиком
}

export function lookRule() {
  return LOOKS[state.look] || LOOKS.work;
}
