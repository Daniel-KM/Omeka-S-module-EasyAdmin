/**
 * Context of the last browse or search, kept by the browser in the session
 * storage, so the navigation between resources requires no server session.
 *
 * A new tab does not get the session storage of the tab that opened it, so the
 * context is copied in the local storage too and used as fallback for a new
 * tab, during one day only.
 *
 * Duplicated in modules AdvancedSearch, BlockPlus, EasyAdmin and Menu: keep the
 * copies identical. The first loaded copy is used.
 *
 * The context is a json object stored under the key "omeka:browse-context":
 * - site (string): slug of the site.
 * - page (string): path and query of the page that wrote the context, used to
 *   merge the data written by several modules on the same page.
 * - url (string): url to go back to the list.
 * - label (string), type (string), subtype (string): description of the list.
 * - resource (string): resource name of the list, for example "items".
 * - query (object): query of the list, for a navigation in all the results.
 * - ids (int[]), total (int), limit (int): first ids of the results.
 * - item_set_id (int), selection_id (int): source of the list.
 *
 * Enhanced elements:
 * - [data-browse-context-endpoint]: replaced by the html returned by the
 *   endpoint for the context, when the context matches the resource. The
 *   attribute data-browse-context-require is "ids" (the resource is one of the
 *   ids, the default), "query" (same resource name and a query) or "any" (the
 *   ids if any, else the query).
 * - a[data-browse-back]: href replaced by the url of the context.
 * - [data-browse-context-crumb]: breadcrumb, with a link to the list inserted
 *   before the current resource. The attributes data-label-default and
 *   data-label-search ("%s" replaced by the query) set the label.
 */
(function () {
    'use strict';

    if (window.OmekaBrowseContext) {
        return;
    }

    const key = 'omeka:browse-context';

    const maxAgeShared = 86400000;

    const parse = function (storage) {
        try {
            const context = JSON.parse(storage.getItem(key));
            return context && typeof context === 'object' ? context : null;
        } catch (e) {
            return null;
        }
    };

    const read = function () {
        const context = parse(window.sessionStorage);
        if (context) {
            return context;
        }
        const shared = parse(window.localStorage);
        return shared && Date.now() - (shared.time || 0) < maxAgeShared
            ? shared
            : null;
    };

    const write = function (data) {
        try {
            const page = window.location.pathname + window.location.search;
            const current = parse(window.sessionStorage);
            const context = current && current.page === page
                ? Object.assign(current, data)
                : Object.assign({}, data);
            context.page = page;
            context.time = Date.now();
            window.sessionStorage.setItem(key, JSON.stringify(context));
            window.localStorage.setItem(key, JSON.stringify(context));
        } catch (e) {
            // The storage may be disabled: no navigation context.
        }
    };

    const matches = function (context, element) {
        if (!context || context.site !== element.dataset.site) {
            return false;
        }
        const require = element.dataset.browseContextRequire;
        const hasIds = Array.isArray(context.ids);
        if (require === 'query' || (require === 'any' && !hasIds)) {
            return context.resource === element.dataset.resourceName
                && !!context.query && typeof context.query === 'object';
        }
        const id = parseInt(element.dataset.resourceId, 10);
        return hasIds && context.ids.map(Number).includes(id);
    };

    const isLocalUrl = function (url) {
        return typeof url === 'string' && url.charAt(0) === '/' && url.charAt(1) !== '/';
    };

    const replaceBlocks = function (context) {
        document.querySelectorAll('[data-browse-context-endpoint]').forEach(function (element) {
            if (!matches(context, element)) {
                return;
            }
            const params = new URLSearchParams();
            if (element.dataset.browseContextRequire === 'query') {
                params.set('query', JSON.stringify(context.query));
            } else {
                params.set('ids', context.ids.map(Number).join(','));
                ['type', 'subtype', 'label', 'total', 'limit', 'item_set_id', 'selection_id', 'show_label'].forEach(function (name) {
                    if (context[name] !== undefined && context[name] !== null) {
                        params.set(name, context[name]);
                    }
                });
            }
            if (isLocalUrl(context.url)) {
                params.set('url', context.url);
            }
            if (element.dataset.browseContextOptions) {
                params.set('options', element.dataset.browseContextOptions);
            }
            fetch(element.dataset.browseContextEndpoint + '?' + params.toString(), {
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            })
                .then(function (response) {
                    return response.ok ? response.text() : '';
                })
                .then(function (html) {
                    if (html.trim() !== '') {
                        element.outerHTML = html;
                    }
                })
                .catch(function () {});
        });
    };

    const replaceBackLinks = function (context) {
        if (!context || !isLocalUrl(context.url)) {
            return;
        }
        document.querySelectorAll('a[data-browse-back]').forEach(function (element) {
            if (!element.dataset.site || element.dataset.site === context.site) {
                element.setAttribute('href', context.url);
            }
        });
    };

    const insertCrumbs = function (context) {
        document.querySelectorAll('[data-browse-context-crumb]').forEach(function (element) {
            if (!isLocalUrl(context && context.url) || !matches(context, element)) {
                return;
            }
            const active = element.querySelector('[aria-current="page"]');
            const exists = Array.prototype.some.call(element.querySelectorAll('a[href]'), function (link) {
                return link.getAttribute('href') === context.url;
            });
            if (!active || exists) {
                return;
            }
            const link = document.createElement('a');
            link.setAttribute('href', context.url);
            link.className = 'browse-context';
            link.textContent = !context.label
                ? element.dataset.labelDefault
                : (context.type === 'search' ? element.dataset.labelSearch.replace('%s', context.label) : context.label);
            active.parentNode.insertBefore(link, active);
            const separator = element.querySelector('.breadcrumb-separator');
            if (separator) {
                active.parentNode.insertBefore(document.createTextNode(' '), active);
                active.parentNode.insertBefore(separator.cloneNode(true), active);
                active.parentNode.insertBefore(document.createTextNode(' '), active);
            }
        });
    };

    const enhance = function () {
        const context = read();
        replaceBlocks(context);
        replaceBackLinks(context);
        insertCrumbs(context);
    };

    window.OmekaBrowseContext = {
        key: key,
        read: read,
        write: write,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhance);
    } else {
        enhance();
    }
})();
