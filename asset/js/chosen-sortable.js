/**
 * Make Chosen multiselect dropdowns sortable via drag-and-drop.
 *
 * Requires SortableJS (already available in Omeka S).
 *
 * All multiselect chosen-selects become sortable.
 * Optionally, add attributes on the <select> element:
 *   - data-values-order='["val1","val2"]' (json) to restore a specific order on load
 *
 * On form submit, <option> elements are reordered to match the visual order,
 * so the server receives values in the displayed order.
 */
(function($) {
    'use strict';

    $(function() {
        if (typeof Sortable === 'undefined') {
            return;
        }

        $('select.chosen-select[multiple]').each(function() {
            initSortableChosen($(this));
        });

        // Before form submit, sync the <option> order with visual order.
        $(document).on('submit', 'form', function() {
            $(this).find('select.chosen-select[multiple]').each(function() {
                syncSelectOrder($(this));
            });
        });
    });

    function initSortableChosen($select) {
        var chosen = $select.data('chosen');
        if (!chosen || !chosen.is_multiple) {
            return;
        }

        var $choicesList = chosen.search_choices;
        if (!$choicesList || !$choicesList.length) {
            return;
        }

        // Restore saved order on load (for elements with data-values-order).
        var savedOrder = $select.data('values-order');
        if (savedOrder && Array.isArray(savedOrder)) {
            restoreOrder(chosen, $choicesList, savedOrder);
        }

        // Stop mousedown on search-choice from reaching Chosen's container
        // handler, which calls evt.preventDefault() and search_field.focus().
        // Without this, Chosen intercepts the click (opens dropdown, steals
        // focus) and SortableJS cannot start the drag.
        // The close button is excluded so it still works normally.
        $choicesList[0].addEventListener('mousedown', function(e) {
            var target = e.target;
            while (target && target !== $choicesList[0]) {
                if (target.classList.contains('search-choice-close')) {
                    return;
                }
                if (target.classList.contains('search-choice')) {
                    e.stopPropagation();
                    return;
                }
                target = target.parentNode;
            }
        }, false);

        // Apply SortableJS to the chosen choices list.
        new Sortable($choicesList[0], {
            draggable: '.search-choice',
            filter: '.search-field',
            animation: 150,
            ghostClass: 'chosen-sortable-ghost',
            scroll: false,
        });
    }

    /**
     * Reorder .search-choice elements to match the saved value order.
     */
    function restoreOrder(chosen, $choicesList, order) {
        var $searchField = $choicesList.find('.search-field');
        var $choices = $choicesList.find('.search-choice');

        // Build map: value → jQuery element.
        var choicesByValue = {};
        $choices.each(function() {
            var value = getChoiceValue($(this), chosen);
            if (value !== null) {
                choicesByValue[String(value)] = $(this);
            }
        });

        // Move each choice before the search field, in saved order.
        for (var i = 0; i < order.length; i++) {
            var $choice = choicesByValue[String(order[i])];
            if ($choice) {
                $choice.insertBefore($searchField);
            }
        }
    }

    /**
     * Get the value of a .search-choice element via chosen's results_data.
     */
    function getChoiceValue($choice, chosen) {
        var $close = $choice.find('.search-choice-close');
        if ($close.length) {
            var idx = parseInt($close.attr('data-option-array-index'), 10);
            if (chosen.results_data && chosen.results_data[idx]) {
                return chosen.results_data[idx].value;
            }
        }
        return null;
    }

    /**
     * Reorder <option> elements in the <select> to match the visual order.
     * Browsers send multiselect values in DOM order, so this ensures
     * the server receives values in the order displayed to the user.
     */
    function syncSelectOrder($select) {
        var chosen = $select.data('chosen');
        if (!chosen || !chosen.is_multiple) {
            return;
        }

        var $choicesList = chosen.search_choices;
        if (!$choicesList || !$choicesList.length) {
            return;
        }

        // Read visual order from chosen UI.
        var orderedValues = [];
        $choicesList.find('.search-choice').each(function() {
            var value = getChoiceValue($(this), chosen);
            if (value !== null) {
                orderedValues.push(String(value));
            }
        });

        // Reorder selected <option> elements: move them to the top in order.
        // Iterate in reverse and prepend, so the first value ends up first.
        for (var i = orderedValues.length - 1; i >= 0; i--) {
            var val = orderedValues[i];
            var $opt = $select.find('option').filter(function() {
                return this.value === val;
            });
            if ($opt.length) {
                $select.prepend($opt);
            }
        }
    }

})(jQuery);
