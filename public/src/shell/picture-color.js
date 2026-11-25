/* The same offline snapshot feeds HTML and PDF. Styles, card content and arrow
   routes come from the editor, so its skins and projections stay in sync. */
import { state } from 'goblin/core/state.js';
import { gridInk, gridStep, settings } from 'goblin/core/settings.js';
import { isHiddenByCollapse } from 'goblin/core/containers.js';
import { t, lang } from 'goblin/core/i18n.js';
import { ISO_A, ISO_B, ISO_MATRIX, project, projectedBounds } from 'goblin/canvas/projection.js';

const VIEWS = { list: '.ui-list', hierarchy: '.ui-tree', timeline: '.ui-timeline', run: '.ui-run' };
const VIEW_SCROLLERS = { list: '.ui-list-body', hierarchy: '.ui-tree-cols', timeline: '.tl-body' };
const STYLES = new Set(['tokens', 'base', 'canvas', 'elements', 'run', 'looks', 'classic',
  'ui-variants', 'timeline', 'studio', 'projection', 'mobile']);
const SVG = 'http://www.w3.org/2000/svg';
const HTML = 'http://www.w3.org/1999/xhtml';
const XMLNS = 'http://www.w3.org/2000/xmlns/';

const EXPORT_CSS = `
html,body{height:auto;overflow:auto}
body{display:block!important;margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body>.export-heading{font-size:var(--f-lg);padding:12px 18px;background:var(--surface);border-bottom:1px solid var(--line)}
.export-scroll{display:block;overflow:auto}
.export-view.canvas-wrap{position:relative!important;inset:auto!important}
.export-view,:is(.ui-list-body,.ui-tree-cols,.ui-tree-col,.tl-body){overflow:visible}
.export-view :is(.ui-tree-level,.ui-run-head,.tl-top,.tl-name,.tl-corner){position:static}
.export-view :is(.node-title,.node-desc,.ui-row,.ui-tree-row){user-select:text;cursor:auto}
.export-view *{animation:none!important;transition:none!important}
`;

export async function pictureSnapshot() {
  const mode = Object.hasOwn(VIEWS, state.variant) ? state.variant : 'canvas';
  const skin = state.look;
  const theme = document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
  const iso = mode === 'canvas' && state.iso;
  const noGrid = mode === 'canvas' && (!settings.grid || gridInk() === 0);
  const source = document.querySelector(mode === 'canvas' ? '#canvas-wrap' : VIEWS[mode]);
  if (!state.folder || !source) throw new Error(t('editor.files.nothing_to_save'));
  const title = state.folder.name || t('editor.files.scheme_title');
  const view = source.cloneNode(true);
  view.classList.add('export-view');
  const images = prepareContent(source, view);
  const dimensions = mode === 'canvas' ? canvasSize(source, view, iso) : alternateSize(source, view, mode);
  view.style.width = `${dimensions.width}px`;
  view.style.height = `${dimensions.height}px`;
  const [css] = await Promise.all([editorStyles(skin === 'studio' || mode === 'timeline'), ...images]);
  const body = document.createElement('body');
  body.dataset.look = skin;
  body.dataset.ui = mode;
  body.classList.toggle('iso', iso);
  body.classList.toggle('no-grid', noGrid);
  body.append(view);
  return { ...dimensions, title, theme, css, body };
}

function canvasSize(source, view, iso) {
  const boxes = [...state.elements.values()]
    .filter((element) => element.type !== 'arrow' && !isHiddenByCollapse(element))
    .map((element) => projectedBounds(element, iso));
  if (!boxes.length) throw new Error(t('editor.files.nothing_to_save'));

  for (const arrow of source.querySelectorAll('#arrows > g')) {
    if (arrow.style.display === 'none') continue;
    const { x, y, width, height } = arrow.getBBox();
    const corners = [[x, y], [x + width, y], [x + width, y + height], [x, y + height]]
      .map(([px, py]) => project(px, py, 0, iso));
    const xs = corners.map((point) => point.x), ys = corners.map((point) => point.y);
    boxes.push({ x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) });
  }
  const bounds = boxes.reduce((b, rect) => ({
    left: Math.min(b.left, rect.x), top: Math.min(b.top, rect.y),
    right: Math.max(b.right, rect.x + rect.w), bottom: Math.max(b.bottom, rect.y + rect.h),
  }), { left: Infinity, top: Infinity, right: -Infinity, bottom: -Infinity });
  const pad = 64;
  const x = pad - Math.floor(bounds.left), y = pad - Math.floor(bounds.top);
  const canvas = view.querySelector('#canvas');
  canvas.style.transform = `translate(${x}px,${y}px)${iso ? ` ${ISO_MATRIX}` : ''}`;
  canvas.style.setProperty('--zoom', '1');
  canvas.style.setProperty('--label-size', '11px');
  canvas.style.setProperty('--label-halo', '3px');

  const step = gridStep();
  const grid = { 'grid-step': step, 'grid-step-x': 2 * step * ISO_A, 'grid-step-y': 2 * step * ISO_B,
    'grid-dot': 0.65, 'grid-x': x % step, 'grid-y': y % step, 'iso-grid-x': x, 'iso-grid-y': y };
  for (const [key, value] of Object.entries(grid)) view.style.setProperty(`--${key}`, `${value}px`);
  view.style.setProperty('--grid-ink', `color-mix(in srgb, var(--ink-faint) ${Math.round(70 * gridInk())}%, transparent)`);
  return { width: Math.ceil(bounds.right) + x + pad, height: Math.ceil(bounds.bottom) + y + pad };
}

function alternateSize(source, view, mode) {
  const scroll = VIEW_SCROLLERS[mode] ? source.querySelector(VIEW_SCROLLERS[mode]) : null;
  const width = Math.max(640, source.scrollWidth, scroll?.scrollWidth || 0);
  // Measure the detached view at its export width with the editor's CSS. All
  // of its internal scroll areas expand so the file is not clipped to a screen.
  view.style.cssText += `;position:fixed;left:-100000px;top:0;visibility:hidden;width:${width}px;height:auto;overflow:visible`;
  for (const section of view.querySelectorAll('.ui-list-body,.ui-tree-cols,.ui-tree-col,.tl-body')) {
    section.style.overflow = 'visible';
  }
  document.body.append(view);
  const height = Math.max(100, view.scrollHeight);
  view.remove();
  view.removeAttribute('style');
  return { width, height };
}

function prepareContent(source, view) {
  const originals = source.querySelectorAll('img');
  const images = [...view.querySelectorAll('img')].map(async (img, i) => {
    const src = originals[i].currentSrc || originals[i].getAttribute('src');
    img.removeAttribute('src');
    img.removeAttribute('srcset');
    if (!src || img.closest('[hidden]')) return;
    try {
      const url = new URL(src, document.baseURI);
      if (!['http:', 'https:', 'blob:', 'data:'].includes(url.protocol)) throw new Error();
      img.src = await inlineFile(url, 'image/');
    } catch {
      // An unavailable cover should not prevent saving the rest of the scheme.
      img.closest('.has-image')?.classList.remove('has-image');
      img.remove();
    }
  });
  const detail = '.node :is(.node-desc,.node-marks,.node-result,.node-review,.node-badges,.node-brick,.node-data,.node-count,.link-stubs)';
  const details = source.querySelectorAll(detail);
  [...view.querySelectorAll(detail)].forEach((node, i) => {
    if (getComputedStyle(details[i]).display === 'none') node.remove();
  });
  view.querySelectorAll('[hidden],script,link,style,iframe,object,embed,source,.canvas-tools,.marquee,.empty-hint,.run-corner,.iso-overlay,.iso-tools,.iso-pillar,.m-folds,.port,.node-grip,.hit,.tl-tip,.tl-hint,.ui-list-head input,.ui-run-head .row,.tl-head button')
    .forEach((node) => node.remove());
  view.querySelectorAll('.node.selected,.node.sel,#arrows .selected,#arrows .sel,.ui-row.on,.ui-tree-row.on,.tl-bar.on')
    .forEach((node) => node.classList.remove('selected', 'sel', 'on'));

  for (const field of view.querySelectorAll('input,textarea,select')) {
    const label = document.createElement('span');
    label.className = field.className;
    label.textContent = field.selectedOptions?.[0]?.textContent || field.value || field.placeholder || '';
    label.hidden = field.hidden;
    field.replaceWith(label);
  }
  for (const node of [view, ...view.querySelectorAll('*')]) {
    for (const { name, value } of [...node.attributes]) {
      if (/^on/i.test(name) || ['contenteditable', 'draggable', 'data-url', 'formaction', 'action', 'target', 'tabindex', 'data-link-sig'].includes(name)
          || ((name === 'href' || name === 'xlink:href') && !value.startsWith('#'))) node.removeAttribute(name);
    }
  }
  return images;
}

async function inlineFile(url, prefix = '') {
  const result = await fetch(url, { credentials: 'same-origin' });
  const blob = await result.blob();
  if (!result.ok || !blob.type.startsWith(prefix)) throw new Error();
  return await new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(blob);
  });
}

async function editorStyles(fonts) {
  const sheets = [...document.styleSheets].filter((sheet) => {
    if (!sheet.href) return false;
    const url = new URL(sheet.href);
    return url.origin === location.origin && STYLES.has(url.pathname.split('/').pop()?.replace(/\.css$/, ''));
  });
  const styles = await Promise.all(sheets.map(async (sheet) => {
    const rules = await Promise.all([...sheet.cssRules].map(async (rule) => {
      if (rule.type !== CSSRule.FONT_FACE_RULE) return rule.cssText;
      if (!fonts) return '';
      const src = rule.style.getPropertyValue('src').match(/url\(["']?([^"')]+)/)?.[1];
      if (!src) return '';
      try { return rule.cssText.replace(src, await inlineFile(new URL(src, sheet.href))); }
      catch { return ''; }
    }));
    return rules.join('\n');
  }));
  return styles.join('\n') + EXPORT_CSS;
}

export function pictureHtml({ title, theme, css, body }) {
  const doc = document.implementation.createHTMLDocument(title);
  doc.documentElement.lang = lang;
  doc.documentElement.dataset.theme = theme;
  const encoding = doc.createElement('meta');
  encoding.setAttribute('charset', 'utf-8');
  doc.head.prepend(encoding);
  const viewport = doc.createElement('meta');
  viewport.name = 'viewport';
  viewport.content = 'width=device-width,initial-scale=1';
  const style = doc.createElement('style');
  style.textContent = css;
  doc.head.append(viewport, style);
  const heading = doc.createElement('h1');
  heading.className = 'export-heading';
  heading.textContent = title;
  const scroll = doc.createElement('main');
  scroll.className = 'export-scroll';
  scroll.append(...body.childNodes);
  body.append(heading, scroll);
  doc.body.replaceWith(body);
  return '<!doctype html>\n' + doc.documentElement.outerHTML;
}

export async function picturePng({ width, height, theme, css, body }) {
  const scale = Math.min(2, 8192 / width, 8192 / height, Math.sqrt(24_000_000 / (width * height)));
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(width * scale));
  canvas.height = Math.max(1, Math.round(height * scale));
  const svg = document.createElementNS(SVG, 'svg');
  svg.dataset.theme = theme;
  svg.setAttribute('width', canvas.width);
  svg.setAttribute('height', canvas.height);
  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
  const style = document.createElementNS(SVG, 'style');
  style.textContent = css;
  const artwork = document.createElementNS(SVG, 'foreignObject');
  artwork.setAttribute('width', width);
  artwork.setAttribute('height', height);
  body.setAttributeNS(XMLNS, 'xmlns', HTML);
  artwork.append(body);
  svg.append(style, artwork);
  // A data URL keeps this self-contained SVG readable by the canvas.
  const image = new Image();
  image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg));
  await image.decode();
  const paint = canvas.getContext('2d');
  if (!paint) throw new Error(t('editor.files.pdf_builder'));
  paint.drawImage(image, 0, 0);
  if (!paint.getImageData(0, 0, 1, 1).data[3]) throw new Error(t('editor.files.pdf_builder'));
  const png = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
  if (!png) throw new Error(t('editor.files.pdf_builder'));
  return png.arrayBuffer();
}
