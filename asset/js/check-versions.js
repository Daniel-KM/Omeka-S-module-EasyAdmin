'use strict';

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
            var lastVersions = {};
            data.split("\n").forEach(line => {
                line = line.trim();
                if (!line) return;
                const moduleVersion = line.split("\t");
                if (moduleVersion.length >= 2) {
                    lastVersions[moduleVersion[0].trim()] = moduleVersion[1].trim();
                }
            });
            $('#modules .module').each(function(index) {
                const module = $(this);
                const moduleAddon = module.find('.version-notification');
                var addon;
                // Get new versions for disabled versions too.
                const  moduleName = module.find('.module-name').text().trim();
                if (moduleAddon.length) {
                    addon = moduleAddon;
                } else if (notifyVersionInactive && unmanagedAddons[moduleName]) {
                    const moduleId = unmanagedAddons[moduleName].id;
                    const moduleVersion = unmanagedAddons[moduleName].version;
                    module.find('.module-meta')
                        .append(`<div class="version-notification module-unmanaged" style="display: none;" data-addon-id="${moduleId}" data-current-version="${moduleVersion}">${msgNewVersion}</div>`);
                    addon = module.find('.version-notification');
                } else {
                    return;
                }
                const addonId = addon.data('addon-id');
                // Ensure version is a string (jQuery.data() may do type coercion).
                const currentVersion = String(addon.data('current-version') || '');
                const lastVersion = addonId in lastVersions ? lastVersions[addonId] : null;
                const lastVersionIsDev = lastVersion && lastVersion.match(/alpha|beta|dev/);
                if (lastVersion
                    && (notifyVersionDev || !lastVersionIsDev)
                ) {
                    if (lastVersionIsDev) {
                        addon.addClass('new-version-is-dev');
                    }
                    // Js package semver was replaced by compareVersions since Omeka S v4.0.1.
                    // Still try original comparator to keep original url.
                    if (typeof compareVersions !== 'undefined') {
                        try {
                            if (compareVersions.compareVersions(currentVersion, lastVersion) === -1) {
                                addon.show();
                            }
                            return;
                        } catch (e) {
                            console.warn('EasyAdmin: compareVersions error for', addonId, ':', e.message, {currentVersion, lastVersion});
                        }
                    } else if (typeof semver !== 'undefined') {
                        try {
                            if (semver.lt(currentVersion, lastVersion)) {
                                addon.show();
                            }
                            return;
                        } catch (e) {
                            console.warn('EasyAdmin: semver error for', addonId, ':', e.message, {currentVersion, lastVersion});
                        }
                    }
                    if (compareVersionNumbers(currentVersion, lastVersion) < 0) {
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
