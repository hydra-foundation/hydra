/* The admin drawer.
 *
 * On a narrow screen the sidebar is off-canvas and the top bar's button opens
 * it. Open state lives on <html> so the scrim, the drawer and the page's scroll
 * lock can all key off one attribute rather than staying in sync with each
 * other. Everything below is a no-op on a wide screen, where the media query
 * leaves the sidebar a rail and the button undisplayed.
 */
(function () {
    'use strict';

    const root = document.documentElement;

    function toggle() {
        return document.querySelector('.admin-toggle');
    }

    function isOpen() {
        return root.dataset.nav === 'open';
    }

    function open() {
        root.dataset.nav = 'open';
        toggle()?.setAttribute('aria-expanded', 'true');
        document.querySelector('#admin-sidebar .nav-link')?.focus();
    }

    function close(restoreFocus) {
        if (!isOpen()) {
            return;
        }

        delete root.dataset.nav;
        toggle()?.setAttribute('aria-expanded', 'false');

        // Only when the drawer is dismissed deliberately: after a navigation the
        // reader is looking at the frame, and pulling focus back to the button
        // would undo the move they just made.
        if (restoreFocus) {
            toggle()?.focus();
        }
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('.admin-toggle')) {
            isOpen() ? close(true) : open();

            return;
        }

        if (event.target.closest('.admin-scrim')) {
            close(true);

            return;
        }

        // A link inside the drawer has done its job by the time it is followed.
        if (isOpen() && event.target.closest('#admin-sidebar a')) {
            close(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            close(true);
        }
    });

    /* The palette is selected by an attribute on <html>, which no frame swap can
       reach. A screen that changes it says so in the fragment it returns, and
       the page repaints without a reload. There is no htmx response header to
       carry this in 4.x, HX-Trigger and HX-Refresh being gone. */
    function applyTheme() {
        const declared = document.querySelector('#admin-theme')?.dataset.theme;

        if (declared) {
            root.dataset.theme = declared;
        }
    }

    /* htmx 4 namespaces its events with colons; the htmx 3 spelling of this one
       silently never fires. A frame swap means the drawer's link has landed. */
    document.addEventListener('htmx:after:swap', function () {
        close(false);
        applyTheme();
    });

    /* A card whose first fetch fails says so and offers to ask again. Only a
       card still holding its placeholder has the notice to show (see
       widget-failed.php); one with data keeps it through a failed poll. htmx
       swaps an error answer too, but the error renderer sends it out of band,
       so the card is left as it was for this to finish. */
    const CARD = '.admin-widget, .admin-summary-card';

    function failed(event) {
        const card = event.target.closest?.(CARD);
        const notice = card?.querySelector('.admin-widget-failed');

        if (!notice) {
            return;
        }

        card.classList.add('is-failed');
        card.setAttribute('aria-busy', 'false');
        notice.hidden = false;
    }

    document.addEventListener('htmx:response:error', failed);
    document.addEventListener('htmx:error', failed);

    document.addEventListener('htmx:before:request', function (event) {
        const card = event.target.closest?.(CARD);

        if (!card?.classList.contains('is-failed')) {
            return;
        }

        card.classList.remove('is-failed');
        card.setAttribute('aria-busy', 'true');
        card.querySelector('.admin-widget-failed').hidden = true;
    });

    /* Dragging the viewport wide while the drawer is open would otherwise leave
       the scroll lock on a page that no longer has a drawer to blame. */
    window.matchMedia('(min-width: 56.001rem)').addEventListener('change', function (event) {
        if (event.matches) {
            close(false);
        }
    });
})();
