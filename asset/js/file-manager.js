'use strict';

(function ($) {
    $(document).ready(function() {

        // Auto-submit directory selector on change.
        $('#dir_path').on('change', function() {
            $('#dir-select-form').submit();
        });

        // Auto-submit when display_dir checkbox changes.
        $('.display-dir-label input').on('change', function() {
            $('#dir-select-form').submit();
        });

        // Select all checkbox.
        $('.select-all').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('input[name="filenames[]"]').prop('checked', isChecked);
            updateBatchButton();
        });

        // Update batch button when individual checkboxes change.
        $(document).on('change', 'input[name="filenames[]"]', function() {
            updateBatchButton();
        });

        function updateBatchButton() {
            var count = $('input[name="filenames[]"]:checked').length;
            var $btn = $('.batch-delete-btn');
            var $count = $('.selected-count');

            $btn.prop('disabled', count === 0);
            $count.text(count > 0 ? '(' + count + ')' : '');
        }

        // Batch delete button opens confirmation sidebar.
        $('.batch-delete-btn').on('click', function(e) {
            e.preventDefault();
            var count = $('input[name="filenames[]"]:checked').length;
            if (count === 0) return;

            $('.delete-count').text(count);
            Omeka.openSidebar($('#sidebar-confirm-batch'));
        });

        // Single file delete uses standard Omeka sidebar handling via .sidebar-content class.

        // Append selected filenames to batch delete form before submit.
        $('#sidebar-confirm-batch form').on('submit', function(e) {
            var $form = $(this);
            // Remove any previously added hidden inputs.
            $form.find('input[name="filenames[]"]').remove();
            // Add selected files.
            $('input[name="filenames[]"]:checked').each(function() {
                $form.append(
                    $('<input>')
                        .attr('type', 'hidden')
                        .attr('name', 'filenames[]')
                        .val($(this).val())
                );
            });
            // Add dir_path.
            if (!$form.find('input[name="dir_path"]').length) {
                $form.append(
                    $('<input>')
                        .attr('type', 'hidden')
                        .attr('name', 'dir_path')
                        .val($('#file-list-form input[name="dir_path"]').val())
                );
            }
        });

    });
})(jQuery);
