const config = () => window.HAJKIC || {};
const cache = new Map();

export function baseUrl() {
    return config().base || '/';
}

export function dataUrl(path) {
    const base = baseUrl();
    if (/^https?:\/\//i.test(path)) return path;
    return `${base.replace(/\/?$/, '/')}${String(path).replace(/^\//, '')}`;
}

export function trailPageUrl(slug) {
    return `${String(config().trailBase || '/staza/').replace(/\/?$/, '/')}${encodeURIComponent(slug)}`;
}

export async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
        signal: options.signal,
        headers: { Accept: 'application/json' },
        cache: options.cache || 'default',
    });
    if (!response.ok) {
        const error = new Error(`HTTP ${response.status} za ${url}`);
        error.status = response.status;
        throw error;
    }
    return response.json();
}

export function loadIndex(options = {}) {
    if (!cache.has('index')) {
        const promise = fetchJson(dataUrl('data/trails-index.json'), options).catch((error) => {
            cache.delete('index');
            throw error;
        });
        cache.set('index', promise);
    }
    return cache.get('index');
}

export function loadOverview(options = {}) {
    if (!cache.has('overview')) {
        const promise = fetchJson(dataUrl('data/overview.geojson'), options).catch((error) => {
            cache.delete('overview');
            throw error;
        });
        cache.set('overview', promise);
    }
    return cache.get('overview');
}

export function loadCountryBorder(options = {}) {
    if (!cache.has('country-border')) {
        const promise = fetchJson(dataUrl('data/geo/serbia.geojson'), options).catch((error) => {
            if (error.status === 404) return null;
            throw error;
        });
        cache.set('country-border', promise);
    }
    return cache.get('country-border');
}

export function loadPlaces(options = {}) {
    if (!cache.has('places')) {
        const promise = fetchJson(dataUrl('data/places.geojson'), options).catch((error) => {
            if (error.status === 404) {
                return { type: 'FeatureCollection', features: [] };
            }
            throw error;
        });
        cache.set('places', promise);
    }
    return cache.get('places');
}

export function loadTrail(slug, options = {}) {
    const key = `trail:${slug}`;
    if (cache.has(key)) return cache.get(key);

    const promise = (async () => {
        const trail = await fetchJson(dataUrl(`data/trails/${encodeURIComponent(slug)}.json`), options);
        let geojson = null;
        if (trail?.geometry?.geojson) {
            try {
                geojson = await fetchJson(dataUrl(trail.geometry.geojson), options);
            } catch (error) {
                if (error.name === 'AbortError') throw error;
                geojson = null;
            }
        }
        return { trail, geojson };
    })().catch((error) => {
        cache.delete(key);
        throw error;
    });

    cache.set(key, promise);
    return promise;
}

export function clearMemoryCache() {
    cache.clear();
}
