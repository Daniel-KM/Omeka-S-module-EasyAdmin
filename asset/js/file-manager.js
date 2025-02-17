'use strict';

(function ($) {
    $(document).ready(function() {

        // Complete the batch delete form after confirmation.
        $('#confirm-delete-selected, #confirm-delete-all').on('submit', function(e) {
            var confirmForm = $(this);
            if ('confirm-delete-all' === this.id) {
                confirmForm.append($('.batch-query').clone());
            } else {
                $('#batch-form').find('input[name="filenames[]"]:checked').each(function() {
                    confirmForm.append($(this).clone().prop('disabled', false).attr('type', 'hidden'));
                });
            }
        });

        $('.delete-all').on('click', function(e) {
            Omeka.closeSidebar($('#sidebar-delete-selected'));
        });

        $('.delete-selected').on('click', function(e) {
            Omeka.closeSidebar($('#sidebar-delete-all'));
            var inputs = $('input[name="filenames[]"]');
            $('#delete-selected-count').text(inputs.filter(':checked').length);
        });

        $('#sidebar-delete-all').on('click', 'input[name="confirm-delete-all-check"]', function(e) {
            $('#confirm-delete-all input[type="submit"]').prop('disabled', this.checked ? false : true);
        });

    });
})(jQuery);
