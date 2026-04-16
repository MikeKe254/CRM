const loaderState = {
    element: null,
    active: false,
    hideTimer: null,
    shownAt: 0,
};

function ensureLoader() {
    if (loaderState.element && loaderState.element.isConnected) {
        return loaderState.element;
    }

    if (!document.body) {
        return null;
    }

    const existingLoaders = Array.from(document.querySelectorAll('#turbo-loader'));

    if (existingLoaders.length > 0) {
        const [current, ...duplicates] = existingLoaders;
        duplicates.forEach((loader) => loader.remove());

        loaderState.element = current;
        return current;
    }

    const element = document.createElement('div');
    const orb = document.createElement('div');
    const ring = document.createElement('div');
    const core = document.createElement('div');

    element.id = 'turbo-loader';
    element.className = 'turbo-loader';
    element.setAttribute('role', 'status');
    element.setAttribute('aria-live', 'polite');
    element.setAttribute('aria-label', 'Loading');
    element.setAttribute('data-turbo-temporary', '');
    element.hidden = true;

    orb.className = 'turbo-loader__orb';
    ring.className = 'turbo-loader__ring';
    core.className = 'turbo-loader__core';

    orb.append(ring, core);
    element.appendChild(orb);
    document.body.appendChild(element);

    loaderState.element = element;
    return element;
}

function resetLoader(removeElement = false) {
    if (loaderState.hideTimer) {
        window.clearTimeout(loaderState.hideTimer);
        loaderState.hideTimer = null;
    }

    document.querySelectorAll('#turbo-loader').forEach((loader) => {
        loader.hidden = true;

        if (removeElement) {
            loader.remove();
        }
    });

    loaderState.active = false;
    loaderState.shownAt = 0;

    if (removeElement) {
        loaderState.element = null;
    }
}

function showLoader() {
    const element = ensureLoader();

    if (!element) {
        return;
    }

    if (loaderState.hideTimer) {
        window.clearTimeout(loaderState.hideTimer);
        loaderState.hideTimer = null;
    }

    loaderState.active = true;
    loaderState.shownAt = Date.now();
    element.hidden = false;
}

function finishLoader() {
    if (!loaderState.active || !loaderState.element) {
        return;
    }

    const elapsed = Date.now() - loaderState.shownAt;
    const delay = Math.max(0, 260 - elapsed);

    loaderState.hideTimer = window.setTimeout(() => {
        if (!loaderState.element) {
            return;
        }

        loaderState.element.hidden = true;
        loaderState.active = false;
        loaderState.shownAt = 0;
    }, delay);
}

function isTurboNavigableLink(link, event) {
    if (!link || !link.href) {
        return false;
    }

    const rawHref = link.getAttribute('href')?.trim() ?? '';

    if (event.defaultPrevented || event.button !== 0) {
        return false;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
    }

    if (link.target && link.target !== '_self') {
        return false;
    }

    if (link.hasAttribute('download') || link.getAttribute('data-turbo') === 'false') {
        return false;
    }

    if (!rawHref || rawHref === '#' || rawHref.startsWith('#') || rawHref.toLowerCase().startsWith('javascript:')) {
        return false;
    }

    const url = new URL(link.href, window.location.href);

    if (url.origin !== window.location.origin) {
        return false;
    }

    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) {
        return false;
    }

    return true;
}

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');

    if (isTurboNavigableLink(link, event)) {
        showLoader();
    }
}, true);

document.addEventListener('DOMContentLoaded', ensureLoader);
document.addEventListener('turbo:load', () => {
    ensureLoader();
    finishLoader();
});
window.addEventListener('pageshow', () => resetLoader(true));
window.addEventListener('pagehide', () => resetLoader(true));
document.addEventListener('popstate', () => resetLoader(true));
document.addEventListener('turbo:before-cache', () => resetLoader(true));
document.addEventListener('turbo:submit-start', showLoader);
document.addEventListener('turbo:before-fetch-request', showLoader);
document.addEventListener('turbo:before-render', showLoader);
document.addEventListener('turbo:render', finishLoader);
document.addEventListener('turbo:submit-end', finishLoader);
document.addEventListener('turbo:fetch-request-error', finishLoader);
