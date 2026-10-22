/**
 * Post Press API admin screens.
 *
 * - Confirmation prompts for destructive links ([data-ppapi-confirm]).
 * - Conditional sections ([data-ppapi-show-for] / [data-ppapi-show-values]).
 * - Repeatable query/header rows.
 * - The mapping editor, which keeps mapping rows in the hidden
 *   #ppapi-mappings-json field that the server validates on save.
 * - Test Request, Preview/Dry Run, and Run Now via the ppapi/v1 REST routes.
 *
 * Every value from the server or the API is inserted with textContent, never as HTML.
 */
(function () {
	'use strict';

	var data = window.ppapiEditor || null;
	var i18n = (data && data.i18n) || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	var uid = 0;

	/* ------------------------------------------------------------------ */
	/* Utilities                                                           */
	/* ------------------------------------------------------------------ */

	function sprintf(format) {
		var args = Array.prototype.slice.call(arguments, 1);
		var index = 0;
		return String(format || '').replace(/%(?:(\d+)\$)?([sd%])/g, function (match, position, type) {
			if (type === '%') {
				return '%';
			}
			var value = position ? args[parseInt(position, 10) - 1] : args[index++];
			if (type === 'd') {
				return String(parseInt(value, 10) || 0);
			}
			return value === undefined || value === null ? '' : String(value);
		});
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === null || value === undefined || value === false) {
				return;
			}
			if (key === 'text') {
				node.textContent = String(value);
			} else if (key === 'className') {
				node.className = value;
			} else if (key === 'hidden') {
				node.hidden = !!value;
			} else if (key === 'value') {
				node.value = String(value);
			} else if (key === 'checked' || key === 'disabled' || key === 'selected') {
				node[key] = !!value;
			} else if (key.indexOf('on') === 0 && typeof value === 'function') {
				node.addEventListener(key.slice(2), value);
			} else {
				node.setAttribute(key, value === true ? '' : String(value));
			}
		});
		(children || []).forEach(function (child) {
			if (child === null || child === undefined || child === false) {
				return;
			}
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function clear(node) {
		while (node && node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function option(value, label, selected, disabled) {
		return el('option', { value: value, selected: selected, disabled: disabled, text: label });
	}

	function select(id, label, options, value, onChange, extra) {
		var control = el('select', Object.assign({ id: id }, extra || {}), options.map(function (opt) {
			if (opt.group) {
				return el('optgroup', { label: opt.group }, opt.options.map(function (inner) {
					return option(inner[0], inner[1], inner[0] === value, inner[2]);
				}));
			}
			return option(opt[0], opt[1], opt[0] === value, opt[2]);
		}));
		control.addEventListener('change', function () {
			onChange(control.value);
		});
		return el('div', { className: 'ppapi-control' }, [el('label', { for: id, text: label }), control]);
	}

	function textInput(id, label, value, onInput, extra) {
		var input = el('input', Object.assign({ type: 'text', id: id, value: value || '', spellcheck: 'false' }, extra || {}));
		input.addEventListener('input', function () {
			onInput(input.value);
		});
		return el('div', { className: 'ppapi-control' }, [el('label', { for: id, text: label }), input]);
	}

	function errorMessage(error) {
		if (!error) {
			return i18n.requestFailed;
		}
		var message = error.message || i18n.requestFailed;
		if (error.data && error.data.errors) {
			var details = [];
			Object.keys(error.data.errors).forEach(function (key) {
				(error.data.errors[key] || []).forEach(function (text) {
					details.push(text);
				});
			});
			if (details.length) {
				return details;
			}
		}
		return message;
	}

	function renderError(container, error) {
		clear(container);
		var message = errorMessage(error);
		var notice = el('div', { className: 'notice notice-error inline' });
		if (Array.isArray(message)) {
			notice.appendChild(el('ul', {}, message.map(function (text) {
				return el('li', { text: text });
			})));
		} else {
			notice.appendChild(el('p', { text: message }));
		}
		container.appendChild(notice);
	}

	function setBusy(button, busy) {
		if (!button) {
			return;
		}
		button.disabled = !!busy;
		button.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	/* ------------------------------------------------------------------ */
	/* Shared behaviors (all plugin screens)                               */
	/* ------------------------------------------------------------------ */

	document.addEventListener('click', function (event) {
		var target = event.target.closest('[data-ppapi-confirm]');
		if (target && !window.confirm(target.getAttribute('data-ppapi-confirm'))) {
			event.preventDefault();
		}
	});

	function controlValue(selector) {
		var nodes = document.querySelectorAll(selector);
		if (!nodes.length) {
			return '';
		}
		if (nodes[0].type === 'radio') {
			for (var i = 0; i < nodes.length; i++) {
				if (nodes[i].checked) {
					return nodes[i].value;
				}
			}
			return '';
		}
		return nodes[0].value;
	}

	function updateVisibility() {
		document.querySelectorAll('[data-ppapi-show-for]').forEach(function (node) {
			var values = (node.getAttribute('data-ppapi-show-values') || '').split(' ');
			node.hidden = values.indexOf(controlValue(node.getAttribute('data-ppapi-show-for'))) === -1;
		});
	}

	document.addEventListener('change', updateVisibility);
	updateVisibility();

	var pairCounter = 1000;
	document.addEventListener('click', function (event) {
		var add = event.target.closest('[data-ppapi-add-pair]');
		if (add) {
			var kind = add.getAttribute('data-ppapi-add-pair');
			var template = document.getElementById('ppapi-tpl-' + kind);
			var table = document.querySelector('[data-ppapi-pairs="' + kind + '"] tbody');
			if (template && table) {
				var html = template.innerHTML.replace(/__i__/g, String(pairCounter++));
				var holder = document.createElement('tbody');
				holder.innerHTML = html;
				var row = holder.firstElementChild;
				table.appendChild(row);
				var first = row.querySelector('input');
				if (first) {
					first.focus();
				}
				markDirty();
			}
			return;
		}
		var remove = event.target.closest('[data-ppapi-remove-pair]');
		if (remove) {
			var tr = remove.closest('tr');
			var tableNode = tr.closest('table');
			tr.parentNode.removeChild(tr);
			var addButton = tableNode && tableNode.nextElementSibling;
			if (addButton) {
				addButton.focus();
			}
			markDirty();
		}
	});

	var dirty = false;
	function markDirty() {
		dirty = true;
		var note = document.getElementById('ppapi-dirty-note');
		if (note) {
			note.hidden = false;
		}
	}

	function bindHistory(node) {
		if (!apiFetch) { return; }
		node.addEventListener('input', function (event) { event.stopPropagation(); });
		node.addEventListener('change', function (event) {
			event.stopPropagation();
			var boxes = Array.from(node.querySelectorAll('[data-history-id]'));
			var all = node.querySelector('[data-history-select-all]');
			if (event.target === all) { boxes.forEach(function (box) { box.checked = all.checked; }); }
			var count = boxes.filter(function (box) { return box.checked; }).length;
			if (all) { all.checked = boxes.length > 0 && count === boxes.length; all.indeterminate = count > 0 && count < boxes.length; }
			var selected = node.querySelector('[data-history-action="selected"]');
			if (selected) { selected.disabled = count === 0; }
		});
		node.addEventListener('click', function (event) {
			var button = event.target.closest('[data-history-action]');
			if (!button || button.disabled) { return; }
			event.preventDefault();
			var action = button.getAttribute('data-history-action');
			var ids = action === 'one' ? [button.getAttribute('data-run-id')] : Array.from(node.querySelectorAll('[data-history-id]:checked')).map(function (box) { return box.getAttribute('data-history-id'); });
			if (action !== 'all' && !ids.length) { return; }
			if (!window.confirm(node.getAttribute('data-confirm'))) { return; }
			node.querySelectorAll('button,input').forEach(function (control) { control.disabled = true; });
			apiFetch({
				path: '/ppapi/v1/presets/' + encodeURIComponent(node.getAttribute('data-preset')) + '/history/delete', method: 'POST',
				headers: { 'X-WP-Nonce': node.getAttribute('data-rest-nonce') },
				data: { run_ids: ids, clear_all: action === 'all', history_nonce: node.getAttribute('data-nonce'), limit: Number(node.getAttribute('data-limit')) }
			}).then(function (response) {
				// HTML is rendered and escaped by our authenticated history endpoint.
				var parsed = new DOMParser().parseFromString(response.html, 'text/html');
				var replacement = parsed.querySelector('[data-ppapi-history]');
				if (!replacement) { throw new Error('Invalid history response.'); }
				node.replaceWith(replacement);
				bindHistory(replacement);
				var feedback = replacement.querySelector('.ppapi-history-feedback');
				feedback.textContent = response.message;
				feedback.tabIndex = -1;
				feedback.focus();
			}).catch(function (error) {
				node.querySelectorAll('button,input').forEach(function (control) { control.disabled = false; });
				node.querySelector('.ppapi-history-feedback').textContent = error.message || String(error);
			});
		});
	}
	document.querySelectorAll('[data-ppapi-history]').forEach(bindHistory);

	if (!data) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/* Editor                                                              */
	/* ------------------------------------------------------------------ */

	var form = document.getElementById('ppapi-preset-form');
	var hidden = document.getElementById('ppapi-mappings-json');
	var mappingContainer = document.getElementById('ppapi-mappings');
	var postTypeSelect = document.getElementById('ppapi-post-type');
	var catalog = data.catalog || null;
	var recordPaths = data.recordPaths || [];
	var responsePaths = data.responsePaths || [];
	var limits = data.limits || { maxChildren: 50, maxRows: 100, maxDepth: 3 };
	var rows = (data.mappings || []).map(normalizeRow);

	form.addEventListener('input', function (event) {
		if (event.target !== hidden) {
			markDirty();
		}
	});
	form.addEventListener('change', markDirty);
	form.addEventListener('submit', function () {
		sync();
		dirty = false;
	});
	window.addEventListener('beforeunload', function (event) {
		if (dirty) {
			event.preventDefault();
			event.returnValue = '';
		}
	});

	function newId() {
		return 'r' + Date.now().toString(36) + (++uid).toString(36);
	}

	function defaultOptions() {
		return {
			separator: ', ',
			join_datetime: false,
			input_format: '',
			input_timezone: '',
			output_format: '',
			output_timezone: '',
			decimals: '',
			map: [],
			unmatched: 'keep'
		};
	}

	/** Restores saved row settings, including disabled conditions on older presets. */
	function normalizeRow(row) {
		var options = Object.assign(defaultOptions(), row.options || {});
		options.map = Array.isArray(options.map) ? options.map.map(function (pair) {
			return { from: String(pair.from || ''), to: String(pair.to || '') };
		}) : [];
		return {
			id: row.id || newId(),
			source_type: row.source_type || 'path',
			source: row.source === undefined || row.source === null ? '' : String(row.source),
			target_type: row.target_type || '',
			target: row.target || '',
			transform: row.transform || 'none',
			options: options,
			condition: Object.assign({ enabled: false, operator: 'count_eq', value: '1', case_sensitive: false, action: 'preserve' }, row.condition || {}),
			on_missing: row.on_missing || 'preserve',
			on_null: row.on_null || 'preserve',
			media: Object.assign({ download: false, output: 'id' }, row.media || {}),
			fallback: row.fallback === undefined || row.fallback === null ? '' : String(row.fallback),
			children: (row.children || []).map(normalizeRow)
		};
	}

	/** Creates a row with actions appropriate for top-level fields or repeater items. */
	function newRow(context) {
		var behavior = context === 'repeater' ? 'clear' : 'preserve';
		return normalizeRow({
			source_type: 'path',
			target_type: context === 'top' ? 'core' : 'acf',
			on_missing: behavior,
			on_null: behavior,
			condition: { action: behavior }
		});
	}

	function sync() {
		hidden.value = JSON.stringify(rows);
	}

	/* Catalog lookups --------------------------------------------------- */

	function acfIndex() {
		var index = {};
		function walk(fields) {
			(fields || []).forEach(function (field) {
				index[field.key] = field;
				walk(field.children);
			});
		}
		if (catalog && catalog.acf && catalog.acf.groups) {
			catalog.acf.groups.forEach(function (group) {
				walk(group.fields);
			});
		}
		return index;
	}

	function coreField(key) {
		var found = null;
		((catalog && catalog.core) || []).forEach(function (field) {
			if (field.key === key) {
				found = field;
			}
		});
		return found;
	}

	function fieldLabel(field) {
		return field.label + ' (' + field.name + ', ' + field.type + ')' + (field.supported ? '' : ' — ' + i18n.unsupported);
	}

	/* Path suggestions -------------------------------------------------- */

	function fillDatalist(id, items) {
		var list = document.getElementById(id);
		if (!list) {
			return;
		}
		clear(list);
		items.forEach(function (item) {
			list.appendChild(el('option', { value: item.path || item.value, label: item.label || item.type || '' }));
		});
	}

	function childPrefix(parentPrefix, source, isRepeater) {
		var prefix = parentPrefix;
		if (source && source !== '$') {
			var cleaned = source.replace(/^\$\.?/, '');
			prefix = prefix ? prefix + (cleaned.charAt(0) === '[' ? '' : '.') + cleaned : cleaned;
		}
		if (isRepeater && !/\[\*\]$/.test(prefix)) {
			prefix += '[*]';
		}
		return prefix;
	}

	function suggestionsFor(prefix) {
		if (!prefix) {
			return recordPaths;
		}
		var out = [{ path: '$', type: '' }];
		recordPaths.forEach(function (item) {
			var path = item.path;
			if (path.indexOf(prefix + '.') === 0) {
				out.push({ path: path.slice(prefix.length + 1), type: item.type });
			} else if (path.indexOf(prefix + '[') === 0) {
				out.push({ path: path.slice(prefix.length), type: item.type });
			}
		});
		return out;
	}

	function refreshStaticDatalists() {
		fillDatalist('ppapi-record-paths', recordPaths);
		fillDatalist('ppapi-response-paths', responsePaths);
		var acfFields = [];
		if (catalog && catalog.acf && catalog.acf.groups) {
			catalog.acf.groups.forEach(function (group) {
				group.fields.forEach(function (field) {
					if (['text', 'number', 'email', 'url'].indexOf(field.type) !== -1) {
						acfFields.push({ value: field.key, label: field.label + ' (' + field.name + ')' });
					}
				});
			});
		}
		fillDatalist('ppapi-acf-match-fields', acfFields);
	}

	/* Mapping rendering ------------------------------------------------- */

	function renderMappings() {
		var active = document.activeElement && document.activeElement.id;
		clear(mappingContainer);

		if (!rows.length) {
			var template = document.getElementById('ppapi-tpl-empty-mappings');
			if (template) {
				mappingContainer.appendChild(template.content.cloneNode(true));
			}
		}

		rows.forEach(function (row, index) {
			mappingContainer.appendChild(renderRow(row, index, rows, null, 0, ''));
		});

		sync();
		if (active) {
			var again = document.getElementById(active);
			if (again) {
				again.focus();
			}
		}
	}

	function behaviorOptions(context, clearable, isContainer) {
		if (context === 'repeater') {
			var repeaterOptions = [['clear', i18n.clearRow]];
			if (!isContainer) {
				repeaterOptions.push(['fallback', i18n.fallback]);
			}
			repeaterOptions.push(['skip_row', i18n.skipRow], ['fail', i18n.fail]);
			return repeaterOptions;
		}
		var options = [['preserve', i18n.preserve]];
		if (clearable) {
			options.push(['clear', i18n.clear]);
		}
		if (!isContainer) {
			options.push(['fallback', i18n.fallback]);
		}
		options.push(['fail', i18n.fail]);
		return options;
	}

	function coerceBehavior(value, options) {
		for (var i = 0; i < options.length; i++) {
			if (options[i][0] === value) {
				return value;
			}
		}
		return options[0][0];
	}

	/**
	 * Moves a mapping within its sibling list and keeps focus on that mapping.
	 * Child rows and settings travel with the row; no mappings cross containers.
	 *
	 * @param {Object} row Mapping being moved.
	 * @param {Array} list Its top-level or nested sibling list.
	 * @param {number} position Zero-based destination index.
	 * @param {string} focusId Control to focus after the editor is redrawn.
	 * @returns {void}
	 */
	function moveMapping(row, list, position, focusId) {
		var index = list.indexOf(row);
		if (index < 0 || index === position || position < 0 || position >= list.length) {
			return;
		}
		list.splice(position, 0, list.splice(index, 1)[0]);
		markDirty();
		renderMappings();
		var focus = document.getElementById(focusId);
		if (focus && focus.disabled) {
			focus = document.getElementById('ppapi-m-' + row.id + '-position');
		}
		if (focus) {
			focus.focus();
		}
	}

	/**
	 * Builds a one-based position control, applied with Move or the Enter key.
	 * Invalid positions leave the list intact and show an accessible inline error.
	 *
	 * @param {Object} row Mapping being positioned.
	 * @param {number} index Current zero-based position.
	 * @param {Array} list Siblings defining the allowed range.
	 * @param {string} base Unique control ID prefix for this mapping.
	 * @returns {HTMLElement} Position controls for the row action bar.
	 */
	function renderPositionControl(row, index, list, base) {
		var error = el('span', { id: base + '-position-error', className: 'ppapi-position-error', role: 'alert', hidden: true });
		var input = el('input', {
			type: 'number', id: base + '-position', className: 'small-text',
			min: 1, max: list.length, step: 1, value: index + 1,
			'aria-describedby': base + '-position-range ' + base + '-position-error'
		});
		function applyPosition() {
			var position = Number(input.value);
			if (!/^\d+$/.test(input.value) || !Number.isSafeInteger(position) || position < 1 || position > list.length) {
				error.textContent = sprintf(i18n.positionError, list.length);
				error.hidden = false;
				input.setAttribute('aria-invalid', 'true');
				input.focus();
				return;
			}
			error.hidden = true;
			input.removeAttribute('aria-invalid');
			moveMapping(row, list, position - 1, input.id);
		}
		input.addEventListener('input', function () {
			error.hidden = true;
			input.removeAttribute('aria-invalid');
		});
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				applyPosition();
			}
		});
		return el('span', { className: 'ppapi-mapping-position' }, [
			el('label', { for: input.id, text: i18n.moveToPosition }), input,
			el('span', { id: base + '-position-range', text: sprintf(i18n.positionRange, list.length) }),
			el('button', { type: 'button', className: 'button button-small', id: base + '-position-apply', text: i18n.move, onclick: applyPosition }),
			error
		]);
	}

	/** Renders a mapping with source, destination, condition, and value handling controls. */
	function renderRow(row, index, list, parent, depth, parentPrefix, insideRepeater) {
		var context = parent === null ? 'top' : (parent.type === 'repeater' ? 'repeater' : 'group');
		insideRepeater = !!insideRepeater || context === 'repeater';
		var acf = acfIndex();
		var descriptor = row.target_type === 'acf' ? acf[row.target] || null : null;
		var isContainer = !!(descriptor && descriptor.container);
		var base = 'ppapi-m-' + row.id;

		var clearable = true;
		if (row.target_type === 'core') {
			var core = coreField(row.target);
			clearable = core ? !!core.clearable : true;
		}

		var behaviors = behaviorOptions(context, clearable, isContainer);
		row.on_missing = coerceBehavior(row.on_missing, behaviors);
		row.on_null = coerceBehavior(row.on_null, behaviors);
		if (isContainer) {
			row.source_type = 'path';
			row.transform = 'none';
		}

		var targetText = '';
		if (descriptor) {
			targetText = descriptor.label;
		} else if (row.target_type === 'core' && coreField(row.target)) {
			targetText = coreField(row.target).label;
		} else if (row.target) {
			targetText = row.target;
		}

		var legend = sprintf(context === 'top' ? i18n.mapping : i18n.subMapping, index + 1) + (targetText ? ' → ' + targetText : '');
		var fieldset = el('fieldset', { className: 'ppapi-mapping' + (context === 'top' ? '' : ' ppapi-mapping--child') }, [
			el('legend', { text: legend })
		]);

		/* Source */
		var sourceCol = el('div', { className: 'ppapi-mapping__col' }, [el('h4', { text: i18n.source })]);
		sourceCol.appendChild(select(base + '-source-type', i18n.sourceType, [
			['path', i18n.sourcePath],
			['constant', i18n.sourceConstant, isContainer],
			['template', i18n.sourceTemplate, isContainer]
		], row.source_type, function (value) {
			row.source_type = value;
			renderMappings();
		}));

		var sourceLabel = row.source_type === 'constant' ? i18n.sourceConstant : (row.source_type === 'template' ? i18n.sourceTemplate : i18n.sourcePath);
		sourceCol.appendChild(textInput(base + '-source', sourceLabel, row.source, function (value) {
			row.source = value;
			sync();
		}, {
			list: row.source_type === 'path' ? (parent ? parent.listId : 'ppapi-record-paths') : null,
			className: 'code',
			placeholder: row.source_type === 'path' ? 'event.title' : (row.source_type === 'template' ? '{event.title} – {website.host}' : '')
		}));
		if (isContainer) {
			sourceCol.appendChild(el('p', { className: 'description', text: i18n.containerSourceHelp }));
		}
		fieldset.appendChild(sourceCol);

		/* Destination */
		var destCol = el('div', { className: 'ppapi-mapping__col' }, [el('h4', { text: i18n.destination })]);
		if (context === 'top') {
			destCol.appendChild(select(base + '-target-type', i18n.destinationType, [
				['core', i18n.destCore],
				['meta', i18n.destMeta],
				['acf', i18n.destAcf, !(catalog && catalog.acf && catalog.acf.available) && row.target_type !== 'acf']
			], row.target_type || 'core', function (value) {
				row.target_type = value;
				row.target = '';
				row.children = [];
				renderMappings();
			}));
		}

		if (!catalog) {
			destCol.appendChild(el('p', { className: 'description', text: i18n.chooseTypeFirst }));
		}

		if (context === 'top' && row.target_type === 'meta') {
			destCol.appendChild(textInput(base + '-target', i18n.metaKey, row.target, function (value) {
				row.target = value.trim();
				sync();
			}, { className: 'code', placeholder: 'my_meta_key' }));
		} else if (context === 'top' && (row.target_type === 'core' || !row.target_type)) {
			var coreOptions = [['', i18n.chooseField]];
			((catalog && catalog.core) || []).forEach(function (field) {
				coreOptions.push([field.key, field.label + ' (' + field.key + ')']);
			});
			destCol.appendChild(select(base + '-target', i18n.field, coreOptions, row.target, function (value) {
				row.target = value;
				renderMappings();
			}));
			var coreInfo = coreField(row.target);
			if (coreInfo && coreInfo.help) {
				destCol.appendChild(el('p', { className: 'description', text: coreInfo.help }));
			}
		} else {
			var acfOptions = [['', i18n.chooseField]];
			if (context === 'top') {
				((catalog && catalog.acf && catalog.acf.groups) || []).forEach(function (group) {
					acfOptions.push({
						group: group.title,
						options: group.fields.map(function (field) {
							return [field.key, fieldLabel(field), !field.supported];
						})
					});
				});
			} else {
				(parent.children || []).forEach(function (field) {
					acfOptions.push([field.key, fieldLabel(field), !field.supported]);
				});
			}
			if (row.target && !descriptor) {
				acfOptions.push([row.target, row.target]);
			}
			if (context === 'top' && catalog && catalog.acf && !catalog.acf.available) {
				destCol.appendChild(el('p', { className: 'ppapi-text-warning', text: i18n.acfInactive }));
			}
			destCol.appendChild(select(base + '-target', i18n.field, acfOptions, row.target, function (value) {
				row.target = value;
				row.children = [];
				renderMappings();
			}));
			if (descriptor) {
				destCol.appendChild(el('p', { className: 'description ppapi-field-info' }, [
					i18n.fieldKey + ': ', el('code', { text: descriptor.key }), ' · ' + i18n.fieldName + ': ',
					el('code', { text: descriptor.name }), ' · ' + i18n.fieldType + ': ' + descriptor.type,
					el('br'), descriptor.trail
				]));
				if (!descriptor.supported && descriptor.reason) {
					destCol.appendChild(el('p', { className: 'ppapi-text-warning', text: descriptor.reason }));
				}
			}
		}
		var mediaCompatible = row.target_type === 'meta' || (row.target_type === 'core' && row.target === 'featured_image') || (descriptor && ['image', 'url', 'text', 'textarea'].indexOf(descriptor.type) !== -1);
		if (mediaCompatible) {
			var mediaToggle = el('input', { type: 'checkbox', id: base + '-media', checked: row.media.download });
			mediaToggle.addEventListener('change', function () { row.media.download = mediaToggle.checked; renderMappings(); });
			destCol.appendChild(el('label', { for: base + '-media' }, [mediaToggle, ' ' + i18n.downloadImage]));
			if (row.media.download) {
				if (row.target_type === 'meta') {
					destCol.appendChild(select(base + '-media-output', i18n.mediaOutput, [['id', i18n.attachmentId], ['url', i18n.localMediaUrl]], row.media.output, function (value) { row.media.output = value; sync(); }));
				} else {
					row.media.output = row.target_type === 'core' || descriptor.type === 'image' ? 'id' : 'url';
				}
				destCol.appendChild(el('p', { className: 'description', text: i18n.imageHelp }));
			}
		} else {
			row.media.download = false;
		}
		fieldset.appendChild(destCol);

		fieldset.appendChild(renderCondition(row, base, insideRepeater ? 'repeater' : context, clearable));

		/* Transformation */
		if (!isContainer) {
			var transformCol = el('div', { className: 'ppapi-mapping__col' }, [el('h4', { text: i18n.transform })]);
			var transformOptions = Object.keys(data.transforms || {}).map(function (key) {
				return [key, data.transforms[key]];
			});
			transformCol.appendChild(select(base + '-transform', i18n.transform, transformOptions, row.transform, function (value) {
				row.transform = value;
				renderMappings();
			}));
			transformCol.appendChild(renderTransformOptions(row, base));
			fieldset.appendChild(transformCol);
		}

		/* Behaviors */
		var behaviorCol = el('div', { className: 'ppapi-mapping__col' }, [el('h4', { text: i18n.missingNull })]);
		behaviorCol.appendChild(select(base + '-missing', i18n.whenMissing, behaviors, row.on_missing, function (value) {
			row.on_missing = value;
			renderMappings();
		}));
		behaviorCol.appendChild(select(base + '-null', i18n.whenNull, behaviors, row.on_null, function (value) {
			row.on_null = value;
			renderMappings();
		}));
		var needsFallback = row.on_missing === 'fallback' || row.on_null === 'fallback' || (row.transform === 'map' && row.options.unmatched === 'fallback');
		if (needsFallback) {
			behaviorCol.appendChild(textInput(base + '-fallback', i18n.fallbackValue, row.fallback, function (value) {
				row.fallback = value;
				sync();
			}));
		}
		fieldset.appendChild(behaviorCol);

		/* Row actions */
		fieldset.appendChild(el('div', { className: 'ppapi-mapping__actions' }, [
			el('button', {
				type: 'button', className: 'button button-small', id: base + '-up', disabled: index === 0,
				text: i18n.moveUp,
				onclick: function () {
					moveMapping(row, list, index - 1, base + '-up');
				}
			}),
			el('button', {
				type: 'button', className: 'button button-small', id: base + '-down', disabled: index === list.length - 1,
				text: i18n.moveDown,
				onclick: function () {
					moveMapping(row, list, index + 1, base + '-down');
				}
			}),
			renderPositionControl(row, index, list, base),
			el('button', {
				type: 'button', className: 'button-link ppapi-link-delete', id: base + '-remove',
				text: i18n.remove,
				onclick: function () {
					if (window.confirm(i18n.confirmRemove)) {
						list.splice(index, 1);
						renderMappings();
						var add = document.getElementById(parent ? 'ppapi-m-' + parent.rowId + '-add-child' : 'ppapi-add-mapping');
						if (add) {
							add.focus();
						}
					}
				}
			})
		]));

		/* Nested mappings for groups and repeaters */
		if (isContainer) {
			var prefix = childPrefix(parentPrefix, row.source, descriptor.type === 'repeater');
			var childListId = 'ppapi-paths-' + row.id;
			var nested = el('div', { className: 'ppapi-mapping__children' }, [
				el('p', { className: 'ppapi-children-title', text: descriptor.type === 'repeater' ? i18n.subMappingsRepeater : i18n.subMappingsGroup })
			]);
			var datalist = el('datalist', { id: childListId });
			suggestionsFor(prefix).forEach(function (item) {
				datalist.appendChild(el('option', { value: item.path, label: item.type || '' }));
			});
			nested.appendChild(datalist);

			var childParent = {
				type: descriptor.type,
				children: descriptor.children || [],
				listId: childListId,
				rowId: row.id
			};
			row.children.forEach(function (child, childIndex) {
				nested.appendChild(renderRow(child, childIndex, row.children, childParent, depth + 1, prefix, insideRepeater));
			});

			var canAdd = depth + 1 < limits.maxDepth && row.children.length < limits.maxChildren;
			nested.appendChild(el('button', {
				type: 'button', className: 'button button-small', id: 'ppapi-m-' + row.id + '-add-child',
				disabled: !canAdd, text: i18n.addSubMapping,
				onclick: function () {
					var child = newRow(descriptor.type === 'repeater' ? 'repeater' : 'group');
					row.children.push(child);
					renderMappings();
					var focus = document.getElementById('ppapi-m-' + child.id + '-source');
					if (focus) {
						focus.focus();
					}
				}
			}));
			fieldset.appendChild(nested);
		}

		return fieldset;
	}

	/**
	 * Renders one opt-in rule on the mapping's untransformed source.
	 * Disabled rules retain their settings. Enabled actions follow destination
	 * clearing rules and never preserve a value inside a rebuilt repeater row.
	 */
	function renderCondition(row, base, context, clearable) {
		var condition = row.condition;
		var box = el('div', { className: 'ppapi-mapping__col' }, [el('h4', { text: i18n.condition })]);
		var toggle = el('input', { type: 'checkbox', id: base + '-condition-enabled', checked: condition.enabled });
		toggle.addEventListener('change', function () {
			condition.enabled = toggle.checked;
			renderMappings();
		});
		box.appendChild(el('label', { for: toggle.id }, [toggle, document.createTextNode(' ' + i18n.conditionEnable)]));
		if (!condition.enabled) {
			return box;
		}
		box.appendChild(el('p', { className: 'description', text: i18n.conditionHelp }));
		var operators = Object.keys(data.conditionOperators || {}).map(function (key) {
			return [key, data.conditionOperators[key]];
		});
		box.appendChild(select(base + '-condition-operator', i18n.conditionWhen, operators, condition.operator, function (value) {
			condition.operator = value;
			renderMappings();
		}));
		var isCount = condition.operator.indexOf('count_') === 0;
		box.appendChild(textInput(base + '-condition-value', isCount ? i18n.conditionCount : i18n.conditionText, condition.value, function (value) {
			condition.value = value;
			sync();
		}, isCount ? { inputmode: 'numeric', pattern: '[0-9]+', className: 'code' } : {}));
		if (!isCount) {
			var caseToggle = el('input', { type: 'checkbox', id: base + '-condition-case', checked: condition.case_sensitive });
			caseToggle.addEventListener('change', function () {
				condition.case_sensitive = caseToggle.checked;
				sync();
			});
			box.appendChild(el('label', { for: caseToggle.id }, [caseToggle, document.createTextNode(' ' + i18n.conditionCase)]));
		}
		var actions = context === 'repeater' ? [['clear', i18n.clearRow], ['skip_row', i18n.skipRow]] : [['preserve', i18n.preserve]];
		if (context !== 'repeater' && clearable) {
			actions.push(['clear', i18n.clear]);
		}
		condition.action = coerceBehavior(condition.action, actions);
		box.appendChild(select(base + '-condition-action', i18n.conditionAction, actions, condition.action, function (value) {
			condition.action = value;
			sync();
		}));
		box.appendChild(el('p', { className: 'description', text: context === 'repeater' ? i18n.conditionRepeater : i18n.conditionPreserve }));
		return box;
	}

	function renderTransformOptions(row, base) {
		var box = el('div', { className: 'ppapi-transform-options' });
		var options = row.options;

		function bind(key, label, extra) {
			box.appendChild(textInput(base + '-opt-' + key, label, options[key], function (value) {
				options[key] = value;
				sync();
			}, extra));
		}

		if (row.transform === 'join') {
			bind('separator', i18n.separator, { className: 'small-text code' });
			box.appendChild(el('label', { for: base + '-opt-join_datetime' }, [
				el('input', {
					type: 'checkbox', id: base + '-opt-join_datetime', checked: !!options.join_datetime,
					onchange: function (event) {
						options.join_datetime = event.target.checked;
						markDirty();
						renderMappings();
						document.getElementById(base + '-opt-join_datetime').focus();
					}
				}), ' ' + i18n.joinDateTime
			]));
			box.appendChild(el('p', { className: 'description', text: i18n.joinDateHelp }));
		} else if (row.transform === 'decimal') {
			bind('decimals', i18n.decimals, { className: 'small-text', inputmode: 'numeric' });
		}
		if (row.transform === 'datetime' || (row.transform === 'join' && options.join_datetime)) {
			bind('input_format', i18n.inputFormat, { className: 'code', placeholder: 'Y-m-d H:i' });
			bind('input_timezone', i18n.inputTimezone, { list: 'ppapi-timezones', placeholder: 'America/New_York' });
			bind('output_format', i18n.outputFormat, { className: 'code' });
			bind('output_timezone', i18n.outputTimezone, { list: 'ppapi-timezones' });
		} else if (row.transform === 'map') {
			var table = el('table', { className: 'ppapi-map-table' }, [
				el('thead', {}, [el('tr', {}, [
					el('th', { scope: 'col', text: i18n.mapFrom }),
					el('th', { scope: 'col', text: i18n.mapTo }),
					el('th', { scope: 'col' }, [el('span', { className: 'screen-reader-text', text: i18n.remove })])
				])])
			]);
			var body = el('tbody');
			options.map.forEach(function (pair, pairIndex) {
				var fromId = base + '-map-' + pairIndex + '-from';
				var toId = base + '-map-' + pairIndex + '-to';
				var from = el('input', { type: 'text', id: fromId, value: pair.from, className: 'code' });
				var to = el('input', { type: 'text', id: toId, value: pair.to, className: 'code' });
				from.addEventListener('input', function () {
					pair.from = from.value;
					sync();
				});
				to.addEventListener('input', function () {
					pair.to = to.value;
					sync();
				});
				body.appendChild(el('tr', {}, [
					el('td', {}, [el('label', { for: fromId, className: 'screen-reader-text', text: i18n.mapFrom }), from]),
					el('td', {}, [el('label', { for: toId, className: 'screen-reader-text', text: i18n.mapTo }), to]),
					el('td', {}, [el('button', {
						type: 'button', className: 'button-link ppapi-link-delete', text: i18n.remove,
						onclick: function () {
							options.map.splice(pairIndex, 1);
							renderMappings();
						}
					})])
				]));
			});
			table.appendChild(body);
			box.appendChild(table);
			box.appendChild(el('button', {
				type: 'button', className: 'button button-small', id: base + '-map-add', text: i18n.addMapEntry,
				onclick: function () {
					options.map.push({ from: '', to: '' });
					renderMappings();
					var focus = document.getElementById(base + '-map-' + (options.map.length - 1) + '-from');
					if (focus) {
						focus.focus();
					}
				}
			}));
			box.appendChild(select(base + '-unmatched', i18n.unmatched, [
				['keep', i18n.unmatchedKeep],
				['fallback', i18n.unmatchedFallback],
				['error', i18n.unmatchedError]
			], options.unmatched, function (value) {
				options.unmatched = value;
				renderMappings();
			}));
		}

		return box;
	}

	document.getElementById('ppapi-add-mapping').addEventListener('click', function () {
		if (rows.length >= limits.maxRows) {
			window.alert(i18n.limitReached);
			return;
		}
		var row = newRow('top');
		rows.push(row);
		renderMappings();
		markDirty();
		var focus = document.getElementById('ppapi-m-' + row.id + '-source');
		if (focus) {
			focus.focus();
		}
	});

	mappingContainer.addEventListener('change', markDirty);

	if (postTypeSelect) {
		postTypeSelect.addEventListener('change', function () {
			var type = postTypeSelect.value;
			if (!type) {
				catalog = null;
				renderMappings();
				refreshStaticDatalists();
				return;
			}
			clear(mappingContainer);
			mappingContainer.appendChild(el('p', { text: i18n.loadingFields }));
			apiFetch({ path: '/' + data.restNamespace + '/destinations?post_type=' + encodeURIComponent(type) }).then(function (result) {
				catalog = result;
				renderMappings();
				refreshStaticDatalists();
			}).catch(function (error) {
				catalog = null;
				renderMappings();
				renderError(mappingContainer.insertBefore(el('div'), mappingContainer.firstChild), error || { message: i18n.fieldsError });
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Test Request and Dry Run                                            */
	/* ------------------------------------------------------------------ */

	function formBody() {
		sync();
		var params = new URLSearchParams();
		new FormData(form).forEach(function (value, key) {
			params.append(key, value);
		});
		return params.toString();
	}

	function postForm(route) {
		return apiFetch({
			path: '/' + data.restNamespace + route,
			method: 'POST',
			body: formBody(),
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }
		});
	}

	function httpLine(result) {
		if (result.source === 'sample') {
			return i18n.sampleSource;
		}
		if (!result.http) {
			return '';
		}
		var size = result.http.bytes >= 1024 ? (result.http.bytes / 1024).toFixed(1) + ' KB' : result.http.bytes + ' B';
		return sprintf(i18n.httpSummary, result.http.status, size, result.http.duration_ms) + (result.http.url ? ' · ' + result.http.url : '');
	}

	function pathsTable(caption, items) {
		var table = el('table', { className: 'widefat striped ppapi-paths-table' }, [
			el('caption', { text: caption }),
			el('thead', {}, [el('tr', {}, [
				el('th', { scope: 'col', text: i18n.path }),
				el('th', { scope: 'col', text: i18n.type }),
				el('th', { scope: 'col', text: i18n.example })
			])])
		]);
		var body = el('tbody');
		items.forEach(function (item) {
			body.appendChild(el('tr', {}, [
				el('td', {}, [el('code', { text: item.path })]),
				el('td', { text: item.type }),
				el('td', { className: 'ppapi-sample-cell', text: item.sample })
			]));
		});
		table.appendChild(body);
		return table;
	}

	function renderTestResult(container, result) {
		clear(container);
		var line = httpLine(result);
		if (line) {
			container.appendChild(el('p', { className: 'ppapi-result__meta', text: line }));
		}

		if (!result.ok) {
			renderError(container.appendChild(el('div')), { message: result.error });
			if (result.snippet) {
				container.appendChild(el('details', {}, [
					el('summary', { text: i18n.responseBody }),
					el('pre', { className: 'ppapi-pre', text: result.snippet })
				]));
			}
			return;
		}

		if (result.records_ok) {
			container.appendChild(el('div', { className: 'notice notice-success inline' }, [
				el('p', { text: sprintf(i18n.recordsFound, result.record_count, document.getElementById('ppapi-records-path').value) })
			]));
			if (result.record_paths && result.record_paths.length) {
				container.appendChild(pathsTable(i18n.recordPaths, result.record_paths));
			}
			if (result.samples && result.samples.length) {
				container.appendChild(el('details', {}, [
					el('summary', { text: i18n.sampleRecords }),
					el('pre', { className: 'ppapi-pre', text: result.samples.join('\n\n') })
				]));
			}
		} else if (result.records_error) {
			container.appendChild(el('div', { className: 'notice notice-warning inline' }, [el('p', { text: result.records_error })]));
		}

		if (result.response_paths && result.response_paths.length) {
			container.appendChild(el('details', { open: !result.records_ok }, [
				el('summary', { text: i18n.responsePaths }),
				pathsTable(i18n.responsePaths, result.response_paths)
			]));
		}
		if (result.preview) {
			container.appendChild(el('details', {}, [
				el('summary', { text: i18n.rawPreview }),
				el('pre', { className: 'ppapi-pre', text: result.preview })
			]));
		}
	}

	var testButton = document.getElementById('ppapi-test-request');
	testButton.addEventListener('click', function () {
		var container = document.getElementById('ppapi-test-result');
		setBusy(testButton, true);
		clear(container);
		container.appendChild(el('p', { text: i18n.working }));
		postForm('/test-request').then(function (result) {
			if (result.ok) {
				if (result.record_paths) {
					recordPaths = result.record_paths;
				}
				if (result.response_paths) {
					responsePaths = result.response_paths;
				}
				refreshStaticDatalists();
				renderMappings();
			}
			renderTestResult(container, result);
		}).catch(function (error) {
			renderError(container, error);
		}).then(function () {
			setBusy(testButton, false);
		});
	});

	var actionLabels = {
		created: i18n.actionCreated,
		updated: i18n.actionUpdated,
		unchanged: i18n.actionUnchanged,
		skipped: i18n.actionSkipped,
		failed: i18n.actionFailed
	};
	var resultLabels = {
		set: i18n.resultSet,
		clear: i18n.resultClear,
		preserve: i18n.resultPreserve,
		skip_row: i18n.resultSkipRow,
		error: i18n.resultError
	};

	function detailRows(details, body, depth) {
		(details || []).forEach(function (detail) {
			var state = detail.state ? detail.state + (detail.raw_type ? ' · ' + detail.raw_type : '') : '';
			body.appendChild(el('tr', { className: depth ? 'ppapi-detail-child' : '' }, [
				el('td', { text: (depth ? '↳ ' : '') + detail.row }),
				el('td', { text: detail.target }),
				el('td', {}, [el('code', { text: detail.source })]),
				el('td', { text: state }),
				el('td', { className: 'ppapi-sample-cell', text: detail.raw }),
				el('td', { className: 'ppapi-detail-result ppapi-detail-result--' + (detail.result || ''), text: (resultLabels[detail.result] || detail.result) + (detail.via === 'fallback' ? ' ' + i18n.viaFallback : '') }),
				el('td', { className: 'ppapi-sample-cell', text: detail.value }),
				el('td', { text: detail.note })
			]));
			detailRows(detail.children, body, depth + 1);
		});
	}

	function renderDryRun(container, result) {
		clear(container);
		var line = httpLine(result);
		if (line) {
			container.appendChild(el('p', { className: 'ppapi-result__meta', text: line }));
		}
		if (!result.ok) {
			renderError(container.appendChild(el('div')), { message: result.error });
			if (result.snippet) {
				container.appendChild(el('pre', { className: 'ppapi-pre', text: result.snippet }));
			}
			return;
		}

		var counts = result.counts || {};
		container.appendChild(el('div', { className: 'notice notice-info inline' }, [
			el('p', { text: sprintf(i18n.dryRunSummary, result.evaluated, result.record_count, counts.created, counts.updated, counts.unchanged, counts.skipped, counts.failed) }),
			el('p', { text: result.note })
		]));

		(result.records || []).forEach(function (record, index) {
			var heading = sprintf(i18n.record, record.position);
			if (record.external_id) {
				heading += ' · ' + sprintf(i18n.externalId, record.external_id);
			}
			heading += ' → ' + (actionLabels[record.action] || record.action);
			if (record.post_id) {
				heading += ' (' + sprintf(i18n.post, record.post_id) + ')';
			}
			var details = el('details', { className: 'ppapi-dry-record ppapi-dry-record--' + record.action, open: index === 0 || record.action === 'failed' }, [
				el('summary', { text: heading })
			]);
			if (record.message) {
				details.appendChild(el('p', { className: record.action === 'failed' ? 'ppapi-text-error' : 'description', text: record.message }));
			}
			if (record.details && record.details.length) {
				var table = el('table', { className: 'widefat striped ppapi-detail-table' }, [
					el('thead', {}, [el('tr', {}, [
						el('th', { scope: 'col', text: '#' }),
						el('th', { scope: 'col', text: i18n.destination }),
						el('th', { scope: 'col', text: i18n.source }),
						el('th', { scope: 'col', text: i18n.state }),
						el('th', { scope: 'col', text: i18n.raw }),
						el('th', { scope: 'col', text: i18n.result }),
						el('th', { scope: 'col', text: i18n.value }),
						el('th', { scope: 'col', text: i18n.note })
					])])
				]);
				var body = el('tbody');
				detailRows(record.details, body, 0);
				table.appendChild(body);
				details.appendChild(el('div', { className: 'ppapi-table-scroll' }, [table]));
			}
			container.appendChild(details);
		});
	}

	var dryButton = document.getElementById('ppapi-dry-run');
	dryButton.addEventListener('click', function () {
		var container = document.getElementById('ppapi-dry-run-result');
		setBusy(dryButton, true);
		clear(container);
		container.appendChild(el('p', { text: i18n.working }));
		postForm('/dry-run').then(function (result) {
			renderDryRun(container, result);
		}).catch(function (error) {
			renderError(container, error);
		}).then(function () {
			setBusy(dryButton, false);
		});
	});

	/* ------------------------------------------------------------------ */
	/* Run Now                                                             */
	/* ------------------------------------------------------------------ */

	var runButton = document.getElementById('ppapi-run-now');
	var cancelButton = document.getElementById('ppapi-run-cancel');
	var runStatus = document.getElementById('ppapi-run-status');
	var statusLabels = {
		running: i18n.statusRunning,
		waiting: i18n.statusWaiting,
		completed: i18n.statusCompleted,
		completed_with_errors: i18n.statusCompletedWithErrors,
		truncated: i18n.statusTruncated,
		failed: i18n.statusFailed,
		cancelled: i18n.statusCancelled
	};
	var stepFailures = 0;

	function runRoute(suffix) {
		return '/' + data.restNamespace + '/presets/' + data.presetId + '/run' + (suffix || '');
	}

	function renderRun(response) {
		if (!runStatus) {
			return;
		}
		clear(runStatus);
		var run = response && response.run;
		if (!run) {
			return;
		}
		var counts = run.counts || {};
		var label = statusLabels[run.status] || run.status;
		runStatus.appendChild(el('p', {}, [
			el('span', { className: 'ppapi-status ppapi-status--' + (response.active ? 'info' : (run.status === 'completed' ? 'success' : (run.status === 'failed' ? 'error' : 'warning'))), text: label }),
			' ',
			sprintf(i18n.runProgress, run.processed, run.pages)
		]));
		runStatus.appendChild(el('p', { className: 'ppapi-counts', text: sprintf(i18n.counts, counts.created, counts.updated, counts.unchanged, counts.skipped, counts.failed) }));
		if (response.active && response.resume_in > 0) {
			runStatus.appendChild(el('p', { text: sprintf(i18n.runWaiting, response.resume_in) }));
		}
		if (response.busy) {
			runStatus.appendChild(el('p', { text: i18n.runBusy }));
		}
		if (response.pending) {
			runStatus.appendChild(el('p', { text: i18n.runCancelRequested }));
		}
		if (run.message) {
			runStatus.appendChild(el('p', { className: run.status === 'failed' ? 'ppapi-text-error' : '', text: run.message }));
		}
		if (run.issues && run.issues.length) {
			runStatus.appendChild(el('details', {}, [
				el('summary', { text: i18n.failedRecords + ' (' + (run.issues.length + (run.issue_overflow || 0)) + ')' }),
				el('ul', {}, run.issues.map(function (issue) {
					return el('li', { text: sprintf(i18n.record, issue.position) + (issue.external_id ? ' · ' + sprintf(i18n.externalId, issue.external_id) : '') + ': ' + issue.message });
				}))
			]));
		}
		if (!response.active) {
			runStatus.appendChild(el('p', { className: 'description', text: i18n.reloadHistory }));
		}
	}

	function setRunning(active) {
		if (runButton) {
			runButton.disabled = !!active;
		}
		if (cancelButton) {
			cancelButton.hidden = !active;
		}
	}

	function loop(response) {
		renderRun(response);
		setRunning(response && response.active);
		if (!response || !response.active) {
			return;
		}
		var delay = response.busy ? 3000 : (response.resume_in > 0 ? response.resume_in * 1000 : 250);
		window.setTimeout(function () {
			apiFetch({ path: runRoute('/step'), method: 'POST' }).then(function (next) {
				stepFailures = 0;
				loop(next);
			}).catch(function (error) {
				stepFailures++;
				if (stepFailures < 4) {
					loop({ active: true, busy: true, run: response.run });
					return;
				}
				renderError(runStatus, error);
				setRunning(false);
			});
		}, delay);
	}

	if (runButton) {
		runButton.addEventListener('click', function () {
			if (dirty && !window.confirm(i18n.saveFirst)) {
				return;
			}
			setRunning(true);
			clear(runStatus);
			runStatus.appendChild(el('p', { text: i18n.runStarting }));
			var force = document.getElementById('ppapi-run-force');
			apiFetch({ path: runRoute(), method: 'POST', data: { force: !!(force && force.checked) } }).then(loop).catch(function (error) {
				renderError(runStatus, error);
				setRunning(false);
			});
		});
	}

	if (cancelButton) {
		cancelButton.addEventListener('click', function () {
			setBusy(cancelButton, true);
			apiFetch({ path: runRoute('/cancel'), method: 'POST' }).then(function (response) {
				renderRun(response);
				if (!response.active) {
					setRunning(false);
				}
			}).catch(function (error) {
				renderError(runStatus, error);
			}).then(function () {
				setBusy(cancelButton, false);
			});
		});
	}

	/* Initial render ---------------------------------------------------- */

	refreshStaticDatalists();
	renderMappings();
	dirty = false;

	if (data.activeRun && data.presetId) {
		loop({ active: true, busy: false, resume_in: 0, run: data.activeRun });
	}

	var summary = document.getElementById('ppapi-error-summary');
	if (summary) {
		summary.focus();
	}
})();
