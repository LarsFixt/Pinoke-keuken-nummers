/**
 * Thin wrapper around the self-hosted Umami tracker (lf.js).
 *
 * - Queues events until the tracker has loaded, so pages can track right away.
 * - Identifies every visitor with an anonymous id so Umami can link sessions into one journey.
 * - Cleans URLs before they are sent: the Livewire `order` param is removed, so /track stays one page.
 */

const VISITOR_KEY = 'lf.visitor';
const ENTRY_KEY = 'lf.entry';
const IGNORED_QUERY_PARAMS = ['order'];

const queue = [];
let identified = false;
let lastPageviewUrl = null;

const storage = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {}
    },
    remove(key) {
        try {
            window.localStorage.removeItem(key);
        } catch (e) {}
    },
    session(key, value = undefined) {
        try {
            if (value === undefined) {
                return window.sessionStorage.getItem(key);
            }

            window.sessionStorage.setItem(key, value);
        } catch (e) {
            return null;
        }
    },
};

function detectPlatform() {
    const userAgent = window.navigator.userAgent || '';
    const isIos = /iPad|iPhone|iPod/.test(userAgent)
        || (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);

    if (isIos) {
        return 'ios';
    }

    if (/Android/i.test(userAgent)) {
        return 'android';
    }

    return 'desktop';
}

function detectDisplayMode() {
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

    return isStandalone ? 'standalone' : 'browser';
}

/**
 * The traffic source of this browser session, fixed on the first page that was opened.
 */
function detectEntry() {
    const known = storage.session(ENTRY_KEY);

    if (known) {
        return known;
    }

    const entry = new URLSearchParams(window.location.search).get('utm_source') || 'direct';
    storage.session(ENTRY_KEY, entry);

    return entry;
}

function visitorId() {
    let id = storage.get(VISITOR_KEY);

    if (!id) {
        id = window.crypto?.randomUUID?.() || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
        storage.set(VISITOR_KEY, id);
    }

    return id;
}

function cleanUrl(value) {
    if (!value) {
        return value;
    }

    try {
        const isRelative = value.startsWith('/');
        const url = new URL(value, window.location.origin);

        IGNORED_QUERY_PARAMS.forEach((param) => url.searchParams.delete(param));

        return isRelative ? `${url.pathname}${url.search}${url.hash}` : url.toString();
    } catch (e) {
        return value;
    }
}

function flush() {
    if (!window.umami) {
        return false;
    }

    if (!identified) {
        identified = true;
        window.umami.identify(visitorId(), analytics.sessionProperties());
    }

    while (queue.length) {
        const [name, data] = queue.shift();
        window.umami.track(name, data);
    }

    return true;
}

const analytics = {
    platform: detectPlatform(),

    sessionProperties() {
        return {
            entry: detectEntry(),
            platform: this.platform,
            display_mode: detectDisplayMode(),
        };
    },

    track(name, data = undefined) {
        queue.push([name, data]);
        flush();
    },

    /**
     * Remember a moment (e.g. when tracking an order started) so a later event can report a duration.
     */
    mark(key) {
        storage.set(`lf.mark.${key}`, String(Date.now()));
    },

    minutesSince(key) {
        const startedAt = Number(storage.get(`lf.mark.${key}`));

        return startedAt ? Math.round((Date.now() - startedAt) / 60000) : undefined;
    },

    forget(key) {
        storage.remove(`lf.mark.${key}`);
    },
};

/**
 * Called by lf.js (data-before-send) for every payload; returning a falsy value cancels the send.
 */
window.umamiBeforeSend = (type, payload) => {
    payload.url = cleanUrl(payload.url);
    payload.referrer = cleanUrl(payload.referrer);

    const isPageview = type === 'event' && !payload.name;

    if (isPageview) {
        if (payload.url === lastPageviewUrl) {
            return null;
        }

        lastPageviewUrl = payload.url;
    }

    return payload;
};

window.analytics = analytics;

detectEntry();

if (!flush()) {
    const waitForTracker = window.setInterval(() => {
        if (flush()) {
            window.clearInterval(waitForTracker);
        }
    }, 250);

    window.setTimeout(() => window.clearInterval(waitForTracker), 15000);
}

window.addEventListener('appinstalled', () => analytics.track('app-installed', { platform: analytics.platform }));

export default analytics;
