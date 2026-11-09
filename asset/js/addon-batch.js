/**
 * Enable the batch actions of the modules and themes tables.
 *
 * The core function Omeka.manageSelectedActions() only enables the options
 * "update-selected" and "delete-selected", that are the ones of the resource
 * browse pages, so the options of the addons are managed here.
 *
 * All handlers are delegated on the document, because the catalogue is loaded
 * asynchronously and swaps the content of the page (see addon-catalogue.js), so
 * handlers bound directly on the checkboxes would be lost after the swap.
 */

(function () {
    'use strict';

    var updateBatchActions = function () {
        var select = document.querySelector('.batch-actions-select');
        if (!select) {
            return;
        }
        var checked = document.querySelectorAll(
            '.batch-edit tbody input[type="checkbox"]:checked'
        ).length;
        Array.prototype.forEach.call(select.options, function (option) {
            if (option.value !== 'default' && !option.dataset.always) {
                option.disabled = !checked;
            }
        });
    };

    var copyText = function (text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        // The clipboard api is available only on https.
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
        document.body.appendChild(textarea);
        textarea.select();
        var done = document.execCommand('copy');
        textarea.remove();
        return done ? Promise.resolve() : Promise.reject(new Error('copy'));
    };

    // Copy the list of the visible addons, all or selected, one per line.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.batch-actions [data-copy]');
        if (!button) {
            return;
        }
        var rows = document.querySelectorAll('.batch-edit tbody tr[data-copy]:not(.addon-hidden-filter)');
        var lines = Array.prototype.filter.call(rows, function (row) {
            if (button.dataset.copy === 'all') {
                return true;
            }
            // Tablesaw copies the checkbox "select all" in the cell labels.
            var checkbox = row.querySelector('input[type="checkbox"][name$="[]"]');
            return checkbox && checkbox.checked;
        }).map(function (row) {
            return row.dataset.copy;
        });
        if (!lines.length) {
            return;
        }
        copyText(lines.join('\n') + '\n').then(function () {
            var label = button.dataset.label || button.textContent;
            button.dataset.label = label;
            button.textContent = (button.dataset.copied || '%d').replace('%d', lines.length);
            setTimeout(function () {
                button.textContent = label;
            }, 2000);
        });
    });

    document.addEventListener('change', function (event) {
        var target = event.target;
        // The core handlers, bound directly on the elements at load, are lost
        // when the catalogue swaps the page content, so they are re-applied
        // here through delegation. They are idempotent with the core ones on
        // the initial (non-swapped) load.
        if (target.matches('.select-all')) {
            var rows = document.querySelectorAll(
                '.batch-edit td input[type="checkbox"]:not(:disabled)'
            );
            Array.prototype.forEach.call(rows, function (checkbox) {
                checkbox.checked = target.checked;
            });
            updateBatchActions();
            return;
        }
        if (target.matches('.batch-edit td input[type="checkbox"]')) {
            var selectAll = document.querySelector('.select-all:checked');
            if (selectAll) {
                selectAll.checked = false;
            }
            updateBatchActions();
            return;
        }
        if (target.matches('.batch-actions-select')) {
            var actions = document.querySelectorAll('.batch-actions .active');
            Array.prototype.forEach.call(actions, function (node) {
                node.classList.remove('active');
            });
            var selected = document.querySelectorAll('.' + target.value);
            Array.prototype.forEach.call(selected, function (node) {
                node.classList.add('active');
            });
        }
    });
})();
