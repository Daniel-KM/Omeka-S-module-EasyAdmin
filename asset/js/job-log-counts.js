'use strict';

/**
 * Display the counts of logged errors and warnings next to the job status.
 */
(function () {
    const init = () => {
        const source = document.getElementById('easy-admin-job-log-counts');
        if (!source) {
            return;
        }
        const counts = JSON.parse(source.textContent);
        document.querySelectorAll('.jobs.browse table tbody tr').forEach((row) => {
            // The module Log marks the cell of the status, else use the column
            // of the core: id, class, status.
            let statusCell = row.querySelector('td.job-status[data-job-id]');
            let jobId = statusCell ? statusCell.dataset.jobId : '';
            if (!statusCell) {
                // Tablesaw may add a label in the cell, so read the link.
                const idLink = row.cells[0] ? row.cells[0].querySelector('a') : null;
                jobId = idLink ? idLink.textContent.trim() : '';
                statusCell = row.cells[2];
            }
            if (!counts[jobId] || !statusCell) {
                return;
            }
            const container = document.createElement('div');
            container.className = 'easy-admin-job-log-counts';
            Object.entries(counts[jobId]).forEach(([level, count]) => {
                const link = document.createElement('a');
                link.className = 'easy-admin-job-log-count easy-admin-job-log-' + level;
                link.href = count.url;
                link.textContent = count.label;
                container.append(link, ' ');
            });
            statusCell.append(container);
        });
    };

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
})();
