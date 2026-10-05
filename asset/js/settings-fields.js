/**
 * Shared helpers to filter the fields of the settings pages.
 *
 * The classification of the fields (kind and status) is computed server-side
 * and injected in window.EasyAdmin.settingsFilter (see the method
 * appendSettingsFilterAssets of the module). These helpers are used by the
 * full filter, for the versions of Omeka without it, and by the filters added
 * to the filter of the core (Omeka 4.3 and above).
 */

(function () {
    'use strict';

    var data = (window.EasyAdmin && window.EasyAdmin.settingsFilter) || {};
    var kinds = data.kinds || {};
    var statuses = data.status || {};

    // Setting name of a field, from the name of its control.
    var baseKey = function (field) {
        var el = field.querySelector('input[name], select[name], textarea[name]');
        var base = el && el.name ? el.name.replace(/\[.*$/, '') : '';
        return base && base.charAt(0) !== '_' ? base : '';
    };

    // A note is a static text (no named control), rendered as ".note".
    var isNote = function (field) {
        return !!field.querySelector('.note');
    };

    // The kind of a field (structural | literal | manual) comes from the type
    // of its form element, classified server-side like the SiteHub module. A
    // field is a text field when its kind is "literal".
    var kindCache = new WeakMap();
    var isTextField = function (field) {
        if (kindCache.has(field)) {
            return kindCache.get(field);
        }
        var base = baseKey(field);
        // A note is not a text setting; ambiguous fields default to text, as in
        // the classifier.
        var kind = isNote(field)
            ? 'structural'
            : (base && kinds[base] ? kinds[base] : 'literal');
        var text = kind === 'literal';
        kindCache.set(field, text);
        return text;
    };

    // Status of a field: default, modified or unknown, computed server-side
    // from the module defaults declared in module.config.php. A note has no
    // value, so it is always at its default.
    var fieldStatus = function (field) {
        if (isNote(field)) {
            return 'default';
        }
        var base = baseKey(field);
        return base && statuses[base] ? statuses[base] : 'unknown';
    };

    // Current textual value of a field's controls, to search within the values.
    var fieldValueText = function (field) {
        var parts = [];
        field.querySelectorAll('input, select, textarea').forEach(function (el) {
            var type = (el.type || '').toLowerCase();
            if (type === 'hidden' || type === 'submit' || type === 'button'
                || type === 'password'
                || el.classList.contains('setting-filter-input')
                || el.classList.contains('o-filter-input')
            ) {
                return;
            }
            if (type === 'checkbox' || type === 'radio') {
                if (el.checked) {
                    parts.push(el.value || '');
                }
            } else if (el.tagName === 'SELECT') {
                Array.prototype.forEach.call(el.selectedOptions || [], function (o) {
                    parts.push(o.textContent);
                });
            } else if (el.value) {
                parts.push(el.value);
            }
        });
        return parts.join(' ').toLowerCase();
    };

    // Highlight one or multiple queries in a container, resetting any previous
    // highlight.
    var highlight = function (container, queries) {
        if (!container) {
            return;
        }
        // Remove previous highlights by unwrapping the <mark> nodes. This
        // preserves sibling nodes and their event listeners (e.g. the per-field
        // buttons injected by other modules such as SiteHub), unlike a
        // container.innerHTML rewrite, which destroys them.
        container.querySelectorAll('mark.setting-filter-hit').forEach(function (mark) {
            mark.replaceWith(document.createTextNode(mark.textContent));
        });
        container.normalize();
        var words = (typeof queries === 'string' ? [queries] : (queries || []))
            .filter(function (word) {
                return !!word;
            });
        words.forEach(function (query) {
            var walker = document.createTreeWalker(
                container,
                NodeFilter.SHOW_TEXT,
                null
            );
            var nodes = [];
            while (walker.nextNode()) {
                // Skip the text already highlighted for a previous word, so the
                // marks are never nested.
                var parent = walker.currentNode.parentNode;
                if (!parent || !parent.classList
                    || !parent.classList.contains('setting-filter-hit')
                ) {
                    nodes.push(walker.currentNode);
                }
            }
            nodes.forEach(function (node) {
                var text = node.nodeValue;
                var lower = text.toLowerCase();
                var index = lower.indexOf(query);
                if (index === -1) {
                    return;
                }
                var fragment = document.createDocumentFragment();
                var last = 0;
                while (index !== -1) {
                    fragment.appendChild(
                        document.createTextNode(text.slice(last, index))
                    );
                    var mark = document.createElement('mark');
                    mark.className = 'setting-filter-hit';
                    mark.textContent = text.slice(index, index + query.length);
                    fragment.appendChild(mark);
                    last = index + query.length;
                    index = lower.indexOf(query, last);
                }
                fragment.appendChild(document.createTextNode(text.slice(last)));
                node.parentNode.replaceChild(fragment, node);
            });
        });
    };

    // A checkbox with a label, used for the filters of the module.
    var makeToggle = function (className, label) {
        var wrap = document.createElement('label');
        wrap.className = 'setting-filter-toggle';
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.className = className;
        box.checked = true;
        var text = document.createElement('span');
        text.className = 'setting-filter-label';
        text.textContent = label;
        wrap.appendChild(box);
        wrap.appendChild(text);
        return { label: wrap, box: box, text: text };
    };

    // A labelled group of checkboxes.
    var makeGroup = function (label, ctls) {
        var group = document.createElement('div');
        group.className = 'setting-filter-group';
        var title = document.createElement('span');
        title.className = 'setting-filter-group-label';
        title.textContent = label;
        group.appendChild(title);
        ctls.forEach(function (ctl) {
            group.appendChild(ctl.label);
        });
        return group;
    };

    // A coloured status marker at the start of the label of each field.
    var appendStatusDots = function (fields, i18n) {
        fields.forEach(function (field) {
            var meta = field.querySelector('.field-meta');
            if (!meta || meta.querySelector('.setting-status-dot')) {
                return;
            }
            var st = fieldStatus(field);
            var label = i18n[st] || st;
            var dot = document.createElement('span');
            dot.className = 'setting-status-dot setting-status-' + st;
            // Accessible: the colour also has a name, so it is not conveyed by
            // colour alone (a distinct shape is added in CSS for colour
            // blindness).
            dot.title = label;
            dot.setAttribute('role', 'img');
            dot.setAttribute('aria-label', label);
            meta.insertBefore(dot, meta.firstChild);
        });
    };

    window.EasyAdmin = window.EasyAdmin || {};
    window.EasyAdmin.settingsFields = {
        baseKey: baseKey,
        isNote: isNote,
        isTextField: isTextField,
        fieldStatus: fieldStatus,
        fieldValueText: fieldValueText,
        highlight: highlight,
        makeToggle: makeToggle,
        makeGroup: makeGroup,
        appendStatusDots: appendStatusDots,
    };
})();
