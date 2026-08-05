/**
 * HILG Vault administration screen.
 *
 * Three responsibilities: the folder tree, the file listing, and uploads.
 *
 * Uploads are the interesting part. A file under the multipart threshold goes
 * in one signed PUT. Anything larger is split, and the parts are uploaded
 * through a small concurrency pool rather than all at once: a naive
 * Promise.all over a gigabyte file opens hundreds of parallel requests, which
 * the browser queues anyway while memory climbs. A pool of four keeps the
 * throughput and makes a resumed upload possible, because only the parts that
 * failed need signing again.
 *
 * As on the front end, nothing user supplied is assigned as HTML. File names
 * come from external contributors and are rendered with textContent only.
 */
(function () {
    'use strict';

    if (typeof window.hilgVaultAdmin === 'undefined') {
        return;
    }

    var config = window.hilgVaultAdmin;
    var text = config.i18n;

    var CONCURRENCY = 4;
    var state = {
        folderId: 0,
        folders: {},
        editing: null
    };

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    var error = new Error(payload.message || 'Request failed');

                    error.payload = payload;
                    throw error;
                }

                return payload;
            });
        });
    }

    function el(tag, props, children) {
        var node = document.createElement(tag);

        Object.keys(props || {}).forEach(function (key) {
            if (key === 'text') {
                node.textContent = props[key];
            } else if (key === 'className') {
                node.className = props[key];
            } else if (key === 'dataset') {
                Object.keys(props[key]).forEach(function (dataKey) {
                    node.dataset[dataKey] = props[key][dataKey];
                });
            } else if (key.indexOf('on') === 0) {
                node.addEventListener(key.slice(2).toLowerCase(), props[key]);
            } else if (props[key] !== null && props[key] !== false) {
                node.setAttribute(key, props[key]);
            }
        });

        (children || []).forEach(function (child) {
            if (child) {
                node.appendChild(child);
            }
        });

        return node;
    }

    function status(message) {
        var node = document.getElementById('hilg-status');

        if (node) {
            node.textContent = message || '';
        }
    }

    function formatSize(bytes) {
        var value = Number(bytes);

        if (!value) {
            return '0 B';
        }

        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        var scaled = value / Math.pow(1024, index);

        return (index === 0 ? scaled : scaled.toFixed(1)) + ' ' + units[index];
    }

    function formatDate(value) {
        if (!value) {
            return '';
        }

        var date = new Date(String(value).replace(' ', 'T') + 'Z');

        return isNaN(date.getTime()) ? '' : date.toLocaleDateString();
    }

    // ---------------------------------------------------------------
    // Folder tree
    // ---------------------------------------------------------------

    function treeItem(folder, depth) {
        var item = el('li', { className: 'hilg-tree__item' });

        var button = el('button', {
            type: 'button',
            className: 'hilg-tree__button' + (Number(folder.id) === state.folderId ? ' is-current' : ''),
            style: 'padding-left:' + (8 + depth * 14) + 'px',
            dataset: { folder: String(folder.id) },
            'aria-current': Number(folder.id) === state.folderId ? 'true' : null,
            onClick: function () {
                selectFolder(Number(folder.id));
            }
        }, [
            el('span', { className: 'hilg-tree__label', text: String(folder.name) }),
            folder.mode && folder.mode !== 'public'
                ? el('span', {
                    className: 'hilg-tree__badge hilg-tree__badge--' + folder.mode,
                    text: folder.mode === 'password' ? 'PW' : 'Private',
                    title: config.modes[folder.mode] || folder.mode
                })
                : null
        ]);

        item.appendChild(button);

        if (folder.has_children) {
            var sublist = el('ul', { className: 'hilg-tree__list' });

            item.appendChild(sublist);
            loadChildren(Number(folder.id), sublist, depth + 1);
        }

        return item;
    }

    function loadChildren(parentId, container, depth) {
        api('/folders?parent=' + parentId).then(function (folders) {
            folders.forEach(function (folder) {
                state.folders[folder.id] = folder;
                container.appendChild(treeItem(folder, depth));
            });
        });
    }

    function loadTree() {
        var tree = document.getElementById('hilg-tree');

        while (tree.firstChild) {
            tree.removeChild(tree.firstChild);
        }

        var list = el('ul', { className: 'hilg-tree__list' });

        var rootButton = el('button', {
            type: 'button',
            className: 'hilg-tree__button hilg-tree__button--root' + (state.folderId === 0 ? ' is-current' : ''),
            onClick: function () {
                selectFolder(0);
            }
        }, [el('span', { className: 'hilg-tree__label', text: text.allFiles })]);

        list.appendChild(el('li', { className: 'hilg-tree__item' }, [rootButton]));
        tree.appendChild(list);

        loadChildren(0, list, 1);
    }

    // ---------------------------------------------------------------
    // File listing
    // ---------------------------------------------------------------

    function fileRow(file) {
        var actions = el('td', { className: 'hilg-admin__col-actions' });

        if (config.canManage) {
            actions.appendChild(el('button', {
                type: 'button',
                className: 'button-link',
                text: text.rename,
                onClick: function () {
                    renameFile(file);
                }
            }));

            actions.appendChild(document.createTextNode(' | '));

            actions.appendChild(el('button', {
                type: 'button',
                className: 'button-link hilg-admin__danger',
                text: text.delete,
                onClick: function () {
                    deleteFile(file);
                }
            }));
        }

        return el('tr', {}, [
            el('td', {}, [el('strong', { text: String(file.name) })]),
            el('td', { className: 'hilg-admin__col-size', text: formatSize(file.size_bytes) }),
            el('td', { className: 'hilg-admin__col-date', text: formatDate(file.created_at) }),
            actions
        ]);
    }

    function renderFiles(files) {
        var body = document.getElementById('hilg-file-rows');

        while (body.firstChild) {
            body.removeChild(body.firstChild);
        }

        if (!files.length) {
            body.appendChild(el('tr', {}, [
                el('td', { colspan: '4', className: 'hilg-admin__empty', text: text.empty })
            ]));

            return;
        }

        files.forEach(function (file) {
            body.appendChild(fileRow(file));
        });
    }

    function renderToolbar() {
        var actions = document.getElementById('hilg-actions');

        while (actions.firstChild) {
            actions.removeChild(actions.firstChild);
        }

        if (state.folderId === 0 || !config.canManage) {
            return;
        }

        actions.appendChild(el('button', {
            type: 'button',
            className: 'button',
            text: text.settings,
            onClick: function () {
                openDialog(state.folderId);
            }
        }));

        actions.appendChild(el('button', {
            type: 'button',
            className: 'button hilg-admin__danger',
            text: text.delete,
            onClick: deleteCurrentFolder
        }));

        // The shortcode is shown where the folder is, so an editor does not
        // have to look up an id to put the folder on a page.
        var shortcode = '[hilg_vault folder="' + state.folderId + '"]';

        actions.appendChild(el('button', {
            type: 'button',
            className: 'button hilg-admin__shortcode',
            text: shortcode,
            title: text.shortcodeHint,
            onClick: function (event) {
                navigator.clipboard.writeText(shortcode).then(function () {
                    status(text.copied + ': ' + shortcode);
                    event.target.textContent = text.copied;

                    window.setTimeout(function () {
                        event.target.textContent = shortcode;
                    }, 1500);
                });
            }
        }));
    }

    function selectFolder(folderId) {
        state.folderId = folderId;
        status(text.loading);

        var heading = document.getElementById('hilg-current-folder');
        var folder = state.folders[folderId];

        heading.textContent = folderId === 0 ? text.allFiles : (folder ? folder.name : '');

        document.getElementById('hilg-dropzone').hidden = folderId === 0 || !config.canManage;

        renderToolbar();

        document.querySelectorAll('.hilg-tree__button').forEach(function (button) {
            var isCurrent = Number(button.dataset.folder || 0) === folderId;

            button.classList.toggle('is-current', isCurrent);

            if (isCurrent) {
                button.setAttribute('aria-current', 'true');
            } else {
                button.removeAttribute('aria-current');
            }
        });

        if (folderId === 0) {
            renderFiles([]);
            status('');

            return;
        }

        api('/folders/' + folderId + '/files')
            .then(function (files) {
                renderFiles(files);
                status(files.length + ' files');
            })
            .catch(function () {
                renderFiles([]);
                status(text.failed);
            });
    }

    // ---------------------------------------------------------------
    // Uploads
    // ---------------------------------------------------------------

    function queueRow(file) {
        var bar = el('span', { className: 'hilg-queue__bar' });
        var fill = el('span', { className: 'hilg-queue__fill' });

        bar.appendChild(fill);

        var label = el('span', { className: 'hilg-queue__state', text: text.uploading });

        var row = el('li', { className: 'hilg-queue__item' }, [
            el('span', { className: 'hilg-queue__name', text: file.name }),
            bar,
            label
        ]);

        document.getElementById('hilg-queue').appendChild(row);

        return {
            progress: function (fraction) {
                fill.style.width = Math.round(fraction * 100) + '%';
            },
            done: function () {
                fill.style.width = '100%';
                row.classList.add('is-done');
                label.textContent = text.uploaded;
                window.setTimeout(function () {
                    row.remove();
                }, 2500);
            },
            fail: function (message) {
                row.classList.add('is-failed');
                label.textContent = message || text.failed;
            }
        };
    }

    /**
     * Runs tasks with a bounded number in flight. Returns a promise that
     * rejects on the first failure so the caller can abort the upload.
     */
    function pool(tasks, limit) {
        return new Promise(function (resolve, reject) {
            var index = 0;
            var active = 0;
            var failed = false;

            function next() {
                if (failed) {
                    return;
                }

                if (index >= tasks.length && active === 0) {
                    resolve();

                    return;
                }

                while (active < limit && index < tasks.length) {
                    active += 1;

                    tasks[index++]()
                        .then(function () {
                            active -= 1;
                            next();
                        })
                        .catch(function (error) {
                            failed = true;
                            reject(error);
                        });
                }
            }

            next();
        });
    }

    function putBlob(url, blob) {
        return fetch(url, { method: 'PUT', body: blob }).then(function (response) {
            if (!response.ok) {
                throw new Error('part failed');
            }

            return response.headers.get('etag');
        });
    }

    function uploadSingle(file, handshake, ui) {
        ui.progress(0.1);

        return putBlob(handshake.upload_url, file).then(function () {
            ui.progress(0.9);
        });
    }

    function uploadMultipart(file, handshake, ui) {
        var partSize = handshake.part_size;
        var count = handshake.part_count;
        var numbers = [];

        for (var i = 1; i <= count; i++) {
            numbers.push(i);
        }

        return api('/manage/uploads/' + handshake.file_id + '/parts', {
            method: 'POST',
            body: { parts: numbers }
        }).then(function (signed) {
            var etags = [];
            var completed = 0;

            var tasks = numbers.map(function (number) {
                return function () {
                    var start = (number - 1) * partSize;
                    var chunk = file.slice(start, Math.min(start + partSize, file.size));

                    return putBlob(signed.urls[number], chunk).then(function (etag) {
                        etags.push({ PartNumber: number, ETag: etag });
                        completed += 1;
                        ui.progress(completed / count);
                    });
                };
            });

            return pool(tasks, CONCURRENCY).then(function () {
                return etags;
            });
        });
    }

    function uploadFile(file) {
        var ui = queueRow(file);

        return api('/manage/folders/' + state.folderId + '/uploads', {
            method: 'POST',
            body: { name: file.name, size: file.size, type: file.type }
        })
            .then(function (handshake) {
                var transfer = handshake.mode === 'multipart'
                    ? uploadMultipart(file, handshake, ui)
                    : uploadSingle(file, handshake, ui);

                return transfer.then(function (parts) {
                    return api('/manage/uploads/' + handshake.file_id + '/complete', {
                        method: 'POST',
                        body: parts ? { parts: parts } : {}
                    });
                });
            })
            .then(function () {
                ui.done();
            })
            .catch(function (error) {
                ui.fail(error && error.payload ? error.payload.message : text.failed);
                throw error;
            });
    }

    function handleFiles(fileList) {
        var files = Array.prototype.slice.call(fileList);

        if (!files.length || state.folderId === 0) {
            return;
        }

        // Files are uploaded one after another so the concurrency pool inside
        // a large upload is not competing with other files for connections.
        var chain = Promise.resolve();

        files.forEach(function (file) {
            chain = chain.then(function () {
                return uploadFile(file).catch(function () {
                    // A failed file must not stop the rest of the batch.
                });
            });
        });

        chain.then(function () {
            selectFolder(state.folderId);
        });
    }

    function bindDropzone() {
        var zone = document.getElementById('hilg-dropzone');
        var input = document.getElementById('hilg-file-input');

        if (!zone || !input) {
            return;
        }

        input.addEventListener('change', function () {
            handleFiles(input.files);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (event) {
                event.preventDefault();
                zone.classList.add('is-over');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function (event) {
                event.preventDefault();
                zone.classList.remove('is-over');
            });
        });

        zone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files) {
                handleFiles(event.dataTransfer.files);
            }
        });
    }

    // ---------------------------------------------------------------
    // File actions
    // ---------------------------------------------------------------

    function renameFile(file) {
        var name = window.prompt(text.rename, file.name);

        if (!name || name === file.name) {
            return;
        }

        api('/manage/files/' + file.id, { method: 'PATCH', body: { name: name } })
            .then(function () {
                selectFolder(state.folderId);
            })
            .catch(function () {
                status(text.failed);
            });
    }

    function deleteFile(file) {
        if (!window.confirm(text.confirmDelete)) {
            return;
        }

        api('/manage/files/' + file.id, { method: 'DELETE' })
            .then(function () {
                selectFolder(state.folderId);
            })
            .catch(function () {
                status(text.failed);
            });
    }

    function deleteCurrentFolder() {
        if (!window.confirm(text.confirmDelete)) {
            return;
        }

        api('/manage/folders/' + state.folderId, { method: 'DELETE' })
            .then(function () {
                state.folderId = 0;
                loadTree();
                selectFolder(0);
            })
            .catch(function () {
                status(text.failed);
            });
    }

    // ---------------------------------------------------------------
    // Folder dialog and role matrix
    // ---------------------------------------------------------------

    function renderMatrix(assigned, inherited) {
        var container = document.getElementById('hilg-role-matrix');

        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }

        config.roles.forEach(function (role) {
            var current = assigned[role.slug] || {};
            var isInherited = !assigned[role.slug] && inherited && inherited[role.slug];

            var row = el('div', { className: 'hilg-matrix__row' }, [
                el('span', { className: 'hilg-matrix__role' }, [
                    el('span', { text: role.name }),
                    isInherited ? el('em', { className: 'hilg-matrix__inherited', text: ' (' + text.inherited + ')' }) : null
                ])
            ]);

            [
                { key: 'can_view', label: text.canView },
                { key: 'can_upload', label: text.canUpload },
                { key: 'can_manage', label: text.canManage }
            ].forEach(function (permission) {
                var checkbox = el('input', {
                    type: 'checkbox',
                    dataset: { role: role.slug, permission: permission.key }
                });

                checkbox.checked = Boolean(current[permission.key]);

                row.appendChild(el('label', { className: 'hilg-matrix__cell' }, [
                    checkbox,
                    el('span', { text: permission.label })
                ]));
            });

            container.appendChild(row);
        });
    }

    function collectMatrix() {
        var grants = {};

        document.querySelectorAll('#hilg-role-matrix input[type="checkbox"]').forEach(function (checkbox) {
            var role = checkbox.dataset.role;

            grants[role] = grants[role] || {};
            grants[role][checkbox.dataset.permission] = checkbox.checked;
        });

        return grants;
    }

    function togglePasswordField() {
        var selected = document.querySelector('input[name="hilg_access_mode"]:checked');

        document.getElementById('hilg-password-field').hidden =
            !selected || selected.value !== 'password';
    }

    function openDialog(folderId) {
        var dialog = document.getElementById('hilg-folder-dialog');

        state.editing = folderId;

        var folder = folderId ? state.folders[folderId] : null;

        document.getElementById('hilg-dialog-name').value = folder ? folder.name : '';
        document.getElementById('hilg-dialog-password').value = '';

        var mode = folder && folder.mode ? folder.mode : 'private';
        var radio = document.querySelector('input[name="hilg_access_mode"][value="' + mode + '"]');

        if (radio) {
            radio.checked = true;
        }

        togglePasswordField();

        document.getElementById('hilg-roles-field').hidden = !folderId;

        if (folderId) {
            api('/manage/folders/' + folderId + '/roles').then(function (payload) {
                renderMatrix(payload.assigned || {}, payload.inherited || {});
            });
        }

        dialog.showModal();
        document.getElementById('hilg-dialog-name').focus();
    }

    function saveDialog() {
        var dialog = document.getElementById('hilg-folder-dialog');
        var name = document.getElementById('hilg-dialog-name').value.trim();
        var password = document.getElementById('hilg-dialog-password').value;
        var selected = document.querySelector('input[name="hilg_access_mode"]:checked');
        var mode = selected ? selected.value : 'private';

        if (!name) {
            document.getElementById('hilg-dialog-name').focus();

            return;
        }

        var payload = { name: name, access_mode: mode };

        if (password) {
            payload.password = password;
        }

        var request = state.editing
            ? api('/manage/folders/' + state.editing, { method: 'PATCH', body: payload })
            : api('/manage/folders', {
                method: 'POST',
                body: Object.assign({ parent_id: state.folderId || null }, payload)
            });

        request
            .then(function (result) {
                var folderId = state.editing || result.id;

                if (state.editing) {
                    return api('/manage/folders/' + folderId + '/roles', {
                        method: 'POST',
                        body: { grants: collectMatrix() }
                    });
                }

                return result;
            })
            .then(function () {
                dialog.close();
                state.editing = null;
                loadTree();

                if (state.folderId) {
                    selectFolder(state.folderId);
                }
            })
            .catch(function (error) {
                status(error && error.payload ? error.payload.message : text.failed);
            });
    }

    function bindDialog() {
        var dialog = document.getElementById('hilg-folder-dialog');

        if (!dialog) {
            return;
        }

        document.getElementById('hilg-dialog-save').addEventListener('click', saveDialog);

        document.getElementById('hilg-dialog-cancel').addEventListener('click', function () {
            state.editing = null;
            dialog.close();
        });

        document.querySelectorAll('input[name="hilg_access_mode"]').forEach(function (radio) {
            radio.addEventListener('change', togglePasswordField);
        });

        var newFolder = document.getElementById('hilg-new-folder');

        if (newFolder) {
            newFolder.addEventListener('click', function () {
                openDialog(0);
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadTree();
        selectFolder(0);
        bindDropzone();
        bindDialog();
    });
}());
