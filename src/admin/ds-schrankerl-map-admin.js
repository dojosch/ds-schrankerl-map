document.addEventListener('DOMContentLoaded', function () {
    const dataInput = document.getElementById('ds-schrankerl-map-markers');
    if (!dataInput || typeof wp === 'undefined' || !wp.media) return; // Not on the Schrankerl Map screen

    const settings = window.dsSchrankerlMap || {};
    const i18n = settings.i18n || {};
    const defaultWidth = parseInt(settings.defaultWidth, 10) || 40;
    const maxWidth = parseInt(settings.maxWidth, 10) || 2000;
    const types = ['none', 'link', 'popup'];

    // Block editor packages take over wp.editor, the classic API then lives in wp.oldEditor.
    const classicEditor = wp.oldEditor || wp.editor;

    const imageIdInput = document.getElementById('ds-schrankerl-map-image-id');
    const selectImageBtn = document.getElementById('ds-schrankerl-map-select-image');
    const removeImageBtn = document.getElementById('ds-schrankerl-map-remove-image');
    const editorArea = document.getElementById('ds-schrankerl-map-editor-area');
    const canvas = document.getElementById('ds-schrankerl-map-canvas');
    const baseImage = document.getElementById('ds-schrankerl-map-base-image');
    const markerList = document.getElementById('ds-schrankerl-map-list');
    const emptyNotice = document.getElementById('ds-schrankerl-map-empty');

    let markers = [];
    try {
        markers = JSON.parse(dataInput.value);
        if (!Array.isArray(markers)) markers = [];
    } catch (e) {
        markers = [];
    }
    markers.forEach(function (marker) {
        if (!Array.isArray(marker.popup_images)) marker.popup_images = [];
    });

    let activeId = null;

    // Marker IDs with a live TinyMCE instance
    let editors = [];

    function generateId() {
        return Math.random().toString(36).slice(2, 11);
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function sanitizeWidth(value) {
        const width = parseInt(value, 10);
        return width > 0 ? Math.min(width, maxWidth) : defaultWidth;
    }

    // 0 means "same as width".
    function sanitizeMobileWidth(value) {
        const width = parseInt(value, 10);
        return width > 0 ? Math.min(width, maxWidth) : 0;
    }

    function sanitizeFit(value) {
        return value === 'contain' ? 'contain' : 'cover';
    }

    function sanitizeType(value) {
        return types.indexOf(value) !== -1 ? value : 'none';
    }

    function el(tag, className, attrs) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        Object.keys(attrs || {}).forEach(function (key) {
            node.setAttribute(key, attrs[key]);
        });
        return node;
    }

    function updateInput() {
        dataInput.value = JSON.stringify(markers.map(function (marker) {
            return {
                id: marker.id,
                x: marker.x,
                y: marker.y,
                logo_id: marker.logo_id || 0,
                logo_url: marker.logo_url || '',
                type: sanitizeType(marker.type),
                url: marker.url || '',
                width: sanitizeWidth(marker.width),
                width_mobile: sanitizeMobileWidth(marker.width_mobile),
                fit: sanitizeFit(marker.fit),
                popup_images: marker.popup_images.map(function (image) {
                    return { id: image.id, url: image.url || '' };
                }),
                popup_title: marker.popup_title || '',
                popup_text: marker.popup_text || '',
                popup_button_text: marker.popup_button_text || '',
                popup_button_url: marker.popup_button_url || '',
                popup_new_tab: marker.popup_new_tab !== false
            };
        }));
    }

    function findMarker(id) {
        return markers.find(function (marker) { return marker.id === id; });
    }

    function pointFromEvent(e) {
        const rect = baseImage.getBoundingClientRect();
        return {
            x: Math.round(clamp(((e.clientX - rect.left) / rect.width) * 100, 0, 100) * 1000) / 1000,
            y: Math.round(clamp(((e.clientY - rect.top) / rect.height) * 100, 0, 100) * 1000) / 1000
        };
    }

    // Canvas markers
    function renderCanvas() {
        canvas.querySelectorAll('.ds-schrankerl-map-admin__marker').forEach(function (node) {
            node.remove();
        });

        markers.forEach(function (marker, index) {
            const node = el('div', 'ds-schrankerl-map-admin__marker', { 'data-id': marker.id, title: (i18n.marker || 'Marker %d').replace('%d', index + 1) });
            node.classList.toggle('is-active', marker.id === activeId);
            node.style.left = marker.x + '%';
            node.style.top = marker.y + '%';

            if (marker.logo_url) {
                node.classList.add('has-logo');
                node.classList.toggle('is-contain', sanitizeFit(marker.fit) === 'contain');
                node.style.width = sanitizeWidth(marker.width) + 'px';
                node.appendChild(el('img', '', { src: marker.logo_url, alt: '', draggable: 'false' }));
            } else {
                node.textContent = String(index + 1);
            }

            canvas.appendChild(node);
        });
    }

    // True/false switch, modelled on the ACF true_false UI.
    function switchControl(id, field, checked) {
        const wrapper = el('label', 'ds-schrankerl-map-admin__switch', { for: id });
        const input = el('input', '', { type: 'checkbox', role: 'switch', id: id, 'data-field': field });
        input.checked = checked;
        const track = el('span', 'ds-schrankerl-map-admin__switch-track', { 'aria-hidden': 'true' });
        const on = el('span', 'ds-schrankerl-map-admin__switch-on');
        on.textContent = i18n.yes || 'Yes';
        const off = el('span', 'ds-schrankerl-map-admin__switch-off');
        off.textContent = i18n.no || 'No';
        track.append(on, off);
        wrapper.append(input, track);
        return wrapper;
    }

    function labelFor(id, text, className) {
        const label = el('label', className || '', { for: id });
        label.textContent = text;
        return label;
    }

    function textInput(id, field, value, attrs) {
        const input = el('input', 'widefat', Object.assign({ type: 'text', id: id, 'data-field': field }, attrs || {}));
        input.value = value || '';
        return input;
    }

    // Only the fields of the selected type are shown.
    function applyType(item, marker) {
        const type = sanitizeType(marker.type);
        item.querySelectorAll('[data-show-for]').forEach(function (node) {
            node.hidden = node.getAttribute('data-show-for') !== type;
        });
        if (type === 'popup') initEditor(marker);
    }

    function renderGallery(gallery, marker) {
        gallery.innerHTML = '';
        gallery.hidden = marker.popup_images.length === 0;

        marker.popup_images.forEach(function (image, index) {
            const li = el('li', 'ds-schrankerl-map-admin__gallery-item', { 'data-index': index });
            li.appendChild(el('img', '', { src: image.url, alt: '' }));
            const removeBtn = el('button', 'ds-schrankerl-map-admin__gallery-remove', { type: 'button', 'data-action': 'remove-image', 'aria-label': i18n.removeImage || 'Remove image' });
            removeBtn.textContent = '×';
            li.appendChild(removeBtn);
            gallery.appendChild(li);
        });
    }

    function renderPopupFields(marker) {
        const popup = el('div', 'ds-schrankerl-map-admin__popup', { 'data-show-for': 'popup' });

        const heading = el('strong', 'is-wide');
        heading.textContent = i18n.popup || 'Popup';
        const hint = el('p', 'description is-wide');
        hint.textContent = i18n.popupHint || '';

        const imagesLabel = el('span', 'is-wide');
        imagesLabel.textContent = i18n.popupImages || 'Images';
        const gallery = el('ul', 'ds-schrankerl-map-admin__gallery is-wide');
        renderGallery(gallery, marker);
        window.jQuery(gallery).sortable({
            items: '> li',
            placeholder: 'ds-schrankerl-map-admin__gallery-placeholder',
            update: function () {
                marker.popup_images = Array.prototype.map.call(gallery.children, function (li) {
                    return marker.popup_images[parseInt(li.getAttribute('data-index'), 10)];
                });
                renderGallery(gallery, marker);
                updateInput();
            }
        });
        const imagesBtn = el('button', 'button is-wide', { type: 'button', 'data-action': 'popup-images' });
        imagesBtn.textContent = i18n.addImages || 'Add Images';

        const titleId = 'ds-schrankerl-map-popup-title-' + marker.id;
        const textId = 'ds-schrankerl-map-popup-text-' + marker.id;

        // TinyMCE wraps the textarea, the div keeps that wrapper in a full-width grid cell.
        const textWrap = el('div', 'is-wide');
        const textarea = el('textarea', 'widefat', { id: textId, rows: 5 });
        textarea.value = marker.popup_text || '';
        textWrap.appendChild(textarea);

        const buttonLabel = el('span', 'is-wide');
        buttonLabel.textContent = i18n.button || 'Button';
        const link = el('div', 'ds-schrankerl-map-admin__link is-wide');
        renderLink(link, marker);

        popup.append(
            heading, hint,
            imagesLabel, gallery, imagesBtn,
            labelFor(titleId, i18n.popupTitle || 'Title'), textInput(titleId, 'popup_title', marker.popup_title),
            labelFor(textId, i18n.popupText || 'Text', 'is-wide'), textWrap,
            buttonLabel, link
        );

        return popup;
    }

    // Button link, modelled on the ACF link field.
    function renderLink(container, marker) {
        container.innerHTML = '';

        if (!marker.popup_button_url) {
            const selectBtn = el('button', 'button', { type: 'button', 'data-action': 'link-edit' });
            selectBtn.textContent = i18n.selectLink || 'Select Link';
            container.appendChild(selectBtn);
            return;
        }

        const summary = el('div', 'ds-schrankerl-map-admin__link-summary');
        const title = el('strong');
        title.textContent = marker.popup_button_text || marker.popup_button_url;
        const url = el('a', '', { href: marker.popup_button_url, target: '_blank', rel: 'noopener' });
        url.textContent = marker.popup_button_url;
        summary.append(title, url);
        if (marker.popup_new_tab !== false) {
            const newTab = el('span', 'dashicons dashicons-external', { title: i18n.newTab || 'Opens in a new tab' });
            const newTabText = el('span', 'screen-reader-text');
            newTabText.textContent = i18n.newTab || 'Opens in a new tab';
            summary.append(newTab, newTabText);
        }

        const actions = el('div', 'ds-schrankerl-map-admin__link-actions');
        const editBtn = el('button', 'button-link', { type: 'button', 'data-action': 'link-edit' });
        editBtn.textContent = i18n.edit || 'Edit';
        const removeBtn = el('button', 'button-link button-link-delete', { type: 'button', 'data-action': 'link-remove' });
        removeBtn.textContent = i18n.remove || 'Remove';
        actions.append(editBtn, removeBtn);

        container.append(summary, actions);

        if (!marker.popup_button_text) {
            const notice = el('p', 'description');
            notice.textContent = i18n.noLinkText || 'No link text: the button is not shown.';
            container.appendChild(notice);
        }
    }

    // Edited in the core wpLink dialog. It only writes into a textarea, so hand it a temporary one.
    const linkTextareaId = 'ds-schrankerl-map-link-textarea';
    let linkEdit = null;

    function openLinkDialog(marker, container) {
        if (!window.wpLink) return;

        const textarea = el('textarea', '', { id: linkTextareaId, hidden: '' });
        document.body.appendChild(textarea);
        linkEdit = { marker: marker, container: container, textarea: textarea };
        window.wpLink.open(linkTextareaId);
    }

    // wplink-open/-close are jQuery events. Both fire for the TinyMCE link dialog too, hence the linkEdit guard.
    window.jQuery(document).on('wplink-open', function () {
        if (!linkEdit) return;

        const marker = linkEdit.marker;
        document.getElementById('wp-link-url').value = marker.popup_button_url || '';
        document.getElementById('wp-link-text').value = marker.popup_button_text || '';
        // New links default to a new tab, like regular marker links.
        document.getElementById('wp-link-target').checked = marker.popup_button_url ? marker.popup_new_tab !== false : true;
    });

    window.jQuery(document).on('wplink-close', function () {
        if (!linkEdit) return;

        const edit = linkEdit;
        linkEdit = null;

        // wpLink fills the textarea right before closing, but only when the dialog was submitted.
        if (edit.textarea.value) {
            const attrs = window.wpLink.getAttrs();
            edit.marker.popup_button_url = attrs.href;
            edit.marker.popup_button_text = document.getElementById('wp-link-text').value.trim();
            edit.marker.popup_new_tab = attrs.target === '_blank';
            renderLink(edit.container, edit.marker);
            updateInput();
        }

        edit.textarea.remove();
        const editBtn = edit.container.querySelector('[data-action="link-edit"]');
        if (editBtn) editBtn.focus();
    });

    // Sidebar list
    function renderList() {
        destroyEditors();
        markerList.innerHTML = '';
        emptyNotice.hidden = markers.length > 0;

        markers.forEach(function (marker, index) {
            const li = el('li', 'ds-schrankerl-map-admin__item', { 'data-id': marker.id });
            li.classList.toggle('is-active', marker.id === activeId);

            const header = el('div', 'ds-schrankerl-map-admin__item-header');
            const title = el('strong');
            title.textContent = (i18n.marker || 'Marker %d').replace('%d', index + 1);
            const deleteBtn = el('button', 'button-link button-link-delete', { type: 'button', 'data-action': 'delete' });
            deleteBtn.textContent = i18n.delete || 'Delete';
            header.append(title, deleteBtn);

            const logoRow = el('div', 'ds-schrankerl-map-admin__logo-row');
            if (marker.logo_url) {
                const preview = el('img', 'ds-schrankerl-map-admin__logo-preview', { src: marker.logo_url, alt: '' });
                preview.classList.toggle('is-contain', sanitizeFit(marker.fit) === 'contain');
                logoRow.appendChild(preview);
            } else {
                const notice = el('p', 'description');
                notice.textContent = i18n.noLogo || 'No logo selected.';
                logoRow.appendChild(notice);
            }
            const logoBtn = el('button', 'button', { type: 'button', 'data-action': 'logo' });
            logoBtn.textContent = marker.logo_url ? (i18n.changeLogo || 'Change Logo') : (i18n.selectLogo || 'Select Logo');
            logoRow.appendChild(logoBtn);

            const typeId = 'ds-schrankerl-map-type-' + marker.id;
            const typeSelect = el('select', '', { id: typeId, 'data-field': 'type' });
            [['none', i18n.typeNone || 'None'], ['link', i18n.typeLink || 'Link'], ['popup', i18n.typePopup || 'Popup']].forEach(function (option) {
                const node = el('option', '', { value: option[0] });
                node.textContent = option[1];
                typeSelect.appendChild(node);
            });
            typeSelect.value = sanitizeType(marker.type);

            const urlId = 'ds-schrankerl-map-url-' + marker.id;
            const urlLabel = labelFor(urlId, i18n.url || 'Link URL');
            urlLabel.setAttribute('data-show-for', 'link');
            const urlInput = textInput(urlId, 'url', marker.url, { inputmode: 'url', placeholder: 'https://', 'data-show-for': 'link' });

            const widthId = 'ds-schrankerl-map-width-' + marker.id;
            const widthInput = el('input', 'small-text', { type: 'number', id: widthId, 'data-field': 'width', min: 1, max: maxWidth, step: 1 });
            widthInput.value = sanitizeWidth(marker.width);

            const mobileId = 'ds-schrankerl-map-width-mobile-' + marker.id;
            const mobileInput = el('input', 'small-text', { type: 'number', id: mobileId, 'data-field': 'width_mobile', min: 1, max: maxWidth, step: 1, placeholder: i18n.sameAsWidth || 'Same as width' });
            mobileInput.value = sanitizeMobileWidth(marker.width_mobile) || '';

            const fitId = 'ds-schrankerl-map-fit-' + marker.id;

            const fields = el('div', 'ds-schrankerl-map-admin__fields');
            fields.append(
                labelFor(typeId, i18n.type || 'Type'), typeSelect,
                urlLabel, urlInput,
                labelFor(widthId, i18n.width || 'Width (px)'), widthInput,
                labelFor(mobileId, i18n.widthMobile || 'Mobile width (px)'), mobileInput,
                labelFor(fitId, i18n.fitCover || 'Cover marker'), switchControl(fitId, 'fit', sanitizeFit(marker.fit) === 'cover')
            );

            li.append(header, logoRow, fields, renderPopupFields(marker));
            markerList.appendChild(li);
            applyType(li, marker);
        });
    }

    function render() {
        renderCanvas();
        renderList();
        updateInput();
    }

    function setActive(id) {
        activeId = id;
        canvas.querySelectorAll('.ds-schrankerl-map-admin__marker').forEach(function (node) {
            node.classList.toggle('is-active', node.getAttribute('data-id') === id);
        });
        markerList.querySelectorAll('.ds-schrankerl-map-admin__item').forEach(function (node) {
            node.classList.toggle('is-active', node.getAttribute('data-id') === id);
        });
    }

    // Popup text editors
    function editorId(markerId) {
        return 'ds-schrankerl-map-popup-text-' + markerId;
    }

    function syncEditor(markerId) {
        const editor = window.tinymce && window.tinymce.get(editorId(markerId));
        const marker = findMarker(markerId);
        if (editor && marker) marker.popup_text = editor.getContent();
    }

    // Initialized lazily: TinyMCE can't measure itself inside a hidden container.
    function initEditor(marker) {
        if (!classicEditor || !classicEditor.initialize || editors.indexOf(marker.id) !== -1) return;
        if (!document.getElementById(editorId(marker.id))) return;

        editors.push(marker.id);
        classicEditor.initialize(editorId(marker.id), {
            tinymce: {
                toolbar1: 'bold,italic,link,unlink',
                toolbar2: '',
                plugins: 'paste,tabfocus,wordpress,wplink,wpdialogs',
                paste_as_text: true,
                wpautop: false,
                menubar: false,
                height: 140,
                setup: function (editor) {
                    editor.on('input change undo redo', function () {
                        marker.popup_text = editor.getContent();
                        updateInput();
                    });
                }
            },
            quicktags: false,
            mediaButtons: false
        });
    }

    function destroyEditors() {
        editors.forEach(function (markerId) {
            syncEditor(markerId);
            classicEditor.remove(editorId(markerId));
        });
        editors = [];
    }

    // Catch edits that haven't fired a change event yet.
    if (dataInput.form) {
        dataInput.form.addEventListener('submit', function () {
            editors.forEach(syncEditor);
            updateInput();
        });
    }

    // Place a new marker by clicking on the image
    canvas.addEventListener('click', function (e) {
        if (e.button !== 0 || e.target !== baseImage) return;

        const point = pointFromEvent(e);
        const marker = {
            id: generateId(), x: point.x, y: point.y, logo_id: 0, logo_url: '', type: 'none', url: '', width: defaultWidth, width_mobile: 0, fit: 'cover',
            popup_images: [], popup_title: '', popup_text: '', popup_button_text: '', popup_button_url: '', popup_new_tab: true
        };
        markers.push(marker);
        activeId = marker.id;
        render();

        const item = markerList.querySelector('[data-id="' + marker.id + '"]');
        if (item) item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });

    // Drag existing markers to reposition them
    canvas.addEventListener('pointerdown', function (e) {
        const node = e.target.closest('.ds-schrankerl-map-admin__marker');
        if (!node || e.button !== 0) return;

        e.preventDefault();
        const marker = findMarker(node.getAttribute('data-id'));
        if (!marker) return;

        setActive(marker.id);
        node.setPointerCapture(e.pointerId);
        node.classList.add('is-dragging');

        function onMove(moveEvent) {
            const point = pointFromEvent(moveEvent);
            marker.x = point.x;
            marker.y = point.y;
            node.style.left = marker.x + '%';
            node.style.top = marker.y + '%';
        }

        function onUp() {
            node.classList.remove('is-dragging');
            node.removeEventListener('pointermove', onMove);
            node.removeEventListener('pointerup', onUp);
            node.removeEventListener('pointercancel', onUp);
            updateInput();
        }

        node.addEventListener('pointermove', onMove);
        node.addEventListener('pointerup', onUp);
        node.addEventListener('pointercancel', onUp);
    });

    // List actions
    markerList.addEventListener('click', function (e) {
        const item = e.target.closest('.ds-schrankerl-map-admin__item');
        if (!item) return;

        const marker = findMarker(item.getAttribute('data-id'));
        if (!marker) return;

        setActive(marker.id);

        const actionNode = e.target.closest('[data-action]');
        const action = actionNode && actionNode.getAttribute('data-action');
        if (action === 'delete') {
            if (window.confirm(i18n.confirmDelete || 'Delete this marker?')) {
                markers = markers.filter(function (m) { return m !== marker; });
                if (activeId === marker.id) activeId = null;
                render();
            }
        } else if (action === 'logo') {
            selectLogo(marker);
        } else if (action === 'popup-images') {
            selectPopupImages(marker, item.querySelector('.ds-schrankerl-map-admin__gallery'));
        } else if (action === 'link-edit') {
            openLinkDialog(marker, item.querySelector('.ds-schrankerl-map-admin__link'));
        } else if (action === 'link-remove') {
            marker.popup_button_url = '';
            marker.popup_button_text = '';
            marker.popup_new_tab = true;
            const link = item.querySelector('.ds-schrankerl-map-admin__link');
            renderLink(link, marker);
            link.querySelector('[data-action="link-edit"]').focus();
            updateInput();
        } else if (action === 'remove-image') {
            const index = parseInt(actionNode.closest('[data-index]').getAttribute('data-index'), 10);
            marker.popup_images.splice(index, 1);
            renderGallery(item.querySelector('.ds-schrankerl-map-admin__gallery'), marker);
            updateInput();
        }
    });

    markerList.addEventListener('input', function (e) {
        const field = e.target.getAttribute('data-field');
        const item = e.target.closest('.ds-schrankerl-map-admin__item');
        const marker = item && findMarker(item.getAttribute('data-id'));
        if (!field || !marker) return;

        if (field === 'fit') {
            marker.fit = e.target.checked ? 'cover' : 'contain';
        } else if (field === 'type') {
            marker.type = sanitizeType(e.target.value);
            applyType(item, marker);
        } else if (field === 'width_mobile') {
            marker.width_mobile = sanitizeMobileWidth(e.target.value);
        } else {
            marker[field] = field === 'width' ? sanitizeWidth(e.target.value) : e.target.value.trim();
        }

        if (field === 'width' || field === 'fit') {
            const node = canvas.querySelector('.ds-schrankerl-map-admin__marker[data-id="' + marker.id + '"]');
            if (node && node.classList.contains('has-logo')) {
                node.style.width = marker.width + 'px';
                node.classList.toggle('is-contain', marker.fit === 'contain');
            }
            const preview = item.querySelector('.ds-schrankerl-map-admin__logo-preview');
            if (preview) preview.classList.toggle('is-contain', marker.fit === 'contain');
        }
        updateInput();
    });

    // Normalize the width field once editing is done (e.g. empty → default)
    markerList.addEventListener('change', function (e) {
        const field = e.target.getAttribute('data-field');
        if (field === 'width') {
            e.target.value = sanitizeWidth(e.target.value);
        } else if (field === 'width_mobile') {
            e.target.value = sanitizeMobileWidth(e.target.value) || '';
        }
    });

    // Media frames
    function openMediaFrame(title, onSelect, multiple) {
        const frame = wp.media({
            title: title,
            button: { text: multiple ? (i18n.useImages || 'Use these images') : (i18n.useImage || 'Use this image') },
            library: { type: 'image' },
            multiple: multiple ? 'add' : false
        });

        frame.on('select', function () {
            const selection = frame.state().get('selection');
            if (multiple) {
                onSelect(selection.map(function (attachment) { return attachment.toJSON(); }));
            } else if (selection.first()) {
                onSelect(selection.first().toJSON());
            }
        });

        frame.open();
    }

    function attachmentUrl(attachment, size) {
        return attachment.sizes && attachment.sizes[size] ? attachment.sizes[size].url : attachment.url;
    }

    function selectLogo(marker) {
        openMediaFrame(i18n.logoFrame || 'Select Company Logo', function (attachment) {
            marker.logo_id = attachment.id;
            marker.logo_url = attachmentUrl(attachment, 'medium');
            render();
        });
    }

    function selectPopupImages(marker, gallery) {
        openMediaFrame(i18n.imagesFrame || 'Select Popup Images', function (attachments) {
            attachments.forEach(function (attachment) {
                const exists = marker.popup_images.some(function (image) { return image.id === attachment.id; });
                if (!exists) marker.popup_images.push({ id: attachment.id, url: attachmentUrl(attachment, 'thumbnail') });
            });
            renderGallery(gallery, marker);
            updateInput();
        }, true);
    }

    selectImageBtn.addEventListener('click', function () {
        openMediaFrame(i18n.imageFrame || 'Select Map Image', function (attachment) {
            imageIdInput.value = attachment.id;
            baseImage.src = attachment.url;
            editorArea.hidden = false;
            removeImageBtn.hidden = false;
        });
    });

    removeImageBtn.addEventListener('click', function () {
        imageIdInput.value = '';
        baseImage.removeAttribute('src');
        editorArea.hidden = true;
        removeImageBtn.hidden = true;
    });

    // Initial render
    render();
});
