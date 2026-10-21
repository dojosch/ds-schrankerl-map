(function () {
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

    function openPopup(trigger) {
        const dialog = document.getElementById(trigger.getAttribute('aria-controls'));
        if (!dialog || typeof dialog.showModal !== 'function') return;

        dialog.showModal();

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

        // Modal backdrop clicks target the dialog itself, but land outside its box.
        // Keyboard "clicks" report 0/0 coordinates and only ever target a button, so they never get here.
        if (e.target === current.dialog) {
            const rect = current.dialog.getBoundingClientRect();
            if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
                current.dialog.close();
            }
        }
    });

    // Scroll events don't bubble, so listen in the capture phase.
    document.addEventListener('scroll', function (e) {
        if (e.target instanceof Element && e.target.matches('[data-ds-schrankerl-map-track]')) {
            updateArrows(e.target.closest('[data-ds-schrankerl-map-slider]'));
        }
    }, true);
})();
