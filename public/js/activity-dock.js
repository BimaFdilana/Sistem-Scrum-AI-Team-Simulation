/* Dock aktivitas mengambang: polling adaptif, ETag, status disimpan di localStorage. */
(function () {
    'use strict';

    var root = document.getElementById('activity-dock');
    if (!root) { return; }

    var endpoint = root.dataset.endpoint;
    var csrf = root.dataset.csrf;
    var baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
    var KEY_OPEN = 'scrumspace-dock-open';
    var KEY_DISMISSED = 'scrumspace-dock-dismissed';

    var runs = [];
    var open = read(KEY_OPEN) === '1';
    var dismissed = parse(read(KEY_DISMISSED), []);
    var lastStatus = {};
    var errors = 0;
    var timer = null;
    var busy = {};
    var lastSignature = '';

    var pill = el('button', 'dock-pill');
    pill.type = 'button';
    pill.setAttribute('aria-expanded', 'false');
    pill.setAttribute('aria-controls', 'activity-dock-panel');
    var panel = el('div', 'dock-panel');
    panel.id = 'activity-dock-panel';
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', 'Aktivitas eksekusi');
    panel.hidden = true;
    var live = el('div', 'dock-sr');
    live.setAttribute('aria-live', 'polite');
    live.setAttribute('role', 'status');
    root.append(panel, pill, live);

    pill.addEventListener('click', function () { setOpen(!open); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && open) { setOpen(false); pill.focus(); }
    });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { clearTimeout(timer); } else { poll(); }
    });

    function read(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
    function write(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* abaikan */ } }
    function parse(raw, fallback) { try { return raw ? JSON.parse(raw) : fallback; } catch (e) { return fallback; } }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function setOpen(value) {
        open = value;
        write(KEY_OPEN, value ? '1' : '0');
        render();
        schedule();
    }

    function visibleRuns() {
        return runs.filter(function (r) { return !(r.state === 'done' && dismissed.indexOf(r.id) !== -1); });
    }

    function overall(list) {
        if (list.some(function (r) { return r.state === 'attention'; })) { return 'attention'; }
        if (list.some(function (r) { return r.state === 'running'; })) { return 'running'; }
        return 'done';
    }

    function render() {
        var list = visibleRuns();
        root.hidden = list.length === 0;
        if (list.length === 0) { updateTitle(0); return; }

        var state = overall(list);
        var active = list.filter(function (r) { return r.state !== 'done'; }).length;
        pill.className = 'dock-pill dock-state-' + state;
        pill.setAttribute('aria-expanded', String(open));
        pill.replaceChildren();
        pill.append(el('span', 'dock-dot'));
        pill.append(el('span', 'dock-pill-text', pillText(list, state)));
        if (list.length > 1) { pill.append(el('span', 'dock-pill-count', String(list.length))); }

        panel.hidden = !open;
        if (open) { renderPanel(list); }
        updateTitle(active);
    }

    function pillText(list, state) {
        var focus = list.find(function (r) { return r.state === state; }) || list[0];
        if (list.length > 1) {
            var attention = list.filter(function (r) { return r.state === 'attention'; }).length;
            return attention > 0 ? attention + ' perlu tindakan' : list.length + ' eksekusi';
        }
        var progress = focus.progress.total > 0 ? ' · ' + focus.progress.done + '/' + focus.progress.total + ' task' : '';
        return focus.headline + progress;
    }

    function renderPanel(list) {
        var head = el('div', 'dock-head');
        head.append(el('strong', '', 'Aktivitas eksekusi'));
        var close = el('button', 'dock-close', 'Perkecil');
        close.type = 'button';
        close.addEventListener('click', function () { setOpen(false); pill.focus(); });
        head.append(close);

        var nodes = [head];
        list.forEach(function (run) { nodes.push(renderRun(run)); });
        panel.replaceChildren.apply(panel, nodes);
    }

    function renderRun(run) {
        var card = el('article', 'dock-run is-' + run.state);
        var top = el('div', 'dock-run-top');
        var titleWrap = el('div');
        titleWrap.append(el('h3', 'dock-run-title', run.label), el('p', 'dock-run-headline', run.headline));
        var badgeText = run.state === 'running' ? 'BERJALAN' : (run.state === 'attention' ? 'PERLU TINDAKAN' : 'SELESAI');
        top.append(titleWrap, el('span', 'dock-badge is-' + run.state, badgeText));
        card.append(top);

        var steps = el('ol', 'dock-steps');
        run.stage.steps.forEach(function (step, index) {
            var li = el('li', index < run.stage.index || run.state === 'done' ? 'is-done' : (index === run.stage.index ? 'is-current' : ''));
            li.append(el('i'), el('span', '', step.label));
            steps.append(li);
        });
        card.append(steps);

        if (run.tasks.length) {
            var tasks = el('ul', 'dock-tasks');
            run.tasks.forEach(function (t) {
                var li = el('li');
                var mark = el('span', 'dock-task-mark ' + taskClass(t.status));
                mark.setAttribute('aria-hidden', 'true');
                li.append(mark, el('span', '', t.assignee + ' · ' + t.title));
                tasks.append(li);
            });
            card.append(tasks);
        }

        if (run.log.length) {
            var log = el('ul', 'dock-log');
            run.log.forEach(function (m) {
                var li = el('li');
                li.append(el('b', '', m.who + ': '), document.createTextNode(m.text));
                log.append(li);
            });
            card.append(log);
        }

        var actions = el('div', 'dock-actions');
        (run.actions || []).forEach(function (action) {
            var btn = el('button', 'dock-btn is-primary', action.label);
            btn.type = 'button';
            btn.disabled = !!busy[run.id];
            btn.addEventListener('click', function () { runAction(run, action, btn); });
            actions.append(btn);
        });
        if (run.detail_url) {
            var link = el('a', 'dock-btn', 'Buka detail');
            link.href = run.detail_url;
            actions.append(link);
        }
        if (run.state === 'done') {
            var dismiss = el('button', 'dock-btn', 'Tutup');
            dismiss.type = 'button';
            dismiss.addEventListener('click', function () {
                dismissed.push(run.id);
                write(KEY_DISMISSED, JSON.stringify(dismissed.slice(-50)));
                render();
            });
            actions.append(dismiss);
        }
        card.append(actions);

        return card;
    }

    function taskClass(status) {
        if (status === 'APPROVED' || status === 'QA_PENDING') { return 'is-ok'; }
        if (status === 'IN_PROGRESS' || status === 'IN_REVIEW') { return 'is-live'; }
        if (status === 'REVISION' || status === 'BLOCKED' || status === 'FAILED') { return 'is-warn'; }
        return '';
    }

    function runAction(run, action, btn) {
        busy[run.id] = true;
        btn.disabled = true;
        fetch(action.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).catch(function () {
            announce(run.label + ': aksi gagal dikirim, coba lagi.');
        }).finally(function () {
            busy[run.id] = false;
            poll();
        });
    }

    function announce(text) { live.textContent = ''; setTimeout(function () { live.textContent = text; }, 50); }

    function updateTitle(n) { document.title = n > 0 ? '(' + n + ') ' + baseTitle : baseTitle; }

    function detectChanges(next) {
        next.forEach(function (run) {
            if (lastStatus[run.id] !== undefined && lastStatus[run.id] !== run.status) {
                announce(run.label + ': ' + run.headline);
            }
            lastStatus[run.id] = run.status;
        });
    }

    function poll() {
        clearTimeout(timer);
        if (document.hidden) { return; }
        fetch(endpoint, {
            credentials: 'same-origin',
            cache: 'no-cache',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            if (!res.ok) { throw new Error('http ' + res.status); }
            return res.json();
        }).then(function (data) {
            errors = 0;
            // Render ulang hanya bila data berubah: menjaga fokus, posisi gulir, dan klik yang sedang berjalan.
            var signature = JSON.stringify(data);
            if (signature === lastSignature) { return; }
            lastSignature = signature;
            detectChanges(data.runs);
            runs = data.runs;
            render();
        }).catch(function () {
            errors += 1;
        }).finally(schedule);
    }

    function schedule() {
        clearTimeout(timer);
        if (document.hidden) { return; }
        var delay = errors > 0 ? Math.min(30000, 3000 * Math.pow(2, errors)) : (open ? 3000 : 10000);
        timer = setTimeout(poll, delay);
    }

    poll();
})();
