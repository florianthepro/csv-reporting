<?php
declare(strict_types=1);
$csvreporting_jsonfile = __DIR__ . '/../data/config.json';
if (!is_readable($csvreporting_jsonfile)) {
    http_response_code(500);
    exit('config.json konnte nicht gelesen werden.');
}
$csvreporting_data = json_decode((string) file_get_contents($csvreporting_jsonfile), true);
if (!is_array($csvreporting_data)) {
    http_response_code(500);
    exit('config.json enthält kein gültiges JSON.');
}
$csvreporting_json = json_encode($csvreporting_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($csvreporting_json)) {
    http_response_code(500);
    exit('config.json konnte nicht verarbeitet werden.');
}
?>
<!doctype html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Regeldokumentation</title>
    <style>
        :root {
            --bg: #fff;
            --fg: #151515;
            --muted: #656b73;
            --border: #d7d9de;
            --panel: #f5f6f8;
            --panel2: #eceef1;
            --accent: #1769e0;
            --green: #177245;
            --greenbg: #e8f5ed;
            --yellow: #806100;
            --yellowbg: #fff3c4;
            --red: #b3261e
        }

        @media(prefers-color-scheme:dark) {
            :root {
                --bg: #101214;
                --fg: #eceef1;
                --muted: #a7adb5;
                --border: #30343a;
                --panel: #181b1f;
                --panel2: #22262c;
                --accent: #72a7ff;
                --green: #65d79a;
                --greenbg: #153626;
                --yellow: #e9ca58;
                --yellowbg: #3a3110;
                --red: #ff7870
            }
        }

        * {
            box-sizing: border-box
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 18px;
            background: var(--bg);
            color: var(--fg)
        }

        .head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 12px
        }

        .head h2 {
            margin: 0
        }

        .home {
            font-size: 13px;
            color: var(--muted);
            text-decoration: none;
            white-space: nowrap
        }

        .home:hover {
            color: var(--accent);
            text-decoration: underline
        }

        .filter-toggle {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 10px;
            margin-bottom: 12px;
            cursor: pointer;
            display: flex;
            justify-content: space-between
        }

        .filter-toggle:hover {
            background: var(--panel2)
        }

        .filter {
            display: none;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 12px;
            margin-bottom: 12px
        }

        .filter.visible {
            display: block
        }

        .filter-item {
            margin-bottom: 10px
        }

        .filter-item:last-child {
            margin-bottom: 0
        }

        .filter-label {
            font-size: 12px;
            font-weight: bold;
            color: var(--muted);
            margin-bottom: 4px
        }

        .filter select {
            width: 100%;
            padding: 5px 7px;
            border: 1px solid var(--border);
            border-radius: 3px;
            background: var(--bg);
            color: var(--fg);
            font: inherit
        }

        .rules {
            display: grid;
            gap: 8px
        }

        .rule {
            border: 1px solid var(--border);
            background: var(--bg)
        }

        .rule summary {
            list-style: none;
            display: grid;
            grid-template-columns: 36px 1fr auto 18px;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            background: var(--panel);
            cursor: pointer
        }

        .rule summary::-webkit-details-marker {
            display: none
        }

        .rule summary:after {
            content: '+';
            font-weight: bold;
            color: var(--accent)
        }

        .rule[open] summary:after {
            content: '−'
        }

        .number {
            font-size: 12px;
            color: var(--muted)
        }

        .title {
            font-weight: bold;
            overflow-wrap: anywhere
        }

        .status {
            font-size: 11px;
            font-weight: bold;
            border-radius: 999px;
            padding: 2px 8px
        }

        .active {
            color: var(--green);
            background: var(--greenbg)
        }

        .inactive {
            color: var(--yellow);
            background: var(--yellowbg)
        }

        .body {
            padding: 8px 12px 12px
        }

        .condition {
            display: grid;
            grid-template-columns: minmax(140px, 230px) 30px 1fr;
            gap: 8px;
            padding: 7px 0;
            border-bottom: 1px solid var(--border)
        }

        .condition:last-child {
            border-bottom: 0
        }

        .column {
            font-weight: bold
        }

        .operator {
            text-align: center;
            color: var(--accent);
            font-weight: bold
        }

        .pattern {
            font-family: Consolas, "Courier New", monospace;
            overflow-wrap: anywhere
        }

        .info {
            border-top: 1px solid var(--border);
            padding-top: 10px;
            margin-top: 8px
        }

        .info-row {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 10px;
            margin: 6px 0
        }

        .info-label {
            font-size: 12px;
            font-weight: bold;
            color: var(--muted)
        }

        .info-value {
            white-space: pre-wrap;
            overflow-wrap: anywhere
        }

        .empty,
        .error {
            padding: 12px;
            border: 1px solid var(--border);
            background: var(--panel)
        }

        .error {
            color: var(--red)
        }

        @media(max-width:650px) {

            .condition,
            .info-row {
                grid-template-columns: 1fr
            }

            .operator {
                text-align: left
            }

            .rule summary {
                grid-template-columns: 30px 1fr 18px
            }

            .status {
                grid-column: 2
            }

            .rule summary:after {
                grid-column: 3;
                grid-row: 1/3
            }
        }
    </style>
</head>

<body>
    <div class="head">
        <h2>Regeldokumentation</h2><a class="home" href="../">Zur Startseite</a>
    </div>
    <div class="filter-toggle" id="filterToggle"><span>Filter</span><span id="filterIcon">▼</span></div>
    <div class="filter" id="filterPanel"></div>
    <div class="rules" id="rules"></div>
    <script>
        const DOCUMENTATION_DATA = <?= $csvreporting_json ?>; let RULES = []; const $ = s => document.querySelector(s); const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        function decode(v) { return String(v ?? '').replace(/^!...\s*/, '').split('$$$$$≠$$$$$').join('≠').split('$$$$$=$$$$$').join('=').split('$$$$$&$$$$$').join('&').split('...&...').join(' & ').replace(/\.\.\./g, ' ').replace(/\s+/g, ' ').trim() }
        function fieldLabel(k) { return ({ alternative_title: 'Individueller Titel', info: 'Interne Notiz', overlay_notes: 'Notiz im Index-Overlay', notes: 'Notiz' }[k] || String(k).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())) }
        function isUserField(k, v) { return !['description', 'title', 'name', 'conditions', 'stash_conditions'].includes(k) && (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') }
        function norm(r, i, cols) { r = r && typeof r === 'object' ? r : {}; const raw = String(r.description ?? r.title ?? r.name ?? `Regel ${i + 1}`), activeConditions = Array.isArray(r.conditions) ? r.conditions : [], inactive = activeConditions.length === 0, stash = Array.isArray(r.stash_conditions) ? r.stash_conditions : [], conditions = inactive ? stash : activeConditions, user = {}; Object.entries(r).forEach(([k, v]) => { if (isUserField(k, v) && String(v).trim() !== '') user[k] = String(v) }); const title = String(r.alternative_title || r.title || decode(raw) || `Regel ${i + 1}`), used = [...new Set(conditions.map(c => String(c?.column ?? c?.field ?? '').trim()).filter(Boolean))], order = used.map(c => { let x = cols.indexOf(c); return x < 0 ? 999999 : x }).sort((a, b) => a - b).join('|'); return { source: i + 1, inactive, title, conditions, user, used, order } }
        function render(r) { const cond = r.conditions.length ? r.conditions.map(c => `<div class="condition"><div class="column">${esc(c.column ?? c.field ?? 'Spalte')}</div><div class="operator">${c.negate ? '≠' : '='}</div><div class="pattern">${esc(c.pattern ?? c.value ?? '')}</div></div>`).join('') : '<div class="condition"><div class="pattern">Keine Bedingungen angegeben</div></div>'; const entries = Object.entries(r.user), info = entries.length ? `<div class="info">${entries.map(([k, v]) => `<div class="info-row"><div class="info-label">${esc(fieldLabel(k))}</div><div class="info-value">${esc(v)}</div></div>`).join('')}</div>` : ''; return `<details class="rule"><summary><span class="number">#${r.source}</span><span class="title">${esc(r.title)}</span><span class="status ${r.inactive ? 'inactive' : 'active'}">${r.inactive ? 'Inaktiv' : 'Aktiv'}</span></summary><div class="body">${cond}${info}</div></details>` }
        function dokuFilterParam(key) { return 'doku_filter_' + key }
        function addSelect(key, title, options) { const item = document.createElement('div'); item.className = 'filter-item'; const lab = document.createElement('div'); lab.className = 'filter-label'; lab.textContent = title; const select = document.createElement('select'); select.dataset.filter = key; select.innerHTML = '<option value="">-- Alle --</option>' + options.map(([value, text]) => `<option value="${esc(value)}">${esc(text)}</option>`).join(''); const current = new URL(window.location.href).searchParams.get(dokuFilterParam(key)) || ''; if ([...select.options].some(o => o.value === current)) select.value = current; select.addEventListener('change', () => { const url = new URL(window.location.href), param = dokuFilterParam(key); if (select.value === '') url.searchParams.delete(param); else url.searchParams.set(param, select.value); history.replaceState(null, '', url); apply() }); item.append(lab, select); $('#filterPanel').appendChild(item) }
        function valueOptions(key) { const values = [...new Set(RULES.map(r => String(r.user[key] ?? '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'de', { numeric: true, sensitivity: 'base' })); return [['__na__', 'n/a'], ...values.map(v => [v, v])] }
        function buildFilters() { const panel = $('#filterPanel'); panel.innerHTML = ''; addSelect('status', 'Status', [['active', 'Aktiv'], ['inactive', 'Inaktiv']]); const conditionColumns = [...new Set(RULES.flatMap(r => r.conditions.map(c => String(c?.column ?? c?.field ?? '').trim())).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'de', { numeric: true, sensitivity: 'base' })); const conditionPatterns = [...new Set(RULES.flatMap(r => r.conditions.map(c => String(c?.pattern ?? c?.value ?? '').trim())).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'de', { numeric: true, sensitivity: 'base' })); addSelect('condition_column', 'Bedingung: Spalte', conditionColumns.map(v => [v, v])); addSelect('condition_operator', 'Bedingung: Operator', [['=', '='], ['≠', '≠']]); addSelect('condition_pattern', 'Bedingung: Muster', conditionPatterns.map(v => [v, v])); const standard = ['alternative_title', 'info', 'overlay_notes']; standard.forEach(k => addSelect(k, fieldLabel(k), valueOptions(k))); const extra = [...new Set(RULES.flatMap(r => Object.keys(r.user)).filter(k => !standard.includes(k)))]; extra.forEach(k => addSelect(k, fieldLabel(k), valueOptions(k))) }
        function matches(r, key, value) { if (key === 'status') return value === (r.inactive ? 'inactive' : 'active'); if (key === 'condition_column') return r.conditions.some(c => String(c?.column ?? c?.field ?? '').trim() === value); if (key === 'condition_operator') return r.conditions.some(c => (c?.negate ? '≠' : '=') === value); if (key === 'condition_pattern') return r.conditions.some(c => String(c?.pattern ?? c?.value ?? '').trim() === value); const actual = String(r.user[key] ?? '').trim(); return value === '__na__' ? actual === '' : actual === value }
        function apply() { const selected = [...document.querySelectorAll('[data-filter]')].filter(x => x.value !== ''); let visible = 0; document.querySelectorAll('.rule').forEach((el, i) => { const show = selected.every(f => matches(RULES[i], f.dataset.filter, f.value)); el.hidden = !show; if (show) visible++ }); let empty = $('#empty'); if (!visible && !empty) { $('#rules').insertAdjacentHTML('beforeend', '<div class="empty" id="empty">Keine Regeln entsprechen den Filtern.</div>'); empty = $('#empty') } if (empty) empty.hidden = visible > 0 }
        function load() { try { const json = DOCUMENTATION_DATA, source = Array.isArray(json) ? json : Array.isArray(json.rules) ? json.rules : [], columns = Array.isArray(json.show_columns) ? json.show_columns : []; RULES = source.map((r, i) => norm(r, i, columns)); const inactive = RULES.filter(r => r.inactive).sort((a, b) => a.title.localeCompare(b.title, undefined, { numeric: true, sensitivity: 'base' }) || a.source - b.source), active = RULES.filter(r => !r.inactive).sort((a, b) => a.order.localeCompare(b.order, undefined, { numeric: true }) || a.title.localeCompare(b.title, undefined, { numeric: true, sensitivity: 'base' }) || a.source - b.source); RULES = [...inactive, ...active]; buildFilters(); $('#rules').innerHTML = RULES.map(render).join(''); apply() } catch (error) { $('#rules').innerHTML = `<div class="error">Regeldokumentation konnte nicht geladen werden: ${esc(error.message)}</div>` } }
        $('#filterToggle').addEventListener('click', () => { const panel = $('#filterPanel'); panel.classList.toggle('visible'); $('#filterIcon').textContent = panel.classList.contains('visible') ? '▲' : '▼' }); load();
    </script>
</body>

</html>
