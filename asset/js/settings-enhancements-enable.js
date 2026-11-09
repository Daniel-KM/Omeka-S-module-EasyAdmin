'use strict';

/**
 * Add a button on the settings pages to enable the settings enhancements
 * (live filter and section navigation), shown only when they are disabled.
 *
 * @see \EasyAdmin\Module::appendSettingsEnhancementsButton()
 */
(function () {
    function init() {
        var cfg = window.EasyAdmin && window.EasyAdmin.enableEnhancements;
        if (!cfg || !cfg.url) {
            return;
        }
        var actions = document.getElementById('page-actions');
        if (!actions) {
            return;
        }

        // The page actions are inside the form of the settings, so the button
        // submits a separate form through its attribute "form".
        var form = document.createElement('form');
        form.method = 'post';
        form.action = cfg.url;
        form.id = 'easyadmin-enable-enhancements';
        form.hidden = true;

        var csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf';
        csrf.value = cfg.csrf || '';
        form.appendChild(csrf);
        document.body.appendChild(form);

        var button = document.createElement('button');
        button.type = 'submit';
        button.setAttribute('form', form.id);
        button.className = 'button easyadmin-enable-enhancements';
        button.textContent = cfg.label || 'Enable filters';
        if (cfg.title) {
            button.title = cfg.title;
        }

        var submit = actions.querySelector('button:not([type]), button[type="submit"], input[type="submit"]');
        actions.insertBefore(button, submit);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
