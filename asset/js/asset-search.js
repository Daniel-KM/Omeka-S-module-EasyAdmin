/**
 * Asset search functionality for the sidebar.
 *
 * Extends the core asset-form.js to add search by filename.
 *
 * @see https://github.com/Daniel-KM/Omeka-S-module-Common/issues/3
 */
(function ($) {
    $(document).ready(function () {
        var searchTimeout = null;
        var pendingFocusRestore = null;

        // Get the sidebar element.
        function getSidebar() {
            return $('#asset-sidebar');
        }

        // Get the selecting form's sidebar URL.
        function getSidebarUrl() {
            var sidebar = getSidebar();
            var selectingForm = sidebar.data('selectingForm');
            if (selectingForm && selectingForm.length) {
                return selectingForm.find('.asset-form-select').data('sidebar-content-url');
            }
            return null;
        }

        // Get current filter values.
        function getFilterValues() {
            var params = {};
            var ownerId = $('#asset-sidebar #filter-owner').val();
            var search = $('#asset-sidebar #filter-search').val();

            if (ownerId) {
                params.owner_id = ownerId;
            }
            if (search && search.trim()) {
                params.search = search.trim();
            }
            return params;
        }

        // Restore focus to search input.
        function restoreFocus() {
            if (!pendingFocusRestore) {
                return;
            }
            var newSearchInput = document.getElementById('filter-search');
            if (newSearchInput) {
                newSearchInput.focus();
                var pos = pendingFocusRestore.cursorPosition;
                if (newSearchInput.setSelectionRange) {
                    newSearchInput.setSelectionRange(pos, pos);
                }
            }
            pendingFocusRestore = null;
        }

        // Refresh sidebar with current filters.
        function refreshSidebar(restoreFocusAfter) {
            var url = getSidebarUrl();
            if (!url) {
                return;
            }

            // Store focus restore info before reload.
            if (restoreFocusAfter) {
                var searchInput = document.getElementById('filter-search');
                pendingFocusRestore = {
                    cursorPosition: searchInput ? (searchInput.selectionStart || 0) : 0
                };
            } else {
                pendingFocusRestore = null;
            }

            Omeka.populateSidebarContent(getSidebar(), url, getFilterValues());
        }

        // Use ajaxComplete to detect when sidebar content is loaded.
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (pendingFocusRestore && settings.url && settings.url.indexOf('sidebar-select') !== -1) {
                // Small delay to ensure DOM is updated.
                setTimeout(restoreFocus, 50);
            }
        });

        // Override the owner filter change to also include search.
        $('#content').off('change', '#filter-owner').on('change', '#filter-owner', function() {
            refreshSidebar(false);
        });

        // Handle search input with debounce.
        $('#content').on('input', '#filter-search', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                refreshSidebar(true);
            }, 1000);
        });

        // Handle Enter key in search field.
        $('#content').on('keypress', '#filter-search', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                clearTimeout(searchTimeout);
                refreshSidebar(true);
            }
        });

        // Track the selecting form from asset-form.js events.
        $('#content').on('click', '.asset-form-select', function () {
            getSidebar().data('selectingForm', $(this).closest('.asset-form-element'));
        });

        // Handle pagination with filters preservation.
        $('#content').on('click', '#asset-sidebar .pagination a', function (e) {
            e.preventDefault();
            var pageUrl = $(this).attr('href');
            var params = getFilterValues();

            var separator = pageUrl.indexOf('?') === -1 ? '?' : '&';
            var queryString = $.param(params);
            if (queryString) {
                pageUrl += separator + queryString;
            }

            Omeka.populateSidebarContent(getSidebar(), pageUrl);
        });
    });
})(jQuery);
