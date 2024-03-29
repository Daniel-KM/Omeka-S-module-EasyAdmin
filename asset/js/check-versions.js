$(document).ready(function() {
    /**
     * Compare two software version numbers (e.g. 1.7.1).
     *
     * This function works only with numerical parts.
     *
     * @see https://jsfiddle.net/ripper234/Xv9WL/28/
     * @see https://stackoverflow.com/a/6832721/11236
     */
    function compareVersionNumbers(v1, v2) {
        var v1parts = v1.toString().split('.');
        var v2parts = v2.toString().split('.');

        function isPositiveInteger(x) {
            // http://stackoverflow.com/a/1019526/11236
            return /^\d+$/.test(x);
        }

        // First, validate both numbers are true version numbers
        if (!v1parts.every(isPositiveInteger) || !v2parts.every(isPositiveInteger)) {
            return NaN;
        }

        v1parts = v1parts.map(Number);
        v2parts = v2parts.map(Number);

        for (var i = 0; i < v1parts.length; ++i) {
            if (v2parts.length === i) {
                return 1;
            }

            if (v1parts[i] === v2parts[i]) {
                continue;
            }
            return v1parts[i] > v2parts[i] ? 1 : -1;
        }

        if (v1parts.length !== v2parts.length) {
            return -1;
        }

        return 0;
    }

    /**
     * Check versions for any module, with any version format, and display a message.
     *
     * Adapted from Omeka S application/view/omeka/admin/module/browse.php,
     * that supports only semantic versioning schema and doesn't manage external repositories.
     */
    $.get('https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_modules_versions.tsv')
        .done(function(data) {
            $('.version-notification').each(function(index) {
                var addon = $(this);
                var addonId = addon.data('addon-id');
                var lastVersions = {};
                data.split("\n").forEach(line => {
                    const moduleVersion = line.split("\t");
                    lastVersions[moduleVersion[0]] = moduleVersion[1];
                });
                if (addonId in lastVersions) {
                    // Still try semver to keep original url.
                    try {
                        if (semver.lt(addon.data('current-version'), lastVersions[addonId])) {
                            addon.show();
                        }
                        return;
                    } catch (e) {
                    }
                    if (compareVersionNumbers(addon.data('current-version'), lastVersions[addonId]) < 0) {
                        // Update the message with the module url if any.
                        var link = addon.closest('.module-meta').find('.module-name a');
                        link.length
                            ? addon.find('a').prop('href', link[0].href)
                            : addon.find('a').remove();
                        addon.show();
                    }
                }
            });
        });

});