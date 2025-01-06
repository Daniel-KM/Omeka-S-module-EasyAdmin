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

    const showProcessTask = function () {
        const currentTask = $('input[type=radio].fieldset-process:checked');
        const currentTaskVal = currentTask.val();
        // Get the current value and reset and hide all of them.
        const radioTasks = $('input[type=radio].fieldset-process');
        radioTasks.prop('checked', false);
        $('fieldset.field-container > fieldset').hide();
        // Show the selected container if any.
        if (currentTaskVal && currentTaskVal !== '') {
            currentTask.prop('checked', true);
            $('fieldset.field-container > fieldset.' + currentTaskVal).show();
        }
    }

    $('.check-and-fix fieldset.field-container > fieldset').hide();

    $('.check-and-fix .fieldset-process').on('click', showProcessTask);

    $('input[name="toggle_tasks_with_warning"]').on('click', hideTasksWarning);

    /* Init */

    hideTasksWarning();

    showProcessTask();

});
