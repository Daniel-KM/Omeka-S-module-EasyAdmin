$(document).ready(function() {

    $('.check-and-fix fieldset.field-container > fieldset').hide();

    $('.check-and-fix .fieldset-process').on('click', function() {
        // Reset all other processes when one is set.
        $('.fieldset-process').prop('checked', false);
        $(this).prop('checked', true);
        // Hide all containers, except the current one.
        $('fieldset.field-container > fieldset').hide();
        $('fieldset.field-container > fieldset.' + $(this).val()).show();
    });

});
