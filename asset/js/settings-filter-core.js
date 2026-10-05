/**
 * Filters specific to the module, added to the settings filter of the core.
 *
 * Since Omeka 4.3, the settings pages have their own filter: a search on the
 * labels, the descriptions and the names of the settings, and chips to select
 * an element group. So only the features that the core does not provide are
 * added here: the highlight of the matches, a marker of the status of each
 * value (default, modified or unknown) with the filters for them, the filters
 * on the type of field (text or not), and the search inside the current
 * values.
 *
 * The fields hidden by these filters use a class of the module, so the
 * filtering of the core and the filtering of the module are independent: each
 * one hides the fields it excludes and never shows a field the other one
 * hides.
 */

(function () {
    'use strict';

    var bar = document.querySelector('.settings-filter');
    if (!bar) {
        return;
    }

    // The scope of the filter of the core is its parent element.
    var scope = bar.parentElement;
    var input = bar.querySelector('.o-filter-input');
    var fields = Array.prototype.slice.call(scope.querySelectorAll('.field'));
    if (!input || !fields.length) {
        return;
    }

    var groups = Array.prototype.slice.call(scope.querySelectorAll('.settings-group'));

    var lib = (window.EasyAdmin && window.EasyAdmin.settingsFields) || {};
    var i18n = (window.EasyAdmin && window.EasyAdmin.settingsFilter) || {};

    var valuesCtl = lib.makeToggle(
        'setting-filter-values',
        i18n.includeValues || 'Include values'
    );
    var defaultCtl = lib.makeToggle('setting-filter-default', i18n['default'] || 'Default');
    var modifiedCtl = lib.makeToggle('setting-filter-modified', i18n.modified || 'Modified');
    var unknownCtl = lib.makeToggle('setting-filter-unknown', i18n.unknown || 'Unknown');
    var textCtl = lib.makeToggle('setting-filter-text', i18n.textFields || 'Text fields');
    var nonTextCtl = lib.makeToggle('setting-filter-nontext', i18n.nonTextFields || 'Non-text fields');
    defaultCtl.text.classList.add('setting-status', 'setting-status-default');
    modifiedCtl.text.classList.add('setting-status', 'setting-status-modified');
    unknownCtl.text.classList.add('setting-status', 'setting-status-unknown');

    var separator = document.createElement('span');
    separator.className = 'setting-filter-sep';
    separator.setAttribute('aria-hidden', 'true');

    var toggles = document.createElement('div');
    toggles.className = 'setting-filter-toggles';
    toggles.appendChild(lib.makeGroup(
        i18n.groupValue || 'Value',
        [defaultCtl, modifiedCtl, unknownCtl]
    ));
    toggles.appendChild(separator);
    toggles.appendChild(lib.makeGroup(
        i18n.groupType || 'Type',
        [textCtl, nonTextCtl]
    ));
    toggles.appendChild(valuesCtl.label);

    var controls = document.createElement('div');
    controls.className = 'setting-filter-controls';
    controls.appendChild(toggles);
    bar.appendChild(controls);

    lib.appendStatusDots(fields, i18n);

    // Words of the query of the core: a field matches when it has all of them.
    var queryWords = function () {
        return input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    };

    var apply = function () {
        var words = queryWords();
        var wantStatus = {
            modified: modifiedCtl.box.checked,
            'default': defaultCtl.box.checked,
            unknown: unknownCtl.box.checked,
        };
        var wantText = textCtl.box.checked;
        var wantNonText = nonTextCtl.box.checked;
        var includeValues = valuesCtl.box.checked;
        var valueChecked = (wantStatus.modified ? 1 : 0)
            + (wantStatus['default'] ? 1 : 0)
            + (wantStatus.unknown ? 1 : 0);
        var typeChecked = (wantText ? 1 : 0) + (wantNonText ? 1 : 0);
        var restricted = valueChecked < 3 || typeChecked < 2;
        fields.forEach(function (field) {
            var meta = field.querySelector('.field-meta');
            if (!meta) {
                return;
            }
            var typePass = lib.isTextField(field) ? wantText : wantNonText;
            var valuePass = !!wantStatus[lib.fieldStatus(field)];
            // The search of the core does not look inside the current values,
            // so the fields excluded by it are filtered again here when the
            // option is set.
            var valueMatch = !words.length || !includeValues
                ? false
                : words.every(function (word) {
                    return lib.fieldValueText(field).indexOf(word) !== -1;
                });
            var hidden = !typePass || !valuePass;
            field.classList.toggle('setting-hidden', hidden);
            if (valueMatch && !hidden) {
                field.classList.remove('o-filter-hidden');
            }
            var shown = !hidden && !field.classList.contains('o-filter-hidden');
            lib.highlight(meta, shown ? words : []);
        });
        // An element group without any visible field is hidden, as the core
        // does for its own filter.
        groups.forEach(function (group) {
            var visible = Array.prototype.slice
                .call(group.querySelectorAll('.field'))
                .some(function (field) {
                    return field.querySelector('.field-meta')
                        && !field.classList.contains('setting-hidden')
                        && !field.classList.contains('o-filter-hidden');
                });
            group.classList.toggle('setting-hidden', restricted && !visible);
        });
    };

    // The listeners of the core are set first, so these ones are called after
    // them, with the fields it hides already up to date.
    input.addEventListener('input', apply);
    bar.addEventListener('click', function (event) {
        if (event.target.closest('.o-filter-chip')) {
            apply();
        }
    });
    [
        valuesCtl.box, defaultCtl.box, modifiedCtl.box, unknownCtl.box,
        textCtl.box, nonTextCtl.box,
    ].forEach(function (box) {
        box.addEventListener('change', apply);
    });
    // The core opens the group of the url after every other script, so the
    // first filtering is done after it too.
    window.addEventListener('hashchange', function () {
        window.setTimeout(apply, 0);
    });
    window.setTimeout(apply, 0);

    // Global shortcut "/" to focus the filter, unless already typing.
    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        var active = document.activeElement;
        if (active && active.matches('input, textarea, select, [contenteditable]')) {
            return;
        }
        event.preventDefault();
        input.focus();
    });
})();
