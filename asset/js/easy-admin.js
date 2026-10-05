'use strict';

$(document).ready(function () {

    if (!$('body').hasClass('check-and-fix')) {
        return;
    }

    var $form = $('#check-and-fix-form');
    var subjects = $form.data('subjects') || {};
    var entityTypesTasks = ($form.data('entity-types-tasks') || '').split(',');
    // Tasks running in the web process, with an immediate result.
    var quickProcesses = ($form.data('quick-processes') || '').split(',');
    var quickLabel = $form.data('quick-label') || '';

    // value -> {subject, verb} index from the subjects metadata (core tasks).
    var valueIndex = {};
    Object.keys(subjects).forEach(function (key) {
        var actions = subjects[key].actions || {};
        Object.keys(actions).forEach(function (value) {
            valueIndex[value] = {subject: key, verb: actions[value]};
        });
    });

    // Derive a subject from a process value for tasks not in the metadata
    // (added by other modules through the "form.add_elements" event).
    var subjectOf = function (value) {
        return (value || '').replace(
            /_(check_full|check|fix_all|fix_db|fix|clean|move|recreate|index|save)$/, ''
        );
    };

    // Replace the visible text of a radio label while keeping its input.
    var relabel = function ($label, text) {
        $label.contents().filter(function () {
            return this.nodeType === 3;
        }).remove();
        $label.append(document.createTextNode(' ' + text));
    };

    // Option panels carry their process value as a css class, wherever they
    // are.
    var optionFieldsets = function () {
        return $('.check-and-fix fieldset.field-container').find('fieldset');
    };

    // The row of the field, found from the element itself: the core adds the
    // id "<element id>_field" on the row since Omeka S 4.2 only, so the field
    // would stay in the page with the previous versions.
    var $entityField = $('#files_checkfix-entity_types').closest('.field');

    // A single checkbox is rendered with its label in ".field-meta" and a bare
    // box in ".inputs", which reads as unlabeled once stacked in the sidebar.
    // Move the label next to the box (with the description) when the box has no
    // inline label of its own. Multi-checkboxes already label each option.
    var labelBareCheckboxes = function () {
        // Match any field holding a single bare checkbox (the ".checkbox" class
        // on the row is not always present), not multi-checkboxes.
        optionFieldsets().find('.field').each(function () {
            var $field = $(this);
            var $inputs = $field.find('> .inputs');
            var $box = $inputs.children('input[type="checkbox"]');
            if ($box.length !== 1 || $inputs.find('label').length) {
                return;
            }
            var $meta = $field.find('> .field-meta');
            var label = $meta.find('> label').text().trim();
            if (!label) {
                return;
            }
            // Wrap the box in a label with the text after it, mirroring how
            // multi-checkboxes render inline (a bare sibling label would
            // stack).
            $box.wrap('<label></label>').after(document.createTextNode(' ' + label));
            $meta.find('> label').remove();
            $meta.children().appendTo($inputs);
            $meta.remove();
            // Mark it so the moved info arrow can sit inline at the right.
            $field.addClass('cf-bare-checkbox');
        });
    };

    /**
     * Build subject blocks (known tasks) and legacy groups (others), per
     * section, so tasks added by modules still appear.
     */
    // Split a "Module: action" label into {title, action}; null when no colon.
    var splitLabel = function (labelEl) {
        var text = $(labelEl).text().trim();
        var idx = text.indexOf(':');
        if (idx === -1) {
            return null;
        }
        return {title: text.slice(0, idx).trim(), action: text.slice(idx + 1).trim()};
    };

    var buildSubjects = function () {
        $('.check-and-fix fieldset.field-container').each(function () {
            var entries = $(this).find('input.fieldset-process').map(function () {
                return {value: $(this).val(), label: $(this).closest('label')[0]};
            }).get();

            var i = 0;
            while (i < entries.length) {
                var meta = valueIndex[entries[i].value];
                if (meta) {
                    // Core/module task with metadata.
                    var key = meta.subject;
                    var members = [];
                    while (i < entries.length
                        && valueIndex[entries[i].value]
                        && valueIndex[entries[i].value].subject === key
                    ) {
                        members.push(entries[i]);
                        i++;
                    }
                    buildSubjectBlock(key, members, subjects[key]);
                } else if (splitLabel(entries[i].label)) {
                    // Module task not updated yet: "Module: action" → group
                    // consecutive same-title labels as a subject block.
                    var title = splitLabel(entries[i].label).title;
                    var members2 = [];
                    var actions = {};
                    while (i < entries.length && !valueIndex[entries[i].value]) {
                        var s = splitLabel(entries[i].label);
                        if (!s || s.title !== title) {
                            break;
                        }
                        actions[entries[i].value] = s.action;
                        members2.push(entries[i]);
                        i++;
                    }
                    buildSubjectBlock('mod_' + title.replace(/\W+/g, '_'), members2,
                        {name: title, description: '', actions: actions});
                } else {
                    // Plain fallback group (no metadata, no colon).
                    var subj = subjectOf(entries[i].value);
                    var group = [];
                    while (i < entries.length
                        && !valueIndex[entries[i].value]
                        && !splitLabel(entries[i].label)
                        && subjectOf(entries[i].value) === subj
                    ) {
                        group.push(entries[i].label);
                        i++;
                    }
                    $(group).wrapAll('<div class="task-group"></div>');
                }
            }
        });
    };

    var buildSubjectBlock = function (key, members, meta) {
        var $block = $('<div class="task-subject" data-subject="' + key + '">'
            + '<button type="button" class="task-subject-head" aria-pressed="false">'
            + '<span class="task-subject-name"></span> '
            + '<span class="task-subject-desc"></span>'
            + '</button><div class="task-actions" data-subject="' + key + '"></div></div>');
        $block.find('.task-subject-name').text(meta.name);
        $block.find('.task-subject-desc').text(meta.description || '');
        var $actions = $block.find('.task-actions');
        $block.insertBefore(members[0].label);
        members.forEach(function (e) {
            var $label = $(e.label);
            relabel($label, (meta.actions && meta.actions[e.value]) || e.value);
            if (quickProcesses.indexOf(e.value) !== -1) {
                $label.addClass('task-action-quick');
                if (quickLabel) {
                    $label.append(
                        $('<span class="task-action-quick-tag"></span>').text(quickLabel)
                    );
                }
            }
            $actions.append($label);
        });
    };

    var dangerousTasks = ($form.data('tasks-warning') || '').split(',').filter(Boolean);
    var isDangerous = function (value) {
        return dangerousTasks.indexOf(value) !== -1;
    };

    // Hide a section that has no visible subject/group.
    var updateEmptySections = function () {
        $('.check-and-fix fieldset.field-container').each(function () {
            var hasVisible = $(this).find('.task-subject, .task-group').toArray().some(function (el) {
                return !el.classList.contains('task-hidden-filter') && el.style.display !== 'none';
            });
            $(this).toggle(hasVisible);
        });
    };

    // Exclusive switch: checked lists only dangerous tasks, unchecked lists
    // only normal ones.
    var hideTasksWarning = function () {
        var onlyDangerous = $('#toggle_tasks_with_warning').prop('checked');
        // Show and enable only the actions of the selected category.
        $('.check-and-fix input.fieldset-process').each(function () {
            var match = isDangerous(this.value) === onlyDangerous;
            $(this).prop('disabled', !match).closest('label').toggle(match);
        });
        // Show a subject (or legacy group) only if it has a matching action.
        $('.check-and-fix .task-subject, .check-and-fix .task-group').each(function () {
            var key = $(this).attr('data-subject');
            var radios = key
                ? $('.task-actions[data-subject="' + key + '"] input.fieldset-process').toArray()
                : $(this).find('input.fieldset-process').toArray();
            var hasMatch = radios.some(function (r) {
                return isDangerous(r.value) === onlyDangerous;
            });
            $(this).toggle(hasMatch);
        });
        updateEmptySections();
    };

    var $sidebar = $('#cf-sidebar');
    var $help = $sidebar.find('.task-help');
    var $recap = $sidebar.find('.task-recap');
    var $recapActions = $sidebar.find('.task-recap-actions');
    var $recapOptions = $sidebar.find('.task-recap-options');
    // Hidden home for the action groups and option panels when not displayed in
    // the recap, so they remain findable globally (by value/subject) and the
    // recap can be rebuilt at each selection without losing nodes.
    var $stash = $('<div id="cf-stash" hidden></div>').appendTo($form);

    // Empty the recap: stash its nodes, clear the selection and hide panels.
    var resetRecap = function () {
        $stash.append($recapActions.children()).append($recapOptions.children());
        $('input.fieldset-process').prop('checked', false);
        $('.check-and-fix .task-subject').removeClass('selected');
        $('.check-and-fix .task-subject-head').attr('aria-pressed', 'false');
        optionFieldsets().hide();
        $('.task-actions').hide();
        $entityField.hide();
        $sidebar.removeClass('has-selection');
    };

    // Return to the default sidebar (help + dangerous-tasks toggle).
    var showHelp = function () {
        resetRecap();
        $recap.prop('hidden', true);
        $help.prop('hidden', false);
    };

    var showProcessTask = function (clicked) {
        var current = clicked
            ? $(clicked)
            : $('input.fieldset-process:checked').first();
        var value = current.val();

        // Stash everything currently in the recap, then rebuild it: nodes are
        // never lost and stay findable by value/subject wherever they sit.
        resetRecap();

        if (!value) {
            $recap.prop('hidden', true);
            $help.prop('hidden', false);
            return;
        }
        current.prop('checked', true);

        // The active radio sits in its subject block, the stash or the recap.
        // Read the subject from its ".task-actions" wrapper (works for core and
        // for the "Module: action" fallback blocks alike).
        var $actions = current.closest('.task-actions');
        var key = $actions.attr('data-subject');
        if (key) {
            var $block = $('.task-subject[data-subject="' + key + '"]');
            $recap.find('.task-recap-name').text($block.find('.task-subject-name').text());
            $recap.find('.task-recap-desc').text($block.find('.task-subject-desc').text());
            $block.addClass('selected');
            $block.find('.task-subject-head').attr('aria-pressed', 'true');
            $recapActions.append($actions.show());
        } else {
            // Plain legacy radio (no colon, no metadata): use its label.
            $recap.find('.task-recap-name').text(current.closest('label').text().trim());
            $recap.find('.task-recap-desc').text('');
        }

        // Option panel(s) carry the value as a css class; find anywhere in
        // form.
        var $options = $form.find('fieldset.' + value);
        if ($options.length) {
            $recapOptions.append($options.show());
        }
        if (entityTypesTasks.includes(value)) {
            $recapOptions.append($entityField.show());
        }

        $help.prop('hidden', true);
        $recap.prop('hidden', false);
        $sidebar.addClass('has-selection');
    };

    // Click a subject header: select it, preselecting its first action. The
    // action group ".task-actions" carries the subject and holds the radios
    // wherever it currently sits (block, recap or stash), so find them there.
    var selectSubject = function () {
        var $subject = $(this).closest('.task-subject');
        // Clicking the already-open task closes it and shows the default view.
        if ($subject.hasClass('selected')) {
            showHelp();
            return;
        }
        var key = $subject.attr('data-subject');
        var $radio = $('.task-actions[data-subject="' + key + '"] input.fieldset-process:not(:disabled)').first();
        if ($radio.length) {
            showProcessTask($radio[0]);
        }
    };

    var filterTasks = function () {
        var query = $(this).val().trim().toLowerCase();
        $('.check-and-fix .task-subject, .check-and-fix .task-group').each(function () {
            var match = !query || $(this).text().toLowerCase().indexOf(query) !== -1;
            $(this).toggleClass('task-hidden-filter', !match);
        });
        updateEmptySections();
    };

    var addFilter = function () {
        var placeholder = $form.data('filter-placeholder') || 'Filter tasks…';
        var $filter = $('<div class="task-filter"><input type="search" class="task-filter-input"></div>');
        $filter.find('input').attr('placeholder', placeholder).attr('aria-label', placeholder);
        $filter.insertBefore('.check-and-fix fieldset.field-container:first');
        $filter.find('input').on('input', filterTasks);
    };

    /* Init */

    buildSubjects();
    labelBareCheckboxes();
    optionFieldsets().hide();
    $('.check-and-fix .task-actions').hide();
    addFilter();

    // Reserve the space for the always-open sidebar (shrinks #content).
    if (window.Omeka && Omeka.reserveSidebarSpace) {
        Omeka.reserveSidebarSpace();
    }

    $('.check-and-fix').on('click', '.task-subject-head', selectSubject);
    $('.check-and-fix .fieldset-process').on('change', function () {
        showProcessTask(this);
    });
    $('#toggle_tasks_with_warning').on('click', hideTasksWarning);

    // UTF-8 record types: checking a specific type unchecks "All".
    $('.check-and-fix').on('change', '#db_utf8_encode-type_resources_field input[type="checkbox"]', function () {
        if (this.checked && this.value !== 'all') {
            $('#db_utf8_encode-type_resources_field input[type="checkbox"][value="all"]').prop('checked', false);
        }
    });

    hideTasksWarning();
    showProcessTask();

});
