(function () {
    // Matches the theme's Tailwind md breakpoint: below it the popup opens as a modal.
    const mobileQuery = window.matchMedia('(max-width: 767px)');

    // The popup that is currently open, and the marker that opened it.
    let current = null;

    function updateArrows(slider) {
        const track = slider.querySelector('[data-ds-schrankerl-map-track]');
        const prev = slider.querySelector('[data-ds-schrankerl-map-prev]');
        const next = slider.querySelector('[data-ds-schrankerl-map-next]');
        if (!track || !prev || !next) return;

        // 1px tolerance for subpixel scroll positions.
        prev.disabled = track.scrollLeft <= 1;
        next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    // Beside the marker, preferably on its right, vertically centered on it.
    // Kept within the window and, vertically, within the map as far as it fits.
    function positionPopup(dialog, trigger) {
        const gap = 16;
        const edge = 16;
        const viewportWidth = document.documentElement.clientWidth;
        const map = trigger.closest('.ds-schrankerl-map').getBoundingClientRect();
        const marker = trigger.getBoundingClientRect();
        const width = dialog.offsetWidth;
        const height = dialog.offsetHeight;

        let x;
        if (marker.right + gap + width <= viewportWidth - edge) {
            x = marker.right + gap;
        } else if (marker.left - gap - width >= edge) {
            x = marker.left - gap - width;
        } else {
            x = clamp(marker.left + marker.width / 2 - width / 2, edge, viewportWidth - edge - width);
        }

        const y = clamp(
            marker.top + marker.height / 2 - height / 2,
            Math.max(map.top, edge),
            Math.min(map.bottom, window.innerHeight - edge) - height
        );

        dialog.style.left = (x - map.left) + 'px';
        dialog.style.top = (y - map.top) + 'px';
    }

    function openPopup(trigger) {
        const dialog = document.getElementById(trigger.getAttribute('aria-controls'));
        if (!dialog || typeof dialog.showModal !== 'function') return;

        // A second click on the same marker closes its popup again.
        const isOpen = dialog.open;
        if (current) current.dialog.close();
        if (isOpen) return;

        if (mobileQuery.matches) {
            dialog.showModal();
        } else {
            // Shown first so it can be measured; it moves into place before the next paint.
            dialog.show();
            positionPopup(dialog, trigger);
        }

        current = { dialog: dialog, trigger: trigger };
        trigger.setAttribute('aria-expanded', 'true');
        dialog.addEventListener('close', function () {
            trigger.setAttribute('aria-expanded', 'false');
            if (current && current.dialog === dialog) current = null;
        }, { once: true });

        dialog.querySelectorAll('[data-ds-schrankerl-map-slider]').forEach(updateArrows);
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-ds-schrankerl-map-popup]');
        if (trigger) {
            openPopup(trigger);
            return;
        }

        const closeBtn = e.target.closest('[data-ds-schrankerl-map-close]');
        if (closeBtn) {
            closeBtn.closest('dialog').close();
            return;
        }

        const arrow = e.target.closest('[data-ds-schrankerl-map-prev], [data-ds-schrankerl-map-next]');
        if (arrow) {
            const track = arrow.closest('[data-ds-schrankerl-map-slider]').querySelector('[data-ds-schrankerl-map-track]');
            const direction = arrow.hasAttribute('data-ds-schrankerl-map-prev') ? -1 : 1;
            track.scrollBy({ left: direction * track.clientWidth });
            return;
        }

        if (!current) return;

        // The floating popup closes on any click outside of it.
        if (!current.dialog.contains(e.target)) {
            current.dialog.close();
            return;
        }

        // Modal backdrop clicks target the dialog itself, but land outside its box.
        // Keyboard "clicks" report 0/0 coordinates and only ever target a button, so they never get here.
        if (e.target === current.dialog) {
            const rect = current.dialog.getBoundingClientRect();
            if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
                current.dialog.close();
            }
        }
    });

    // Modals close on Escape natively, the floating popup doesn't.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !current || current.dialog.matches(':modal')) return;

        const trigger = current.trigger;
        current.dialog.close();
        trigger.focus();
    });

    window.addEventListener('resize', function () {
        if (current && !current.dialog.matches(':modal')) positionPopup(current.dialog, current.trigger);
    });

    // Scroll events don't bubble, so listen in the capture phase.
    document.addEventListener('scroll', function (e) {
        if (e.target instanceof Element && e.target.matches('[data-ds-schrankerl-map-track]')) {
            updateArrows(e.target.closest('[data-ds-schrankerl-map-slider]'));
        }
    }, true);
})();
