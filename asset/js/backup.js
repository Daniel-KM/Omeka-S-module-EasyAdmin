'use strict';

(function() {

    document.addEventListener('DOMContentLoaded', function() {
        initDeleteBackup();
    });

    /**
     * Initialize delete backup sidebar functionality.
     */
    function initDeleteBackup() {
        document.querySelectorAll('.delete-backup').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var url = this.dataset.urlSidebarContent;
                var sidebar = document.getElementById('sidebar');
                var content = sidebar.querySelector('.sidebar-content');

                fetch(url)
                    .then(function(response) {
                        return response.text();
                    })
                    .then(function(html) {
                        content.innerHTML = html;
                        Omeka.openSidebar(jQuery(sidebar));
                    });
            });
        });
    }

})();
