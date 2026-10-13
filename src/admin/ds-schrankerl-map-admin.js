document.addEventListener('DOMContentLoaded', function () {
    const dataInput = document.getElementById('ds-schrankerl-map-markers');
    if (!dataInput || typeof wp === 'undefined' || !wp.media) return; // Not on the Schrankerl Map screen

    const settings = window.dsSchrankerlMap || {};
    const i18n = settings.i18n || {};
    const defaultWidth = parseInt(settings.defaultWidth, 10) || 80;
    const maxWidth = parseInt(settings.maxWidth, 10) || 2000;

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

    let activeId = null;

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
                url: marker.url || '',
                width: sanitizeWidth(marker.width)
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
                node.style.width = sanitizeWidth(marker.width) + 'px';
                node.appendChild(el('img', '', { src: marker.logo_url, alt: '', draggable: 'false' }));
            } else {
                node.textContent = String(index + 1);
            }

            canvas.appendChild(node);
        });
    }

    // Sidebar list
    function renderList() {
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
                logoRow.appendChild(el('img', 'ds-schrankerl-map-admin__logo-preview', { src: marker.logo_url, alt: '' }));
            } else {
                const notice = el('p', 'description');
                notice.textContent = i18n.noLogo || 'No logo selected.';
                logoRow.appendChild(notice);
            }
            const logoBtn = el('button', 'button', { type: 'button', 'data-action': 'logo' });
            logoBtn.textContent = marker.logo_url ? (i18n.changeLogo || 'Change Logo') : (i18n.selectLogo || 'Select Logo');
            logoRow.appendChild(logoBtn);

            const urlId = 'ds-schrankerl-map-url-' + marker.id;
            const urlLabel = el('label', '', { for: urlId });
            urlLabel.textContent = i18n.url || 'Link URL (optional)';
            const urlInput = el('input', 'widefat', { type: 'text', inputmode: 'url', id: urlId, 'data-field': 'url', placeholder: 'https://' });
            urlInput.value = marker.url || '';

            const widthId = 'ds-schrankerl-map-width-' + marker.id;
            const widthLabel = el('label', '', { for: widthId });
            widthLabel.textContent = i18n.width || 'Width (px)';
            const widthInput = el('input', 'small-text', { type: 'number', id: widthId, 'data-field': 'width', min: 1, max: maxWidth, step: 1 });
            widthInput.value = sanitizeWidth(marker.width);

            const fields = el('div', 'ds-schrankerl-map-admin__fields');
            fields.append(urlLabel, urlInput, widthLabel, widthInput);

            li.append(header, logoRow, fields);
            markerList.appendChild(li);
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

    // Place a new marker by clicking on the image
    canvas.addEventListener('click', function (e) {
        if (e.button !== 0 || e.target !== baseImage) return;

        const point = pointFromEvent(e);
        const marker = { id: generateId(), x: point.x, y: point.y, logo_id: 0, logo_url: '', url: '', width: defaultWidth };
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

        const action = e.target.getAttribute('data-action');
        if (action === 'delete') {
            if (window.confirm(i18n.confirmDelete || 'Delete this marker?')) {
                markers = markers.filter(function (m) { return m !== marker; });
                if (activeId === marker.id) activeId = null;
                render();
            }
        } else if (action === 'logo') {
            selectLogo(marker);
        }
    });

    markerList.addEventListener('input', function (e) {
        const field = e.target.getAttribute('data-field');
        const item = e.target.closest('.ds-schrankerl-map-admin__item');
        const marker = item && findMarker(item.getAttribute('data-id'));
        if (!field || !marker) return;

        marker[field] = field === 'width' ? sanitizeWidth(e.target.value) : e.target.value.trim();

        if (field === 'width') {
            const node = canvas.querySelector('.ds-schrankerl-map-admin__marker[data-id="' + marker.id + '"]');
            if (node && node.classList.contains('has-logo')) node.style.width = marker.width + 'px';
        }
        updateInput();
    });

    // Normalize the width field once editing is done (e.g. empty → default)
    markerList.addEventListener('change', function (e) {
        if (e.target.getAttribute('data-field') === 'width') {
            e.target.value = sanitizeWidth(e.target.value);
        }
    });

    // Media frames
    function openMediaFrame(title, onSelect) {
        const frame = wp.media({
            title: title,
            button: { text: i18n.useImage || 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first();
            if (attachment) onSelect(attachment.toJSON());
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
