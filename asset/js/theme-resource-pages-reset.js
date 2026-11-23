/**
 * Add a button to reset the resource pages to the configuration of the theme.
 *
 * The page of the core has no way to go back to the blocks of the theme once
 * they were saved for the site, and it has no event to append a button, so the
 * button and its sidebar are added here. A theme or a module may replace the
 * template of the page, so nothing of it is required but "#page-actions".
 */

(function () {
    'use strict';

    var data = window.EasyAdminResetResourcePages;
    if (!data) {
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var actions = document.getElementById('page-actions');
        var content = document.getElementById('content');
        if (!actions || !content || document.getElementById('sidebar-easyadmin-reset-blocks')) {
            return;
        }
        // The core, or a template that follows it, may provide the button
        // itself: it is not doubled then.
        if (document.getElementById('sidebar-reset-blocks')
            || document.querySelector('[name="reset_blocks"]')
        ) {
            return;
        }

        var link = document.createElement('a');
        link.href = '#';
        link.className = 'button sidebar-content';
        link.dataset.sidebarSelector = '#sidebar-easyadmin-reset-blocks';
        link.textContent = data.label;
        actions.insertBefore(link, actions.lastElementChild);

        var sidebar = document.createElement('div');
        sidebar.id = 'sidebar-easyadmin-reset-blocks';
        sidebar.className = 'sidebar';
        sidebar.innerHTML = '<a href="#" class="sidebar-close o-icon-close"></a>'
            + '<div class="sidebar-content"><div id="sidebar-confirm">'
            + '<h3></h3><p></p>'
            + '<form method="post"><input type="hidden" name="site-slug">'
            + '<input type="hidden" name="' + data.csrfName + '">'
            + '<button type="submit" class="button red"></button>'
            + '</form></div></div>';
        sidebar.querySelector('.sidebar-close').title = data.close;
        sidebar.querySelector('.sidebar-close').setAttribute('aria-label', data.close);
        sidebar.querySelector('h3').textContent = data.label;
        sidebar.querySelector('p').textContent = data.warning;
        sidebar.querySelector('form').action = data.url;
        sidebar.querySelector('input[name="site-slug"]').value = data.siteSlug;
        sidebar.querySelector('input[name="' + data.csrfName + '"]').value = data.csrfValue;
        sidebar.querySelector('button').textContent = data.confirm;
        content.appendChild(sidebar);
    });
})();
