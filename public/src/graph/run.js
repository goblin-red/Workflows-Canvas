import { get, post } from 'goblin/api/client.js';
import { lang, t } from 'goblin/core/i18n.js';
import { RUN_MARKS, RUN_WORDS, STEP_MARKS, STEP_WORDS } from 'goblin/core/kinds.js';

const live = (run) => ['running', 'paused'].includes(run?.state);
const tr = (key, vars) => t('editor.graph_run.' + key, vars);
const labels = Object.fromEntries(['start', 'pause', 'resume', 'stop', 'reset', 'finish', 'pass']
  .map((kind) => [kind, tr('event_' + kind)]));
const waitLabels = Object.fromEntries(['pause', 'decision', 'pass', 'show', 'person', 'lead']
  .map((reason) => [reason, tr('wait_' + reason)]));

const clockOptions = { timeZone: 'UTC', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' };
const clockFormat = new Intl.DateTimeFormat(lang, clockOptions);
const stampFormat = new Intl.DateTimeFormat(lang, { ...clockOptions, year: 'numeric', month: '2-digit', day: '2-digit' });
const percentFormat = new Intl.NumberFormat(lang, { style: 'percent' });

function element(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}

function button(text, action, disabled = false, className = 'btn') {
  const node = element('button', text, className);
  node.type = 'button';
  node.disabled = disabled;
  node.onclick = action;
  return node;
}

function leaderLink({ project, parent }) {
  const link = new URL('api.php', location.href);
  link.search = new URLSearchParams({ op: 'scheme', project, parent });
  return link.href;
}

async function copyLeaderLink(scene, feedback) {
  const link = leaderLink(scene);
  try {
    await navigator.clipboard.writeText(link);
    feedback.textContent = t('editor.topbar.run_link_copied');
    feedback.hidden = false;
  } catch {
    const field = element('input', undefined, 'input');
    field.setAttribute('aria-label', tr('leader_link'));
    field.readOnly = true;
    field.value = link;
    feedback.replaceChildren(element('p', t('editor.ai_agent.select_copy')), field);
    feedback.hidden = false;
    field.focus();
    field.select();
  }
}

function heading(text) {
  return element('h3', text, 'panel-title');
}

function statusMark(state) {
  const label = STEP_WORDS[state] ?? state;
  const mark = element('span', (STEP_MARKS[state] ?? '·') + ' ' + label, 'chip graph-step-state');
  mark.dataset.stepState = state;
  return mark;
}

function timeLabel(value) {
  const time = timelineTime(value);
  return Number.isFinite(time) ? clockFormat.format(time) : value || '';
}

function paintSnapshot(paint, steps) {
  const nodes = Object.fromEntries(paint.blocks.map((block) => [block.id, block]));
  const edges = Object.fromEntries(paint.edges.map((edge) => [edge.id, edge.state === 'lit' ? { ...edge, pulse: true } : edge]));
  const latest = new Map();
  for (const step of steps) {
    if (step.block && (!latest.has(step.block) || latest.get(step.block).attempt <= step.attempt)) {
      latest.set(step.block, step);
    }
  }
  for (const step of latest.values()) {
    if (step.state === 'accepted' && step.chosen && edges[step.chosen]) {
      edges[step.chosen] = { ...edges[step.chosen], chosen: true };
    }
  }
  const { run, version, epoch, state } = paint;
  return { run, version, epoch, state, nodes, edges };
}

function timelineTime(value) {
  if (!value) return NaN;
  const iso = value.replace(' ', 'T');
  return Date.parse(/(?:Z|[+-]\d{2}:\d{2})$/i.test(iso) ? iso : iso + 'Z');
}

function durationLabel(seconds) {
  const whole = Math.round(seconds);
  if (whole < 60) return tr('seconds', { n: whole });
  const minutes = Math.floor(whole / 60);
  return minutes < 60 ? tr('minutes_seconds', { minutes, seconds: whole % 60 })
    : tr('hours_minutes', { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
}

function runMetrics(timeline) {
  const { run, progress } = timeline;
  const metrics = element('dl', undefined, 'graph-run-metrics');
  const add = (label, value) => {
    const pair = element('div');
    pair.append(element('dt', label), element('dd', value));
    metrics.append(pair);
  };
  const start = timelineTime(run.started);
  if (Number.isFinite(start)) add(tr('started'), stampFormat.format(start));
  const seconds = (timelineTime(run.finished ?? run.now) - start) / 1000;
  if (Number.isFinite(seconds)) add(tr('duration'), durationLabel(Math.max(0, seconds)));
  if (progress) {
    const count = progress.total ? tr('progress_count', { done: progress.done, total: progress.total }) : '';
    add(tr('completed'), [percentFormat.format(progress.percent / 100), count].filter(Boolean).join(' · '));
  }
  return metrics;
}

function eventLabel(mark) {
  if (mark.kind === 'stop' && mark.asked) return tr('event_stop_requested');
  if (mark.kind === 'finish' && mark.end === 'failed') return tr('event_failed');
  return labels[mark.kind] ?? mark.title ?? mark.kind;
}

function currentWait({ state, timeline }) {
  if (state.run.state === 'paused') return waitLabels.pause;
  if (state.wakeIn) return waitLabels.show;
  if (state.open.some((step) => step.state === 'submitted')) return tr('wait_review');
  const interval = timeline.waits.find((wait) => !wait.to);
  return waitLabels[interval?.reason] ?? tr('wait_next');
}

function scale(timeline) {
  const from = timelineTime(timeline.run.lifeFrom ?? timeline.run.started);
  const last = timelineTime(timeline.run.finished ?? timeline.run.now);
  if (!Number.isFinite(from) || !Number.isFinite(last)) return null;
  const duration = Math.max(1000, last - from);
  const position = (time) => Math.min(100, Math.max(0, (time - from) * 100 / duration));
  const chart = element('figure', undefined, 'graph-timeline');
  chart.append(element('figcaption', tr('timeline'), 'panel-title'));
  const axis = element('div', undefined, 'graph-timeline-axis');
  axis.append(element('span', durationLabel(0)), element('span', durationLabel(duration / 2000)), element('span', durationLabel(duration / 1000)));
  chart.append(axis);

  const events = element('div', undefined, 'graph-timeline-track graph-timeline-events');
  for (const mark of timeline.marks) {
    const at = timelineTime(mark.at);
    if (!Number.isFinite(at) || at < from) continue;
    const point = element('span', undefined, 'graph-timeline-point');
    point.dataset.event = mark.kind;
    point.style.left = position(at) + '%';
    point.title = [timeLabel(mark.at), eventLabel(mark)].filter(Boolean).join(' · ');
    point.setAttribute('aria-label', point.title);
    events.append(point);
  }
  if (events.childElementCount) chart.append(trackRow(tr('events'), events));

  for (const lane of timeline.lanes) {
    const bars = timeline.bars.filter((bar) => bar.lane === lane.key);
    if (!bars.length) continue;
    const track = element('div', undefined, 'graph-timeline-track');
    const rows = [];
    for (const bar of bars) {
      const start = timelineTime(bar.from);
      const end = bar.to ? timelineTime(bar.to) : last;
      if (!Number.isFinite(start) || !Number.isFinite(end)) continue;
      const free = rows.findIndex((prevEnd) => prevEnd <= start);
      const row = free < 0 ? rows.length : free;
      rows[row] = Math.max(start + duration / 40, end);
      const barNode = element('span', undefined, 'graph-timeline-interval');
      barNode.dataset.stepState = bar.state;
      barNode.style.left = position(start) + '%';
      barNode.style.width = (position(end) - position(start)) + '%';
      barNode.style.top = (row * 11 + 3) + 'px';
      barNode.title = [bar.title || tr('step'), timeLabel(bar.from), timeLabel(bar.to), STEP_WORDS[bar.state] ?? bar.state].filter(Boolean).join(' · ');
      barNode.setAttribute('aria-label', barNode.title);
      track.append(barNode);
    }
    track.style.minHeight = (Math.max(1, rows.length) * 11 + 5) + 'px';
    chart.append(trackRow(lane.title, track));
  }
  return chart;
}

function trackRow(title, track) {
  const row = element('div', undefined, 'graph-timeline-row');
  row.append(element('span', title, 'graph-timeline-label'), track);
  return row;
}

function resultFiles(files, project) {
  const list = element('ul', undefined, 'graph-run-files');
  for (const file of files) {
    const item = element('li', undefined, 'graph-run-file');
    if (file.asset || file.url) {
      const url = file.url || 'api.php?' + new URLSearchParams({ op: 'graph.asset.file', project, asset: file.asset });
      const link = element('a', file.name);
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      if (file.image) {
        const preview = element('img');
        preview.src = url;
        preview.alt = file.name;
        preview.loading = 'lazy';
        preview.decoding = 'async';
        link.prepend(preview);
      }
      item.append(link);
    } else {
      item.append(element('span', file.name + ' · ' + tr(file.agent ? 'file_at_worker' : 'file_unavailable'), 'muted'));
    }
    list.append(item);
  }
  return list;
}

function history(timeline, steps, onFocus, archived, project) {
  const section = element('section', undefined, 'section graph-run-history');
  section.append(heading(tr('history')));
  const chart = scale(timeline);
  if (chart) section.append(chart);
  const laneNames = new Map(timeline.lanes.map((lane) => [lane.key, lane.title]));
  const attempts = new Map(steps.map((step) => [step.id, step]));
  if (timeline.marks.length) {
    const marks = element('div', undefined, 'graph-run-marks');
    for (const mark of timeline.marks) {
      marks.append(element('span', [timeLabel(mark.at), eventLabel(mark)].filter(Boolean).join(' · '), 'chip'));
    }
    section.append(marks);
  }

  const list = element('ol', undefined, 'graph-run-attempts');
  for (const bar of timeline.bars) {
    const step = attempts.get(bar.step);
    const id = archived ? null : step?.block ?? bar.element;
    const row = element('li', undefined, 'graph-run-attempt');
    const title = bar.title || (id ? tr('block', { id }) : tr('step'));
    row.dataset.blockId = id ?? '';
    const head = element('div', undefined, 'graph-run-attempt-head');
    head.append(id ? button(title, () => onFocus(id), false, 'graph-run-focus') : element('strong', title), statusMark(bar.state));
    row.append(head);
    const moment = [timeLabel(bar.from), timeLabel(bar.to)].filter(Boolean).join(' – ');
    const attempt = bar.attempt ? tr('attempt', { n: bar.attempt }) : '';
    const where = [moment, attempt, laneNames.get(bar.lane)].filter(Boolean).join(' · ');
    if (where) row.append(element('small', where, 'muted'));
    if (bar.branch || step?.chosen) {
      const branch = ['yes', 'no'].includes(bar.branch) ? tr('branch_' + bar.branch) : bar.branch;
      const fork = [branch, step?.chosen && tr('arrow', { id: step.chosen })].filter(Boolean).join(' · ');
      row.append(element('p', tr('selected_branch', { branch: fork }), 'graph-run-branch'));
    }
    const result = step?.result ?? bar.result;
    const error = step?.error ?? bar.error;
    const files = timeline.files?.[bar.step] ?? [];
    if (result || error || files.length) {
      const details = element('details');
      details.append(element('summary', tr(error ? 'details' : 'result')));
      if (result || error) details.append(element('pre', [result, error].filter(Boolean).join('\n'), 'graph-run-result'));
      if (files.length) {
        details.open = files.some((file) => file.image);
        details.append(resultFiles(files, project));
      }
      row.append(details);
    }
    list.append(row);
  }
  section.append(list.childElementCount ? list : element('p', tr('no_attempts'), 'muted'));
  if (timeline.waits.length) {
    const waits = element('details');
    const intervals = element('ul', undefined, 'graph-run-attempts');
    for (const wait of timeline.waits) {
      intervals.append(element('li', [timeLabel(wait.from), timeLabel(wait.to), waitLabels[wait.reason] ?? wait.reason]
        .filter(Boolean).join(' · '), 'graph-run-attempt muted'));
    }
    waits.append(element('summary', tr('waits')), intervals);
    section.append(waits);
  }
  return section;
}

export function mountGraphRun(host, { onPaint, onFocus, readOnly = false }) {
  let scene = null;
  let runs = [];
  let selected = null;
  let snapshot = null;
  let listed = false;
  let request = 0;
  let loading = false;
  let writing = false;
  let error = '';
  let lastRefresh = 0;
  const preferences = new Map();
  const collapsedRuns = new Set();
  const agentNote = document.createComment('');

  function selectRun(id) {
    if (id === selected) return;
    selected = id;
    snapshot = null;
    onPaint(null);
  }

  function setScene(project, parent, { legacyFolder = null, runId = null } = {}) {
    const key = parent && project ? [project, parent, legacyFolder, runId].join(':') : '';
    if (key === (scene?.key ?? '')) {
      if (!key && !host.childElementCount) draw();
      return;
    }

    request++;
    loading = writing = false;
    scene = key ? { key, project, parent, legacyFolder, runId } : null;
    if (scene) {
      agentNote.data = '\nGoblin run instructions for the current scene (GET):\n' + leaderLink(scene) + '\n';
      if (!agentNote.parentNode) document.prepend(agentNote);
    } else {
      agentNote.remove();
    }
    runs = [];
    listed = false;
    error = '';
    if (selected !== null) selectRun(null);
    draw();
    if (scene) void refresh();
  }

  async function refresh(force = false) {
    if (!scene || (loading && !force)) return;
    lastRefresh = Date.now();
    const turn = ++request;
    const { key, project, parent, legacyFolder, runId } = scene;
    const belongs = (run) => run.parent === parent || (legacyFolder && !run.parent && run.folder === legacyFolder);
    loading = true;
    try {
      const [native, legacy] = await Promise.all([
        get('run.get', { project, parent }),
        legacyFolder ? get('run.get', { project, folder: legacyFolder }) : { runs: [] },
      ]);
      if (turn !== request) return;
      const next = [...native.runs, ...legacy.runs].filter(belongs);
      if (runId && !next.some((run) => run.id === runId)) {
        const named = await get('run.get', { project, run: runId }, ['not_found']).catch((error) => {
          if (error.code !== 'not_found') throw error;
          return null;
        });
        if (turn !== request) return;
        if (named && belongs(named.run)) next.push(named.run);
      }
      const changed = JSON.stringify(next) !== JSON.stringify(runs);
      const first = !listed;
      runs = next;
      listed = true;
      const preferred = runs.find((run) => run.id === preferences.get(key));
      const choice = preferred ?? runs.find((run) => run.id === runId) ?? runs.find((run) => run.parent && live(run)) ?? runs[0];
      selectRun(choice?.id ?? null);
      const hadError = !!error;
      error = '';
      if (!choice) {
        if (first || changed || hadError || force) draw();
        return;
      }

      const args = { project, run: choice.id };
      const state = await get('run.state', args);
      if (turn !== request || !belongs(state.run)) return;
      if (snapshot?.state.run.version === state.run.version && snapshot.state.epoch === state.epoch) {
        if (changed || hadError || force) draw();
        else if (!host.hidden && live(state.run) && state.now !== snapshot.timeline.run.now) {
          snapshot.timeline.run.now = state.now;
          const chart = scale(snapshot.timeline);
          if (chart) host.querySelector('.graph-timeline')?.replaceWith(chart);
          host.querySelector('.graph-run-metrics')?.replaceWith(runMetrics(snapshot.timeline));
        }
        return;
      }

      const archived = !choice.parent;
      const [paint, log, timeline] = await Promise.all([
        archived ? null : get('run.paint', args), get('run.get', args), get('run.timeline', args),
      ]);
      if (turn !== request) return;
      const { version } = state.run;
      if (!belongs(log.run) || !belongs(timeline.run)
          || log.run.version !== version || timeline.run.version !== version || timeline.run.epoch !== state.epoch
          || (paint && (paint.version !== version || paint.epoch !== state.epoch))) {
        draw();
        return;
      }
      const steps = archived ? log.steps : log.steps.filter((step) => step.block);
      snapshot = { state, steps, timeline, archived };
      onPaint(paint ? paintSnapshot(paint, steps) : null);
      draw();
    } catch (problem) {
      if (turn !== request) return;
      error = problem.message || tr('load_error');
      draw();
    } finally {
      if (turn === request) loading = false;
    }
  }

  async function command(op, args = {}) {
    if (readOnly || !scene || writing) return;
    const context = scene;
    const commandId = 'graph-run-' + crypto.getRandomValues(new Uint32Array(4)).join('-');
    writing = true;
    error = '';
    draw();
    try {
      const answer = await post(op, { project: context.project, commandId, ...args });
      if (scene !== context) return;
      if (op === 'run.start' && answer.run?.parent === context.parent) preferences.set(context.key, answer.run.id);
      await refresh(true);
    } catch (problem) {
      if (scene !== context) return;
      error = problem.message || tr('command_error');
    } finally {
      if (scene === context) {
        writing = false;
        draw();
      }
    }
  }

  function readySteps(state) {
    if (!state.ready.length && !state.open.length) return null;
    const section = element('section', undefined, 'section graph-run-work');
    const run = state.run.id;
    const blocked = readOnly || writing || state.run.state !== 'running';
    if (state.ready.length) section.append(heading(tr('ready')));
    for (const item of state.ready) {
      const row = element('div', undefined, 'graph-ready');
      row.append(button(item.title || tr('block', { id: item.id }), () => onFocus(item.id), false, 'graph-run-focus'));
      const args = { run, block: item.id, ...(item.via != null ? { via: item.via } : {}) };
      if (item.type === 'decision') {
        if (!item.branches?.length) row.append(element('p', tr('no_branches'), 'muted'));
        for (const branch of item.branches ?? []) {
          const option = element('div', undefined, 'graph-branch-choice');
          const label = branch.label || branch.key || tr('branch');
          const choice = button(label + ' · #' + branch.edge, () => void command('step.decide', { ...args, edge: branch.edge }), blocked);
          choice.dataset.arrowId = branch.edge;
          option.append(choice);
          const info = [branch.key && tr('branch_key', { key: branch.key }), branch.condition, branch.default && tr('default_branch')].filter(Boolean);
          if (info.length) option.append(element('small', info.join(' · '), 'muted'));
          row.append(option);
        }
      } else if (item.type === 'gateway') {
        row.append(button(tr('pass_gateway'), () => void command('step.pass', args), blocked));
      } else {
        row.append(button(tr('open_step'), () => void command('step.open', args), blocked));
      }
      section.append(row);
    }
    if (state.open.length) section.append(heading(tr('open_steps')));
    for (const step of state.open) {
      if (!step.block) continue;
      const row = element('div', undefined, 'graph-ready');
      row.append(button(step.title || tr('block', { id: step.block }), () => onFocus(step.block), false, 'graph-run-focus'),
        statusMark(step.state));
      if (step.state === 'submitted') {
        if (step.result) row.append(element('pre', step.result, 'graph-run-result'));
        const actions = element('div', undefined, 'graph-actions');
        const form = element('form', undefined, 'graph-return');
        form.hidden = true;
        const field = element('label', undefined, 'field');
        const reason = element('textarea', undefined, 'input');
        reason.required = true;
        field.append(element('span', tr('return_reason')), reason);
        form.append(field, element('button', tr('return_step'), 'btn btn-danger'));
        form.onsubmit = (event) => {
          event.preventDefault();
          if (reason.value.trim()) void command('step.return', { run, step: step.id, why: reason.value.trim() });
        };
        actions.append(
          button(tr('accept'), () => void command('step.accept', { run, step: step.id }), blocked, 'btn btn-accent'),
          button(tr('return'), () => {
            form.hidden = !form.hidden;
            if (!form.hidden) reason.focus();
          }, blocked),
        );
        row.append(actions, form);
      }
      section.append(row);
    }
    return section;
  }

  function draw() {
    const fragment = document.createDocumentFragment();
    if (!scene) {
      host.replaceChildren(element('p', tr('select_scene'), 'muted'));
      return;
    }
    const chosen = snapshot?.state.run ?? runs.find((run) => run.id === selected);
    const actions = element('div', undefined, 'graph-actions');
    const copyFeedback = readOnly ? null : element('div', undefined, 'muted');
    const active = runs.find((run) => run.parent && live(run));
    if (listed && !active && !readOnly) {
      actions.append(button(tr('start'), () => void command('run.start', { parent: scene.parent }), writing, 'btn btn-accent'));
    }
    if (runs.length) {
      const label = element('label', undefined, 'field');
      const list = element('select', undefined, 'input');
      for (const run of runs) {
        const label = (run.label || 'r' + run.no) + ' · ' + (RUN_WORDS[run.state] ?? run.state);
        list.add(new Option(run.parent ? label : label + ' · ' + tr('legacy'), run.id));
      }
      list.value = String(selected);
      list.disabled = writing;
      list.onchange = () => {
        preferences.set(scene.key, Number(list.value));
        selectRun(Number(list.value));
        draw();
        void refresh(true);
      };
      label.append(element('span', tr('scene_runs')), list);
      fragment.append(label);
    }
    if (!readOnly && chosen?.parent && live(chosen)) {
      if (chosen.state === 'paused') actions.append(button(tr('resume'), () => void command('run.resume', { run: chosen.id }), writing));
      else actions.append(
        button(tr('advance'), () => void command('run.advance', { run: chosen.id }), writing),
        button(tr('pause'), () => void command('run.pause', { run: chosen.id }), writing),
      );
    }
    if (copyFeedback) {
      copyFeedback.hidden = true;
      copyFeedback.setAttribute('role', 'status');
      const context = scene;
      actions.append(button(tr('copy_leader_link'), () => void copyLeaderLink(context, copyFeedback)));
    }
    if (actions.childElementCount) fragment.append(actions);
    if (copyFeedback) fragment.append(copyFeedback);
    if (error) {
      const notice = element('p', error, 'graph-run-error');
      notice.setAttribute('role', 'alert');
      fragment.append(notice);
    }
    if (chosen) {
      const status = element('div', undefined, 'graph-run-status');
      const state = element('span', [RUN_MARKS[chosen.state], RUN_WORDS[chosen.state] ?? chosen.state].filter(Boolean).join(' '), 'graph-run-state');
      state.dataset.runState = chosen.state;
      state.setAttribute('role', 'status');
      if (snapshot) {
        const body = element('div');
        body.id = host.id + '-details';
        body.hidden = collapsedRuns.has(chosen.id);
        const toggle = button(chosen.label || 'r' + chosen.no, () => {
          body.hidden = !body.hidden;
          if (body.hidden) collapsedRuns.add(chosen.id);
          else collapsedRuns.delete(chosen.id);
          toggle.setAttribute('aria-expanded', String(!body.hidden));
        }, false, 'btn graph-run-toggle');
        toggle.setAttribute('aria-expanded', String(!body.hidden));
        toggle.setAttribute('aria-controls', body.id);
        status.append(toggle, state);
        fragment.append(status, runMetrics(snapshot.timeline));
        if (chosen.summary) fragment.append(element('p', chosen.summary, 'graph-run-summary'));
        if (chosen.waitFor && live(chosen)) fragment.append(element('p', currentWait(snapshot), 'muted'));
        const work = snapshot.archived ? null : readySteps(snapshot.state);
        if (work) body.append(work);
        body.append(history(snapshot.timeline, snapshot.steps, onFocus, snapshot.archived, scene.project));
        fragment.append(body);
      } else {
        status.append(state);
        fragment.append(status);
      }
    } else if (!error) {
      fragment.append(element('p', tr(listed ? 'no_runs' : 'loading'), 'muted'));
    }

    host.replaceChildren(fragment);
  }

  setInterval(() => {
    if (document.hidden || writing) return;
    if (!host.hidden || live(snapshot?.state.run) || Date.now() - lastRefresh >= 10000) void refresh();
  }, 2000);
  return { setScene, refresh };
}
