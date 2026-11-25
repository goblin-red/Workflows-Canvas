import { mountGraphCanvas } from 'goblin/graph/renderer.js';
import { arrowHeads } from 'goblin/graph/model.js';
import { pictureHtml, picturePng } from 'goblin/shell/picture-color.js';
import { t } from 'goblin/core/i18n.js';

const SHEETS = new Set(['tokens.css', 'base.css', 'graph-canvas.css']);
const EXPORT_CSS = `
html,body { height:auto; overflow:auto }
body { margin:0; print-color-adjust:exact; -webkit-print-color-adjust:exact }
.export-heading { padding:12px 16px; font-size:var(--f-lg); background:var(--surface); border-bottom:1px solid var(--line) }
.export-scroll { overflow:auto }
.graph-canvas { position:relative; inset:auto; touch-action:auto }
.graph-card { cursor:auto; user-select:text }
.graph-arrow-hit { display:none }
.graph-arrow-line { animation:none !important }
.graph-export-flat .graph-card { --graph-card-fill:#fff; --graph-card-edge:#90949b; --graph-card-ink:#1c1e24; --graph-card-dim:#505660 }
.graph-export-flat .graph-card-shell { background:#fff; box-shadow:none }
.graph-export-flat .graph-card[data-kind="decision"] .graph-card-shell { background:#90949b }
.graph-export-flat .graph-card-starter { color:#505660 }
.graph-export-flat .graph-arrow-line { stroke:#565d68 }
`;

const pdfScripts = new Map();

function loadPdfScript(file, global) {
  if (globalThis[global]) return Promise.resolve(globalThis[global]);
  if (pdfScripts.has(file)) return pdfScripts.get(file);
  const pending = new Promise((resolve, reject) => {
    const tag = document.createElement('script');
    tag.src = 'vendor/pdf/' + file;
    const fail = () => {
      tag.remove();
      reject(new Error(t('editor.files.pdf_builder')));
    };
    tag.onload = () => globalThis[global] ? resolve(globalThis[global]) : fail();
    tag.onerror = fail;
    document.head.append(tag);
  }).catch(error => {
    pdfScripts.delete(file);
    throw error;
  });
  pdfScripts.set(file, pending);
  return pending;
}

function exportStyles() {
  const styles = [];
  for (const sheet of document.styleSheets) {
    if (!sheet.href) continue;
    const url = new URL(sheet.href);
    if (url.origin !== location.origin || !SHEETS.has(url.pathname.split('/').at(-1))) continue;
    styles.push(...Array.from(sheet.cssRules, rule => rule.cssText));
  }
  return styles.join('\n') + EXPORT_CSS;
}

async function imageData(src) {
  const url = new URL(src, location.href);
  if (url.origin !== location.origin || url.searchParams.get('op') !== 'graph.asset.file') return '';
  const response = await fetch(url, {credentials: 'same-origin'});
  if (!response.ok) return '';
  const image = await response.blob();
  if (!/^image\/(png|jpeg|gif|webp|bmp|avif)$/i.test(image.type)) return '';
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(image);
  });
}

async function embedCovers(frame) {
  const images = new Map();
  await Promise.all([...frame.querySelectorAll('.graph-card-cover[src]')].map(async image => {
    const src = image.getAttribute('src');
    if (!images.has(src)) images.set(src, imageData(src).catch(() => ''));
    image.removeAttribute('src');
    image.removeAttribute('loading');
    const value = await images.get(src);
    if (value) image.src = value;
    else image.remove();
  }));
}

async function exportPicture({scene, style, skin, title, vector}) {
  const plain = style === 'flat';
  const host = document.createElement('div');
  Object.assign(host.style, {position: 'fixed', inset: '0 auto auto -10000px', width: '1px', height: '1px'});
  host.setAttribute('aria-hidden', 'true');
  document.body.append(host);
  const canvas = mountGraphCanvas(host, {readOnly: true, snapshot: true});
  let snapshot;
  try {
    canvas.setSkin(plain ? 'graph' : skin);
    canvas.render({...scene, selection: [], tool: 'select', runPaint: plain ? null : scene.runPaint});
    snapshot = canvas.exportFrame(vector);
  } finally {
    canvas.destroy();
    host.remove();
  }
  if (!snapshot) throw new Error(t('editor.files.nothing_to_save'));
  if (vector) return snapshot;
  const {frame, width, height} = snapshot;
  await embedCovers(frame);
  const body = document.createElement('body');
  body.classList.toggle('graph-export-flat', plain);
  body.classList.toggle('no-grid', plain || document.body.classList.contains('no-grid'));
  body.append(frame);
  const theme = plain || document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
  return {title, theme, css: exportStyles(), body, width, height};
}

function textLines(value, width, measure, limit) {
  let line = '';
  const lines = [];
  for (const char of (value || '').replace(/\s+/gu, ' ').trim().slice(0, 1200)) {
    if (line && measure(line + char) > width) {
      const space = line.lastIndexOf(' ');
      lines.push(space > 0 ? line.slice(0, space) : line);
      if (lines.length === limit) return lines;
      line = space > 0 ? line.slice(space + 1) : '';
    }
    line += char;
  }
  if (line.trim()) lines.push(line.trim());
  return lines;
}

async function drawVectorPdf(page, doc, library, picture, scale) {
  const [fontkit, fontFile] = await Promise.all([
    loadPdfScript('fontkit.umd.min.js', 'fontkit'), fetch('fonts/NotoSans-Regular.ttf'),
  ]);
  if (!fontFile.ok) throw new Error(t('editor.files.font_failed'));
  doc.registerFontkit(fontkit);
  const font = await doc.embedFont(await fontFile.arrayBuffer(), {subset: true});
  const {rgb} = library;
  const border = rgb(.45, .48, .53), ink = rgb(.12, .15, .20), dim = rgb(.34, .38, .44);
  const white = rgb(1, 1, 1), frame = rgb(.95, .96, .97);
  const baseX = -picture.left * scale, baseY = (picture.height + picture.top) * scale;
  const path = (d, fill, stroke = border, width = 1, dash = false) => page.drawSvgPath(d, {
    x: baseX, y: baseY, scale, color: fill, borderColor: stroke, borderWidth: width,
    borderDashArray: dash ? [6, 5] : [],
  });
  const write = (text, x, y, size, color = ink) => page.drawText(text, {
    x: baseX + x * scale, y: baseY - y * scale, font, size: size * scale, color,
  });
  const zone = card => card.kind === 'area' || card.kind === 'group';
  const drawCard = card => {
    const {x, y, width: w, height: h} = card.rect;
    const pad = card.kind === 'decision' ? .2 * w : card.starter ? .18 * w : 12;
    const titleY = card.starter ? y + .56 * h : y + Math.min(h * .7, 22);
    if (card.starter) {
      page.drawEllipse({x: baseX + (x + w / 2) * scale, y: baseY - (y + h / 2) * scale,
        xScale: w * scale / 2, yScale: h * scale / 2, color: white, borderColor: border, borderWidth: 1.4 * scale});
    } else if (card.kind === 'decision') {
      const cut = Math.min(26, w * .2);
      path(`M ${x + cut} ${y} H ${x + w - cut} L ${x + w} ${y + h / 2} L ${x + w - cut} ${y + h} H ${x + cut} L ${x} ${y + h / 2} Z`, white);
    } else {
      page.drawRectangle({x: baseX + x * scale, y: baseY - (y + h) * scale, width: w * scale, height: h * scale,
        color: zone(card) ? frame : white, borderColor: border, borderWidth: scale,
        borderDashArray: card.kind === 'area' ? [6 * scale, 5 * scale] : []});
    }
    if (h < 18 || w <= 2 * pad) return;
    const measure = (text, size) => font.widthOfTextAtSize(text, size);
    const title = textLines(card.title, w - 2 * pad, text => measure(text, 13), 1)[0];
    if (title) write(title, card.starter ? x + (w - measure(title, 13)) / 2 : x + pad, titleY, 13);
    if (card.starter || zone(card)) return;
    const count = Math.floor((h - (titleY - y) - 8) / 15);
    if (count < 1) return;
    textLines(card.description, w - 2 * pad, text => measure(text, 11), Math.min(4, count))
      .forEach((line, index) => write(line, x + pad, titleY + (index + 1) * 15, 11, dim));
  };
  const tip = (end, inside) => {
    const angle = Math.atan2(end.y - inside.y, end.x - inside.x);
    const dx = Math.cos(angle), dy = Math.sin(angle);
    const x = end.x - dx * 9, y = end.y - dy * 9;
    path(`M ${end.x} ${end.y} L ${x + dy * 4} ${y - dx * 4} L ${x - dy * 4} ${y + dx * 4} Z`, border);
  };
  picture.cards.filter(zone).forEach(drawCard);
  for (const edge of picture.edges) {
    const {d, points, label} = edge.route;
    const heads = arrowHeads(edge.direction);
    path(d, undefined, border, 1.8, edge.back || edge.appearance.back || edge.appearance.dash);
    if (heads.source) tip(points[0], points[1]);
    if (heads.target) tip(points.at(-1), points.at(-2));
    edge.labelRows.forEach((row, index) => {
      write(row, label.x - font.widthOfTextAtSize(row, 11) / 2, label.y - 6 + (index - (edge.labelRows.length - 1) / 2) * 13, 11, dim);
    });
  }
  picture.cards.filter(card => !zone(card)).forEach(drawCard);
}

export async function saveGraphPicture({format, style, skin, title = t('editor.files.scheme_title'), scene}) {
  const pdf = format === 'pdf', vector = pdf && style === 'flat';
  const picture = await exportPicture({scene, style, skin, title, vector});
  const name = (title || t('editor.files.scheme_file')).replace(/[\\/:*?"<>|\x00-\x1f]/g, '_');
  const file = name + (style === 'color' ? '-color' : '') + '.' + format;
  let content;
  if (pdf) {
    const library = await loadPdfScript('pdf-lib.min.js', 'PDFLib');
    const doc = await library.PDFDocument.create();
    const scale = Math.min(.75, 14000 / Math.max(picture.width, picture.height));
    const width = picture.width * scale, height = picture.height * scale;
    const page = doc.addPage([width, height]);
    if (vector) {
      await drawVectorPdf(page, doc, library, picture, scale);
    } else {
      const image = await doc.embedPng(await picturePng(picture));
      page.drawImage(image, {x: 0, y: 0, width, height});
    }
    doc.setTitle(title);
    doc.setCreator(t('editor.layout.title'));
    content = await doc.save();
  } else {
    content = pictureHtml(picture);
  }
  const blob = new Blob([content], {type: pdf ? 'application/pdf' : 'text/html;charset=utf-8'});
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = file;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}
