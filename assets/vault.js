/**
 * HILG Vault folder browser.
 *
 * Progressive enhancement only. Every link this script intercepts already
 * works on its own, so nothing here is load bearing for access to a file.
 *
 * Security note. Nothing user supplied is ever assigned as HTML. Rows are
 * built with createElement and text is set with textContent, so a file named
 * with a script tag is displayed as that literal text and cannot execute.
 * File names in this system are supplied by external network members and read
 * by everyone else, which makes them untrusted input by definition. Escaping
 * strings by hand would work until the day someone adds a field and forgets;
 * building nodes removes the possibility rather than guarding against it.
 *
 * Accessibility notes, which are requirements rather than polish here:
 *   - navigation moves focus into the new listing, so keyboard and screen
 *     reader users are not stranded on a control that no longer exists,
 *   - a live region announces what changed,
 *   - the download step announces itself, because minting a signed URL takes
 *     a moment and silence reads as a broken button.
 */
(function () {
    'use strict';

    if (typeof window.hilgVault === 'undefined') {
        return;
    }

    var config = window.hilgVault;

    var ICONS = {
        folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
        file: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z M14 3v5h5',
        lock: 'M8 10V7a4 4 0 0 1 8 0v3'
    };

    var SVG_NS = 'http://www.w3.org/2000/svg';

    function api(path, options) {
        var settings = options || {};

        return fetch(config.root + path, {
            method: settings.method || 'GET',
            credentials: 'same-origin',
            headers: Object.assign(
                { 'X-WP-Nonce': config.nonce },
                settings.body ? { 'Content-Type': 'application/json' } : {}
            ),
            body: settings.body ? JSON.stringify(settings.body) : undefined
        });
    }

    function announce(root, message) {
        var status = root.querySelector('.hilg-vault__status');

        if (status) {
            status.textContent = message;
        }
    }

    function formatSize(bytes) {
        var value = Number(bytes);

        if (!value || value < 0) {
            return '';
        }

        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        var scaled = value / Math.pow(1024, index);

        return (index === 0 ? scaled : scaled.toFixed(1)) + ' ' + units[index];
    }

    /**
     * Icons are built as SVG nodes from a fixed path table. The table is a
     * constant in this file, never anything that arrives from the network.
     */
    function buildIcon(kind) {
        var svg = document.createElementNS(SVG_NS, 'svg');

        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '28');
        svg.setAttribute('height', '28');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.6');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('aria-hidden', 'true');

        if (kind === 'lock') {
            var rect = document.createElementNS(SVG_NS, 'rect');

            rect.setAttribute('x', '4');
            rect.setAttribute('y', '10');
            rect.setAttribute('width', '16');
            rect.setAttribute('height', '10');
            rect.setAttribute('rx', '2');
            svg.appendChild(rect);
        }

        (ICONS[kind] || ICONS.file).split(' M').forEach(function (segment, index) {
            var path = document.createElementNS(SVG_NS, 'path');

            path.setAttribute('d', index === 0 ? segment : 'M' + segment);
            svg.appendChild(path);
        });

        return svg;
    }

    function buildRow(options) {
        var item = document.createElement('li');

        item.className = 'hilg-vault__item hilg-vault__item--' + options.kind;

        if (options.locked) {
            item.classList.add('is-locked');
        }

        var link = document.createElement('a');

        link.className = 'hilg-vault__link';
        link.setAttribute('href', options.href);

        if (options.ariaLabel) {
            link.setAttribute('aria-label', options.ariaLabel);
        }

        if (options.dataset) {
            Object.keys(options.dataset).forEach(function (key) {
                link.dataset[key] = options.dataset[key];
            });
        }

        var icon = document.createElement('span');

        icon.className = 'hilg-vault__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.appendChild(buildIcon(options.icon));

        var name = document.createElement('span');

        name.className = 'hilg-vault__name';
        name.textContent = options.name;

        link.appendChild(icon);
        link.appendChild(name);

        if (options.meta) {
            var meta = document.createElement('span');

            meta.className = 'hilg-vault__meta';
            meta.textContent = options.meta;
            link.appendChild(meta);
        }

        item.appendChild(link);

        return item;
    }

    function buildFolderRow(folder) {
        var locked = folder.locked === true;
        var id = Number(folder.id) || 0;

        return buildRow({
            kind: 'folder',
            locked: locked,
            icon: locked ? 'lock' : 'folder',
            name: String(folder.name == null ? '' : folder.name),
            meta: locked ? 'Password required' : '',
            href: '?hilg_folder=' + id,
            dataset: { folder: String(id) }
            // No ariaLabel: the accessible name comes from the visible text,
            // so the two cannot drift apart (WCAG 2.5.3).
        });
    }

    function buildFileRow(file) {
        var id = Number(file.id) || 0;
        var extension = String(file.extension || '').toUpperCase();
        var size = formatSize(file.size_bytes);

        return buildRow({
            kind: 'file',
            icon: 'file',
            name: String(file.name == null ? '' : file.name),
            meta: (extension + ' ' + size).trim(),
            href: config.root + '/files/' + id + '/download',
            dataset: { file: String(id) }
        });
    }

    /**
     * Replaces the rows in place. The container itself is never removed, so a
     * folder that happens to be empty does not break the next navigation.
     */
    function renderListing(root, folders, files) {
        var list = root.querySelector('.hilg-vault__items');
        var notice = root.querySelector('.hilg-vault__notice');

        if (!list) {
            return;
        }

        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }

        if (!folders.length && !files.length) {
            if (!notice) {
                notice = document.createElement('p');
                notice.className = 'hilg-vault__notice';
                list.parentNode.insertBefore(notice, list.nextSibling);
            }

            notice.textContent = config.i18n.empty;
            notice.hidden = false;
            list.hidden = true;

            return;
        }

        if (notice) {
            notice.hidden = true;
        }

        list.hidden = false;

        var fragment = document.createDocumentFragment();

        folders.forEach(function (folder) {
            fragment.appendChild(buildFolderRow(folder));
        });

        files.forEach(function (file) {
            fragment.appendChild(buildFileRow(file));
        });

        list.appendChild(fragment);
    }

    /**
     * Keeps the trail in step with the listing. Without this, moving through
     * folders client side leaves the only route back up on the browser's back
     * button, which is not a route a keyboard user should have to find.
     */
    function renderBreadcrumbs(root, trail) {
        var nav = root.querySelector('.hilg-vault__breadcrumbs');

        if (!nav) {
            return;
        }

        var list = nav.querySelector('ol');

        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }

        if (!trail.length) {
            nav.hidden = true;

            return;
        }

        nav.hidden = false;

        var rootItem = document.createElement('li');
        var rootLink = document.createElement('a');

        rootLink.setAttribute('href', '?hilg_folder=0');
        rootLink.dataset.folder = '0';
        rootLink.textContent = 'All files';
        rootItem.appendChild(rootLink);
        list.appendChild(rootItem);

        trail.forEach(function (crumb, index) {
            var item = document.createElement('li');

            if (index === trail.length - 1) {
                var current = document.createElement('span');

                current.setAttribute('aria-current', 'page');
                current.textContent = String(crumb.name);
                item.appendChild(current);
            } else {
                var link = document.createElement('a');

                link.setAttribute('href', '?hilg_folder=' + Number(crumb.id));
                link.dataset.folder = String(Number(crumb.id));
                link.textContent = String(crumb.name);
                item.appendChild(link);
            }

            list.appendChild(item);
        });
    }

    function loadFolder(root, folderId) {
        announce(root, config.i18n.loading);
        root.classList.add('is-loading');

        var requests = [api('/folders?parent=' + folderId)];

        if (folderId > 0) {
            requests.push(api('/folders/' + folderId + '/files'));
            requests.push(api('/folders/' + folderId + '/breadcrumbs'));
        }

        Promise.all(requests)
            .then(function (responses) {
                return Promise.all(responses.map(function (response) {
                    return response.ok ? response.json() : [];
                }));
            })
            .then(function (payloads) {
                var folders = Array.isArray(payloads[0]) ? payloads[0] : [];
                var files = Array.isArray(payloads[1]) ? payloads[1] : [];
                var trail = Array.isArray(payloads[2]) ? payloads[2] : [];

                renderListing(root, folders, files);
                renderBreadcrumbs(root, trail);
                root.dataset.folder = String(folderId);
                root.classList.remove('is-loading');

                announce(root, folders.length + ' folders, ' + files.length + ' files');

                var first = root.querySelector('.hilg-vault__link');

                if (first) {
                    first.focus();
                }
            })
            .catch(function () {
                root.classList.remove('is-loading');
                announce(root, config.i18n.failed);
            });
    }

    function requestDownload(root, fileId, trigger) {
        announce(root, config.i18n.downloading);
        trigger.setAttribute('aria-busy', 'true');

        api('/files/' + fileId + '/download')
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('denied');
                }

                return response.json();
            })
            .then(function (payload) {
                trigger.removeAttribute('aria-busy');
                announce(root, '');

                // The signed URL is short lived and never written into the
                // page, so it cannot be lifted out of the markup afterwards.
                window.location.assign(payload.url);
            })
            .catch(function () {
                trigger.removeAttribute('aria-busy');
                announce(root, config.i18n.failed);
            });
    }

    function bindPasswordForm(root) {
        var form = root.querySelector('.hilg-vault__password');

        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var input = form.querySelector('input[type="password"]');
            var error = form.querySelector('.hilg-vault__password-error');
            var folderId = parseInt(form.dataset.folder, 10);

            api('/unlock/' + folderId, {
                method: 'POST',
                body: { password: input.value }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('rejected');
                    }

                    window.location.reload();
                })
                .catch(function () {
                    error.textContent = config.i18n.wrongPass;
                    error.hidden = false;
                    input.value = '';
                    input.focus();
                });
        });
    }

    function bindSearch(root) {
        var input = root.querySelector('.hilg-vault__search-input');

        if (!input) {
            return;
        }

        var timer = null;

        input.addEventListener('input', function () {
            window.clearTimeout(timer);

            timer = window.setTimeout(function () {
                var term = input.value.trim().toLowerCase();
                var items = root.querySelectorAll('.hilg-vault__item');
                var shown = 0;

                Array.prototype.forEach.call(items, function (item) {
                    var name = item.querySelector('.hilg-vault__name');
                    var text = name ? name.textContent.toLowerCase() : '';
                    var match = term === '' || text.indexOf(term) !== -1;

                    item.hidden = !match;

                    if (match) {
                        shown += 1;
                    }
                });

                announce(root, shown + ' items');
            }, 200);
        });
    }

    function bindNavigation(root) {
        root.addEventListener('click', function (event) {
            var link = event.target.closest('a[data-folder], a[data-file]');

            if (!link || !root.contains(link)) {
                return;
            }

            if (link.parentElement && link.parentElement.classList.contains('is-locked')) {
                return; // Let a locked folder load its password page normally.
            }

            event.preventDefault();

            if (link.dataset.file) {
                requestDownload(root, parseInt(link.dataset.file, 10), link);

                return;
            }

            var folderId = parseInt(link.dataset.folder, 10);

            loadFolder(root, folderId);

            if (window.history && window.history.pushState) {
                window.history.pushState({ folder: folderId }, '', link.getAttribute('href'));
            }
        });

        window.addEventListener('popstate', function (event) {
            if (event.state && typeof event.state.folder === 'number') {
                loadFolder(root, event.state.folder);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.hilg-vault'), function (root) {
            bindNavigation(root);
            bindSearch(root);
            bindPasswordForm(root);
        });
    });
}());
