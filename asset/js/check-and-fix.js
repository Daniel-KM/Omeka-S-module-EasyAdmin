'use strict';

/**
 * Check and fix form: behaviour of the tasks that loop on a resource type with
 * an optional query.
 *
 * - Scope the query builder to the resource type selected in the radio. The
 *   query-form reads data('resourceType') on demand and, when its edit sidebar
 *   is open, it is reloaded for the new type.
 * - Show a visible warning on the query field when "All" is selected, since a
 *   single query is then applied identically to every type.
 * - Note: the Omeka Query element only supports items/item_sets/media; the
 *   others fall back to items.
 */
(function ($) {
    var TYPED = [
        'items',
        'item_sets',
        'media',
    ];
    var FIELDSETS = [
        'db_loop_save',
        'db_value_clean',
    ];

    function setup(prefix, warningText) {
        var $radios = $('input[name="resource_values[' + prefix + '][resource_types]"]');
        if (!$radios.length) {
            return;
        }

        var $query = $('#' + prefix + '-query').closest('.query-form-element');
        var $warning = $('<p>')
            .addClass('messages warning db-loop-query-warning')
            .text(warningText)
            .prop('hidden', true);
        if ($query.length) {
            $query.after($warning);
        }

        function sync() {
            var value = $radios.filter(':checked').val();
            if ($query.length) {
                var resourceType = TYPED.indexOf(value) !== -1 ? value : 'items';
                $query.data('resourceType', resourceType);
                // Reload the open advanced search form for the new type, so the
                // available filters match the selected resource type.
                var $sidebar = $('#query-sidebar-edit');
                if ($sidebar.hasClass('active')) {
                    var url = $query.data('sidebar-edit-url');
                    var query = $query.find('.query-form-query').val().trim().replace(/^\?+/, '');
                    Omeka.populateSidebarContent($sidebar, url + '?' + query, {
                        query_resource_type: resourceType,
                        query_partial_excludelist: JSON.stringify($query.data('partialExcludelist') || [])
                    });
                }
            }
            $warning.prop('hidden', value !== 'all');
        }

        $radios.on('change', sync);
        sync();
    }

    $(function () {
        var warningText = $('#check-and-fix-form').data('dbLoopWarning') || '';
        FIELDSETS.forEach(function (prefix) {
            setup(prefix, warningText);
        });
    });
})(jQuery);
