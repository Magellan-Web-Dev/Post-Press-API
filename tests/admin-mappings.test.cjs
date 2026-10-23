/**
 * DOM integration tests using the real PHP-rendered editor and production script.
 * Install jsdom outside the plugin, then run:
 * NODE_PATH=/path/to/node_modules node --test tests/admin-mappings.test.cjs
 */
'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const fixture = JSON.parse(execFileSync(process.env.PPAPI_TEST_PHP || 'php', [path.join(__dirname, 'fixtures/mapping-editor.php')], { encoding: 'utf8' }));
const script = readFileSync(path.join(__dirname, '../assets/js/admin.js'), 'utf8');

/** Starts the actual editor with optional saved rows/catalog setup and no HTTP access. */
function editor(t, savedRows, configure) {
    const dom = new JSDOM(fixture.html, { runScripts: 'outside-only', url: 'https://example.test/wp-admin/admin.php' });
    t.after(() => dom.window.close());
    const window = dom.window;
    window.confirm = () => true;
    window.wp = { apiFetch: () => { throw new Error('Unexpected HTTP request'); } };
    window.eval(fixture.script);
    if (savedRows) {
        window.ppapiEditor.mappings = savedRows;
    }
    if (configure) {
        configure(window.ppapiEditor);
    }
    window.eval(script);
    const document = window.document;
    return {
        window, document,
        get: id => document.getElementById('ppapi-m-' + id),
        rows: () => JSON.parse(document.getElementById('ppapi-mappings-json').value),
        dirty: () => !document.getElementById('ppapi-dirty-note').hidden,
    };
}

/** Edits an input just as typing would, without committing a position change. */
function type(ui, id, value) {
    const input = ui.get(id);
    input.value = value;
    input.dispatchEvent(new ui.window.Event('input', { bubbles: true }));
    return input;
}

/** Applies the one-based position with the row's explicit Move button. */
function move(ui, row, position) {
    type(ui, row + '-position', String(position));
    ui.get(row + '-position-apply').click();
}

/** Selects an option and dispatches the same change event as the browser. */
function choose(ui, id, value) {
    const input = ui.get(id);
    input.value = value;
    input.dispatchEvent(new ui.window.Event('change', { bubbles: true }));
}

test('conditions are opt-in and count settings survive disabling, moving, and reload', t => {
    const ui = editor(t);
    assert.equal(ui.dirty(), false);
    assert.equal(ui.get('row1-condition-enabled').checked, false);
    assert.equal(ui.get('row1-condition-operator'), null);
    ui.get('row1-condition-enabled').click();
    assert.equal(ui.get('row1-condition-operator').value, 'count_eq');
    assert.equal(ui.get('row1-condition-value').value, '1');
    choose(ui, 'row1-condition-operator', 'count_lte');
    type(ui, 'row1-condition-value', '0');
    choose(ui, 'row1-condition-action', 'clear');
    const condition = { enabled: true, operator: 'count_lte', value: '0', case_sensitive: false, action: 'clear' };
    assert.deepEqual(ui.rows()[0].condition, condition);
    assert.equal(ui.dirty(), true);
    ui.get('row1-condition-enabled').click();
    assert.equal(ui.get('row1-condition-value'), null);
    assert.equal(ui.rows()[0].condition.value, '0');
    ui.get('row1-condition-enabled').click();
    move(ui, 'row1', 30);
    assert.deepEqual(ui.rows()[29].condition, condition);
    const reloaded = editor(t, ui.rows());
    assert.deepEqual(reloaded.rows()[29].condition, condition);
    assert.equal(reloaded.get('row1-condition-action').value, 'clear');
});

test('text condition controls preserve literal input and case sensitivity', t => {
    const ui = editor(t);
    ui.get('row1-condition-enabled').click();
    choose(ui, 'row1-condition-operator', 'contains');
    type(ui, 'row1-condition-value', '<script>window.bad = true</script>?.*');
    ui.get('row1-condition-case').click();
    assert.equal(ui.window.bad, undefined);
    assert.equal(ui.rows()[0].condition.case_sensitive, true);
    choose(ui, 'row1-condition-operator', 'not_contains');
    const reloaded = editor(t, ui.rows());
    assert.equal(reloaded.get('row1-condition-case').checked, true);
    assert.equal(reloaded.get('row1-condition-value').value, '<script>window.bad = true</script>?.*');
    assert.equal(reloaded.window.bad, undefined);
    assert.equal(reloaded.rows()[1].condition.enabled, false);
});

test('repeater subfield condition actions exclude preserve and retain row skipping', t => {
    const ui = editor(t);
    ui.get('date-condition-enabled').click();
    assert.deepEqual(Array.from(ui.get('date-condition-action').options, option => option.value), ['clear', 'skip_row']);
    assert.equal(ui.get('date-condition-action').value, 'clear');
    choose(ui, 'date-condition-operator', 'contains');
    type(ui, 'date-condition-value', 'Cancelled');
    choose(ui, 'date-condition-action', 'skip_row');
    const rows = ui.rows();
    assert.equal(rows[10].children[0].condition.action, 'skip_row');
    assert.equal(rows[10].children[1].condition.enabled, false);
    const reloaded = editor(t, rows);
    assert.equal(reloaded.get('date-condition-action').value, 'skip_row');
});

test('non-clearable destinations restrict condition actions to preserve', t => {
    const ui = editor(t, [{ id: 'slug', source: 'title', source_type: 'path', target_type: 'core', target: 'post_name',
        condition: { enabled: true, operator: 'contains', value: '?', action: 'clear' } }]);
    assert.deepEqual(Array.from(ui.get('slug-condition-action').options, option => option.value), ['preserve']);
    assert.equal(ui.rows()[0].condition.action, 'preserve');
});

test('legacy rows acquire disabled conditions without marking the editor dirty', t => {
    const original = editor(t);
    const oldRows = original.rows();
    function removeConditions(rows) {
        for (const row of rows) {
            delete row.condition;
            removeConditions(row.children);
        }
    }
    removeConditions(oldRows);
    const ui = editor(t, oldRows);
    assert.equal(ui.dirty(), false);
    assert.equal(ui.rows()[0].condition.enabled, false);
    assert.equal(ui.rows()[10].children[0].condition.enabled, false);
});

test('condition restrictions follow repeater ancestry through a nested group', t => {
    const rows = [{ id: 'outer', source: 'items', target_type: 'acf', target: 'field_test_outer', children: [
        { id: 'inner', source: 'details', target_type: 'acf', target: 'field_test_inner', children: [
            { id: 'leaf', source: 'text', target_type: 'acf', target: 'field_test_leaf',
                condition: { enabled: true, operator: 'contains', value: '?', action: 'preserve' } },
        ] },
    ] }];
    const ui = editor(t, rows, data => {
        data.catalog.acf.groups.push({ key: 'group_cond', title: 'Nested conditions', fields: [
            { key: 'field_test_outer', name: 'outer', label: 'Outer', type: 'repeater', supported: true, container: true, children: [
                { key: 'field_test_inner', name: 'inner', label: 'Inner', type: 'group', supported: true, container: true, children: [
                    { key: 'field_test_leaf', name: 'leaf', label: 'Leaf', type: 'text', supported: true, container: false, children: [] },
                ] },
            ] },
        ] });
    });
    assert.deepEqual(Array.from(ui.get('leaf-condition-action').options, option => option.value), ['clear', 'skip_row']);
    assert.equal(ui.rows()[0].children[0].children[0].condition.action, 'clear');
});

test('Join checkbox reveals date controls and retains entered options across toggles/reload', t => {
    const ui = editor(t);
    assert.equal(ui.dirty(), false);
    assert.equal(ui.get('row1-opt-join_datetime').checked, false);
    assert.equal(ui.get('row1-opt-output_format'), null);
    ui.get('row1-opt-join_datetime').click();
    assert.equal(ui.get('row1-opt-output_format').value, 'F j');
    assert.ok(ui.get('row1-opt-input_format'));
    assert.ok(ui.get('row1-opt-input_timezone'));
    assert.ok(ui.get('row1-opt-output_timezone'));
    type(ui, 'row1-opt-input_format', 'Y-m-d');
    type(ui, 'row1-opt-output_timezone', 'America/Phoenix');
    assert.equal(ui.rows()[0].options.join_datetime, true);
    assert.equal(ui.rows()[0].options.output_timezone, 'America/Phoenix');
    assert.equal(ui.dirty(), true);
    assert.equal(ui.document.activeElement.id, 'ppapi-m-row1-opt-join_datetime');
    ui.get('row1-opt-join_datetime').click();
    assert.equal(ui.rows()[0].options.join_datetime, false);
    assert.equal(ui.get('row1-opt-output_format'), null);
    ui.get('row1-opt-join_datetime').click();
    assert.equal(ui.get('row1-opt-input_format').value, 'Y-m-d');
    assert.equal(ui.get('row1-opt-output_timezone').value, 'America/Phoenix');
    // Render a fresh page using the serialized settings as a saved preset would.
    const reloaded = editor(t, ui.rows());
    assert.equal(reloaded.get('row1-opt-join_datetime').checked, true);
    assert.equal(reloaded.get('row1-opt-input_format').value, 'Y-m-d');
});

test('numeric Move jumps from last to first and first to last without losing settings', t => {
    const ui = editor(t);
    const original = ui.rows();
    assert.equal(ui.get('row30-position').value, '30');
    assert.equal(ui.get('row30-position').max, '30');
    move(ui, 'row30', 1);
    assert.deepEqual(ui.rows(), [original[29], ...original.slice(0, 29)]);
    assert.equal(ui.get('row30-position').value, '1');
    assert.equal(ui.get('row30-up').disabled, true);
    assert.equal(ui.get('row29-down').disabled, true);
    assert.equal(ui.document.activeElement.id, 'ppapi-m-row30-position');
    assert.equal(ui.dirty(), true);
    move(ui, 'row30', 30);
    assert.deepEqual(ui.rows(), original);
    const form = ui.document.getElementById('ppapi-preset-form');
    form.dispatchEvent(new ui.window.Event('submit', { cancelable: true }));
    const submitted = JSON.parse(new ui.window.FormData(form).get('ppapi_mappings'));
    assert.equal(submitted[29].id, 'row30');
    const reloaded = editor(t, submitted);
    assert.deepEqual(reloaded.rows(), original);
});

test('Enter applies the position without submitting the preset', t => {
    const ui = editor(t);
    let submits = 0;
    ui.document.getElementById('ppapi-preset-form').addEventListener('submit', () => submits++);
    const input = type(ui, 'row30-position', '12');
    const event = new ui.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    input.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(submits, 0);
    assert.equal(ui.rows()[11].id, 'row30');
    assert.equal(ui.get('row30-position').value, '12');
    const order = ui.rows();
    move(ui, 'row30', 12);
    assert.deepEqual(ui.rows(), order, 'same position is a no-op');
});

test('invalid positions leave order intact and provide a labeled error', t => {
    const ui = editor(t);
    const original = ui.rows();
    for (const value of ['', '0', '-1', '31', '1.5', 'abc', '99999999999999999']) {
        type(ui, 'row30-position', value);
        ui.get('row30-position-apply').click();
        assert.deepEqual(ui.rows(), original);
        assert.equal(ui.get('row30-position').getAttribute('aria-invalid'), 'true');
        assert.equal(ui.get('row30-position-error').hidden, false);
        assert.match(ui.get('row30-position-error').textContent, /1 to 30/);
        assert.equal(ui.document.activeElement, ui.get('row30-position'));
    }
    move(ui, 'row30', 2);
    assert.equal(ui.rows()[1].id, 'row30');
    assert.equal(ui.get('row30-position').hasAttribute('aria-invalid'), false);
    assert.equal(ui.get('row30-position-error').hidden, true);
});

test('nested group and repeater controls reorder only siblings and move with parents', t => {
    const ui = editor(t);
    const before = ui.rows();
    assert.equal(ui.get('advisor-position').max, '2');
    move(ui, 'advisor', 1);
    assert.deepEqual(ui.rows().map(row => row.id), before.map(row => row.id));
    assert.deepEqual(ui.rows()[9].children, [...before[9].children].reverse());
    assert.equal(ui.get('host-opt-join_datetime').checked, true);
    move(ui, 'time', 1);
    assert.deepEqual(ui.rows()[10].children, [...before[10].children].reverse());
    const group = ui.rows()[9];
    move(ui, 'group', 30);
    assert.deepEqual(ui.rows()[29], group);
    assert.equal(ui.get('host-position').value, '2');
    assert.equal(ui.get('group-position').value, '30');
});

test('existing Up/Down buttons still move rows and mark the preset dirty', t => {
    const ui = editor(t);
    assert.equal(ui.dirty(), false);
    ui.get('row30-up').click();
    assert.equal(ui.rows()[28].id, 'row30');
    assert.equal(ui.get('row30-position').value, '29');
    assert.equal(ui.dirty(), true);
    ui.get('row30-down').click();
    assert.equal(ui.rows()[29].id, 'row30');
    assert.equal(ui.get('row30-down').disabled, true);
});

test('removing a row updates numeric limits and never submits the form', t => {
    const ui = editor(t);
    const count = ui.rows().length;
    ui.get('row30-remove').click();
    assert.equal(ui.rows().length, count - 1);
    assert.equal(ui.get('row29-position').max, '29');
    assert.equal(ui.get('row29-position').value, '29');
    assert.equal(ui.get('row29-position-apply').type, 'button');
    const ids = Array.from(ui.document.querySelectorAll('[id]'), element => element.id);
    assert.equal(new Set(ids).size, ids.length, 'all input/label IDs are unique');
});
