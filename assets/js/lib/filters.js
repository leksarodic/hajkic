import { fold } from './format.js';

export const DISTANCE_BUCKETS = {
    '0-5': [0, 5],
    '5-10': [5, 10],
    '10-20': [10, 20],
    '20-30': [20, 30],
    '30+': [30, Infinity],
};

export const ELEVATION_BUCKETS = {
    '0-300': [0, 300],
    '300-700': [300, 700],
    '700-1200': [700, 1200],
    '1200+': [1200, Infinity],
};

export const DISTANCE_LABELS = {
    '0-5': 'do 5 km',
    '5-10': '5–10 km',
    '10-20': '10–20 km',
    '20-30': '20–30 km',
    '30+': 'preko 30 km',
};

export const ELEVATION_LABELS = {
    '0-300': 'do 300 m',
    '300-700': '300–700 m',
    '700-1200': '700–1.200 m',
    '1200+': 'preko 1.200 m',
};

export function emptyFilters() {
    return {
        q: '',
        region: '',
        mountain: '',
        difficulty: [],
        dist: '',
        elev: '',
        gpx: '',
        category: '',
    };
}

export function parseFilters(searchParams) {
    const filters = emptyFilters();
    if (!searchParams) return filters;

    filters.q = String(searchParams.get('q') || '').slice(0, 120);
    filters.region = String(searchParams.get('region') || '').slice(0, 90);
    filters.mountain = String(searchParams.get('mountain') || '').slice(0, 90);
    filters.dist = String(searchParams.get('dist') || '').slice(0, 20);
    filters.elev = String(searchParams.get('elev') || '').slice(0, 20);
    filters.category = String(searchParams.get('category') || '').slice(0, 60);
    const gpx = String(searchParams.get('gpx') || '');
    filters.gpx = ['yes', 'no'].includes(gpx) ? gpx : '';

    const difficulty = String(searchParams.get('difficulty') || '')
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);
    filters.difficulty = [...new Set(difficulty)];

    return filters;
}

export function filtersToParams(filters, extra = {}) {
    const params = new URLSearchParams();
    if (filters.q) params.set('q', filters.q);
    if (filters.region) params.set('region', filters.region);
    if (filters.mountain) params.set('mountain', filters.mountain);
    if (filters.difficulty?.length) params.set('difficulty', filters.difficulty.join(','));
    if (filters.dist) params.set('dist', filters.dist);
    if (filters.elev) params.set('elev', filters.elev);
    if (filters.gpx) params.set('gpx', filters.gpx);
    if (filters.category) params.set('category', filters.category);
    for (const [key, value] of Object.entries(extra)) {
        if (value !== null && value !== undefined && value !== '') params.set(key, String(value));
    }
    return params;
}

function effectiveDistance(trail) {
    return trail?.distance?.official_km ?? trail?.distance?.calculated_km ?? null;
}

function effectiveGain(trail) {
    return trail?.elevation?.official_gain_m ?? trail?.elevation?.calculated_gain_m ?? null;
}

function inBucket(value, bucket, definitions) {
    if (value === null || value === undefined) return false;
    const range = definitions[bucket];
    if (!range) return true;
    return Number(value) >= range[0] && Number(value) < range[1];
}

export function trailMatches(trail, filters) {
    if (!trail) return false;

    if (filters.category && trail.category !== filters.category) return false;
    if (filters.region && trail.region !== filters.region) return false;
    if (filters.mountain && trail.mountain !== filters.mountain) return false;

    if (filters.difficulty?.length && !filters.difficulty.includes(trail.difficulty)) return false;

    if (filters.dist && !inBucket(effectiveDistance(trail), filters.dist, DISTANCE_BUCKETS)) return false;
    if (filters.elev && !inBucket(effectiveGain(trail), filters.elev, ELEVATION_BUCKETS)) return false;

    if (filters.gpx === 'yes' && !trail.gpx?.available) return false;
    if (filters.gpx === 'no' && trail.gpx?.available) return false;

    const query = fold(filters.q || '').trim();
    if (query) {
        const haystack = trail.search || fold(`${trail.name} ${trail.mountain || ''} ${trail.region || ''} ${trail.code || ''}`);
        const tokens = query.split(/\s+/).filter(Boolean);
        for (const token of tokens) {
            if (!haystack.includes(token)) return false;
        }
    }

    return true;
}

export function scoreTrail(trail, query) {
    const q = fold(query || '').trim();
    if (!q) return 0;

    const name = fold(trail.name || '');
    const code = fold(trail.code || '');
    const mountain = fold(trail.mountain || '');
    const region = fold(trail.region || '');
    let score = 0;

    if (code && code === q) score += 140;
    if (name === q) score += 130;
    else if (name.startsWith(q)) score += 100;
    else if (name.includes(q)) score += 70;
    if (mountain.startsWith(q)) score += 45;
    if (region.startsWith(q)) score += 30;
    if (trail.search?.includes(q)) score += 18;

    return score;
}

export function filterTrails(trails, filters) {
    const query = (filters.q || '').trim();
    const matched = trails.filter((trail) => trailMatches(trail, filters));

    if (!query) return matched;

    return matched
        .map((trail) => ({ trail, score: scoreTrail(trail, query) }))
        .sort((a, b) => b.score - a.score || String(a.trail.name).localeCompare(String(b.trail.name), 'sr-Latn'))
        .map((entry) => entry.trail);
}

export function countActiveFilters(filters) {
    let count = 0;
    if (filters.q) count++;
    if (filters.region) count++;
    if (filters.mountain) count++;
    if (filters.difficulty?.length) count += filters.difficulty.length;
    if (filters.dist) count++;
    if (filters.elev) count++;
    if (filters.gpx) count++;
    if (filters.category) count++;
    return count;
}

export function activeFilterChips(filters, facets = {}) {
    const chips = [];
    const regionLabel = facets.regions?.find((item) => item.key === filters.region)?.label || filters.region;
    const mountainLabel = facets.mountains?.find((item) => item.key === filters.mountain)?.label || filters.mountain;
    const difficultyLabels = {
        laka: 'Laka',
        srednje_teska: 'Srednje teška',
        zahtevna: 'Zahtevna',
        vrlo_teska: 'Vrlo teška',
        ostalo: 'Ostalo',
        nepoznato: 'Nije navedeno',
    };

    if (filters.q) chips.push({ key: 'q', label: `„${filters.q}“` });
    if (filters.region) chips.push({ key: 'region', label: regionLabel });
    if (filters.mountain) chips.push({ key: 'mountain', label: mountainLabel });
    for (const difficulty of filters.difficulty || []) {
        chips.push({ key: `difficulty:${difficulty}`, label: difficultyLabels[difficulty] || difficulty });
    }
    if (filters.dist) chips.push({ key: 'dist', label: DISTANCE_LABELS[filters.dist] || filters.dist });
    if (filters.elev) chips.push({ key: 'elev', label: ELEVATION_LABELS[filters.elev] || filters.elev });
    if (filters.category) {
        const categoryLabel = facets.categories?.find((item) => item.key === filters.category)?.label || filters.category;
        chips.push({ key: 'category', label: categoryLabel });
    }
    if (filters.gpx === 'yes') chips.push({ key: 'gpx', label: 'Sa GPX-om' });
    if (filters.gpx === 'no') chips.push({ key: 'gpx', label: 'Bez GPX-a' });

    return chips;
}

export function removeFilter(filters, key) {
    const next = { ...filters, difficulty: [...(filters.difficulty || [])] };
    if (key.startsWith('difficulty:')) {
        const value = key.slice('difficulty:'.length);
        next.difficulty = next.difficulty.filter((item) => item !== value);
    } else if (key === 'q') next.q = '';
    else if (key === 'gpx') next.gpx = '';
    else if (key in next) next[key] = '';
    return next;
}
