import { rich } from 'goblin/ui/rich.js';
import { t } from 'goblin/core/i18n.js';

const kinds = { heading: t('editor.graph.content.heading'), text: t('editor.asset.text'), markdown: 'Markdown', data: 'JSON' };

function element(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}

function field(label, input) {
  const wrap = element('label', undefined, 'field');
  input.classList.add('input');
  wrap.append(element('span', label), input);
  return wrap;
}

function button(label, action) {
  const node = element('button', label, 'btn btn-small');
  node.type = 'button';
  node.onclick = action;
  return node;
}

function dataValue(raw) {
  return JSON.parse(raw, (_key, value) => {
    if (typeof value === 'number' && !Number.isFinite(value)) throw new Error(t('editor.graph.content.number_too_large'));
    return value;
  });
}

function preview(entry) {
  if (entry.kind === 'heading') return element('h4', entry.raw, 'graph-content-heading');
  if (entry.kind === 'text') return element('p', entry.raw, 'graph-content-text');
  if (entry.kind === 'markdown') {
    const view = element('div', undefined, 'graph-content-markdown');
    // rich экранирует исходный текст и добавляет только разметку Markdown.
    view.innerHTML = rich(entry.raw);
    return view;
  }
  return element('pre', entry.raw, 'graph-json graph-content-text');
}

export function blockContentEditor(block, { readOnly, onEdit, onSave }) {
  const stored = Array.isArray(block.properties?.content) ? block.properties.content : [];
  const entries = stored.map(({ id, kind, label, value }) => ({
    id, kind, label: label ?? '', raw: kind === 'data' ? JSON.stringify(value, null, 2) : value,
  }));
  const section = element('details', undefined, 'section graph-block-content');
  const heading = element('summary', t('editor.graph.content.section'));
  const form = element('form', undefined, 'section-body');
  const list = element('ol', undefined, 'graph-content-entries');
  const error = element('p', undefined, 'graph-content-error');
  error.setAttribute('role', 'alert');
  error.hidden = true;
  form.append(list, error);
  section.append(heading, form);

  function mark() {
    error.hidden = true;
    onEdit();
  }

  function draw(focusId) {
    list.replaceChildren();
    if (!entries.length) list.append(element('li', t('editor.graph.content.empty'), 'muted'));
    entries.forEach((entry, index) => {
      const row = element('li', undefined, 'graph-content-entry');
      row.dataset.contentId = entry.id;
      row.append(element('h4', kinds[entry.kind] ?? entry.kind, 'graph-content-kind'));
      if (readOnly) {
        if (entry.label) row.append(element('p', entry.label, 'muted'));
        row.append(preview(entry));
      } else {
        const label = element('input');
        label.value = entry.label;
        label.oninput = () => {
          entry.label = label.value;
          mark();
        };
        const value = element(entry.kind === 'heading' ? 'input' : 'textarea');
        value.value = entry.raw;
        if (entry.kind === 'data') value.classList.add('graph-json');
        if (entry.kind !== 'heading') value.rows = 3;
        const read = element('details');
        read.append(element('summary', t('editor.graph.content.preview')), preview(entry));
        value.oninput = () => {
          entry.raw = value.value;
          if (read.open) read.lastElementChild.replaceWith(preview(entry));
          mark();
        };
        read.ontoggle = () => {
          if (read.open) read.lastElementChild.replaceWith(preview(entry));
        };
        const actions = element('div', undefined, 'graph-actions');
        const move = (offset) => {
          const other = index + offset;
          [entries[index], entries[other]] = [entries[other], entries[index]];
          mark();
          draw(entry.id);
        };
        const up = button(t('editor.graph.content.move_up'), () => move(-1));
        const down = button(t('editor.graph.content.move_down'), () => move(1));
        up.disabled = index === 0;
        down.disabled = index === entries.length - 1;
        actions.append(up, down, button(t('editor.graph.content.remove'), () => {
          entries.splice(index, 1);
          mark();
          draw(entries[Math.min(index, entries.length - 1)]?.id);
        }));
        row.append(field(t('editor.graph.content.label'), label), field(t('editor.graph.content.value'), value), actions, read);
        if (focusId === entry.id) queueMicrotask(() => value.focus());
      }
      list.append(row);
    });
  }

  if (!readOnly) {
    const add = element('select');
    for (const [kind, label] of Object.entries(kinds)) add.add(new Option(label, kind));
    const actions = element('div', undefined, 'graph-actions');
    actions.append(button(t('editor.graph.content.add'), () => {
      const id = 'field-' + crypto.getRandomValues(new Uint32Array(3)).join('-');
      entries.push({ id, kind: add.value, label: '', raw: add.value === 'data' ? '{}' : '' });
      mark();
      draw(id);
    }), element('button', t('editor.graph.content.save'), 'btn btn-accent btn-small'));
    form.append(field(t('editor.graph.content.type'), add), actions);
    form.onsubmit = (event) => {
      event.preventDefault();
      const content = [];
      for (const [index, entry] of entries.entries()) {
        try {
          const { id, kind, label, raw } = entry;
          const value = kind === 'data' ? dataValue(raw) : raw;
          content.push({ id, kind, ...(label ? { label } : {}), value });
        } catch {
          error.textContent = t('editor.graph.content.invalid_json', { n: index + 1 });
          error.hidden = false;
          return;
        }
      }
      onSave(content);
    };
  }
  draw();
  return section;
}
