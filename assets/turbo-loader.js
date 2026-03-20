const loaderState = {
    element: null,
    message: null,
    frame: null,
    percent: null,
    value: 0,
    target: 0,
    active: false,
    timer: null,
    hideTimer: null,
    messageTimer: null,
    shownAt: 0,
    messageIndex: -1,
};

const loaderMessages = [
    'Hang tight, persuading the pixels.',
    'One moment, the hamsters are sprinting.',
    'Fetching the good stuff, quietly.',
    'Just a sec, polishing the page.',
    'Almost there, nudging Turbo along.',
    'Please hold, assembling tiny miracles.',
    'Working on it, with dramatic restraint.',
    'Give us a blink, we are nearly there.',
    'Loading gently, no need for panic.',
    'Small pause, big intentions.',
    'Tidying the page before it arrives.',
    'Making it look easy, one moment.',
    'A brief pause while the gears pretend.',
    'Turbo is stretching its legs.',
    'One moment, we are arranging the nice bits.',
    'Still loading, but in a dignified way.',
    'Please wait, the page is finding its shoes.',
    'Almost ready, smoothing out the corners.',
    'Just a tiny pause for something useful.',
    'We will be there shortly, promise.',
];

function clampProgress(value) {
    return Math.max(0, Math.min(100, value));
}

function buildBar(percent) {
    const safePercent = clampProgress(percent);
    const filledStars = Math.round(safePercent / 5);

    return `[${'*'.repeat(filledStars)}${' '.repeat(20 - filledStars)}]`;
}

function renderLoader() {
    if (!loaderState.element) {
        return;
    }

    const percent = clampProgress(loaderState.value);

    loaderState.frame.textContent = buildBar(percent);
    loaderState.percent.textContent = `${percent}%`;
}

function pickNextMessage() {
    if (loaderMessages.length === 0) {
        return '';
    }

    let nextIndex = Math.floor(Math.random() * loaderMessages.length);

    if (loaderMessages.length > 1 && nextIndex === loaderState.messageIndex) {
        nextIndex = (nextIndex + 1) % loaderMessages.length;
    }

    loaderState.messageIndex = nextIndex;

    return loaderMessages[nextIndex];
}

function updateLoaderMessage() {
    if (!loaderState.message) {
        return;
    }

    loaderState.message.textContent = pickNextMessage();
}

function stopMessageLoop() {
    if (loaderState.messageTimer) {
        window.clearInterval(loaderState.messageTimer);
        loaderState.messageTimer = null;
    }
}

function startMessageLoop() {
    stopMessageLoop();

    loaderState.messageTimer = window.setInterval(() => {
        if (!loaderState.active) {
            return;
        }

        updateLoaderMessage();
    }, 2200);
}

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
        const message = current.querySelector('.turbo-loader__message');
        const frame = current.querySelector('.turbo-loader__frame');
        const percent = current.querySelector('.turbo-loader__percent');

        duplicates.forEach((loader) => loader.remove());

        if (message && frame && percent) {
            loaderState.element = current;
            loaderState.message = message;
            loaderState.frame = frame;
            loaderState.percent = percent;

            updateLoaderMessage();
            renderLoader();

            return current;
        }

        current.remove();
    }

    const element = document.createElement('div');
    const content = document.createElement('div');
    const message = document.createElement('span');
    const progress = document.createElement('div');
    const frame = document.createElement('span');
    const percent = document.createElement('span');

    element.id = 'turbo-loader';
    element.className = 'turbo-loader';
    element.setAttribute('role', 'status');
    element.setAttribute('aria-live', 'polite');
    element.setAttribute('aria-atomic', 'true');
    element.setAttribute('data-turbo-temporary', '');
    element.hidden = true;

    content.className = 'turbo-loader__content';
    message.className = 'turbo-loader__message';
    progress.className = 'turbo-loader__progress';
    frame.className = 'turbo-loader__frame';
    percent.className = 'turbo-loader__percent';

    content.append(message, progress);
    progress.append(frame, document.createTextNode(' '), percent);
    element.append(content);
    document.body.appendChild(element);

    loaderState.element = element;
    loaderState.message = message;
    loaderState.frame = frame;
    loaderState.percent = percent;

    updateLoaderMessage();
    renderLoader();

    return element;
}

function stopProgressLoop() {
    if (loaderState.timer) {
        window.clearInterval(loaderState.timer);
        loaderState.timer = null;
    }
}

function resetLoader(removeElement = false) {
    stopProgressLoop();
    stopMessageLoop();

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

    loaderState.element = removeElement ? null : loaderState.element;
    loaderState.message = removeElement ? null : loaderState.message;
    loaderState.frame = removeElement ? null : loaderState.frame;
    loaderState.percent = removeElement ? null : loaderState.percent;
    loaderState.active = false;
    loaderState.value = 0;
    loaderState.target = 0;
    loaderState.shownAt = 0;

    if (!removeElement) {
        updateLoaderMessage();
        renderLoader();
    }
}

function startProgressLoop() {
    if (loaderState.timer) {
        return;
    }

    loaderState.timer = window.setInterval(() => {
        if (loaderState.value >= loaderState.target) {
            return;
        }

        loaderState.value = Math.min(loaderState.target, loaderState.value + 5);
        renderLoader();
    }, 120);
}

function showLoader(startAt = 10) {
    const element = ensureLoader();

    if (!element) {
        return;
    }

    if (loaderState.hideTimer) {
        window.clearTimeout(loaderState.hideTimer);
        loaderState.hideTimer = null;
    }

    loaderState.active = true;
    loaderState.value = Math.max(loaderState.value, clampProgress(startAt));
    loaderState.target = Math.max(loaderState.target, loaderState.value);
    loaderState.shownAt = Date.now();
    element.hidden = false;

    updateLoaderMessage();
    renderLoader();
    startProgressLoop();
    startMessageLoop();
}

function advanceLoader(nextTarget) {
    if (!loaderState.active) {
        return;
    }

    loaderState.target = Math.max(loaderState.target, clampProgress(nextTarget));
    startProgressLoop();
}

function finishLoader() {
    if (!loaderState.active) {
        return;
    }

    loaderState.target = 100;
    loaderState.value = 100;
    renderLoader();
    stopProgressLoop();
    stopMessageLoop();

    const elapsed = Date.now() - loaderState.shownAt;
    const delay = Math.max(0, 320 - elapsed);

    loaderState.hideTimer = window.setTimeout(() => {
        if (!loaderState.element) {
            return;
        }

        loaderState.element.hidden = true;
        loaderState.active = false;
        loaderState.value = 0;
        loaderState.target = 0;
        loaderState.shownAt = 0;
        updateLoaderMessage();
        renderLoader();
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
        showLoader(10);
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
document.addEventListener('turbo:submit-start', () => showLoader(15));
document.addEventListener('turbo:before-fetch-request', () => advanceLoader(35));
document.addEventListener('turbo:before-render', () => advanceLoader(85));
document.addEventListener('turbo:render', finishLoader);
document.addEventListener('turbo:submit-end', finishLoader);
document.addEventListener('turbo:fetch-request-error', finishLoader);
