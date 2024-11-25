$(document).ready(function() {

    const hideTasksWarning = function () {
        const tasksWarning = $('#form-check-and-fix').data('tasks-warning').split(',');
        $('.check-and-fix .fieldset-process')
            .filter((index, el) =>  tasksWarning.includes($(el).val()))
            .each(function () {
                $(this).prop('disabled', !$(this).prop('disabled'));
                $(this).closest('label').css('opacity: ' + ($(this).prop('disabled') ? '0.5' : '1'));
                $(this).closest('label').toggle();
            });
    };

    $('.check-and-fix fieldset.field-container > fieldset').hide();

    $('.check-and-fix .fieldset-process').on('click', function() {
        // Reset all other processes when one is set.
        $('.fieldset-process').prop('checked', false);
        $(this).prop('checked', true);
        // Hide all containers, except the current one.
        $('fieldset.field-container > fieldset').hide();
        $('fieldset.field-container > fieldset.' + $(this).val()).show();
    });

    $('input[name="toggle_tasks_with_warning"]').on('click', hideTasksWarning);

    /* Init */

    hideTasksWarning();

});
