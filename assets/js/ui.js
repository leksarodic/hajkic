import {
    escapeHtml,
    formatDistance,
    formatDuration,
    formatGain,
    formatInteger,
    resultLabel,
    trailPlace,
} from './lib/format.js';
import { trailPageUrl } from './lib/store.js';

function svgIcon(name) {
    const paths = {
        gpx: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 15h6"/>',
        close: '<path d="m6 6 12 12M18 6 6 18"/>',
    };
    return `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || ''}</svg>`;
}

export function trailCardHtml(trail, options = {}) {
    const large = Boolean(options.large);
    const distance = formatDistance(trail);
    const gain = formatGain(trail);
    const duration = trail.duration?.text || formatDuration(trail.duration?.minutes);
    const place = trailPlace(trail);
    const hasGpx = Boolean(trail.gpx?.available);
    const hasElevation = Boolean(trail.gpx?.has_elevation);
    const difficultyKey = trail.difficulty || 'nepoznato';
    const difficultyLabel = trail.difficulty_label || 'Nije navedeno';
    const description = trail.description
        ? `${String(trail.description).slice(0, 170)}${String(trail.description).length > 170 ? '…' : ''}`
        : '';

    return `
        <a class="trail-card ${large ? 'trail-card--large' : ''}" href="${escapeHtml(trailPageUrl(trail.slug))}" data-slug="${escapeHtml(trail.slug)}"
           aria-label="${escapeHtml(trail.name + (place ? ' — ' + place : ''))}">
            <span class="trail-card__top">
                <span class="trail-card__title-wrap">
                    <span class="trail-card__title">${escapeHtml(trail.name)}</span>
                    ${place ? `<span class="trail-card__place">${escapeHtml(place)}</span>` : ''}
                </span>
                <span class="gpx-indicator ${hasGpx ? 'is-available' : 'is-missing'}" title="${hasGpx ? 'GPX dostupan' : 'GPX nije dostupan'}">
                    ${svgIcon('gpx')}
                    <span class="sr-only">${hasGpx ? 'GPX dostupan' : 'GPX nije dostupan'}</span>
                </span>
            </span>
            <span class="trail-card__meta">
                ${distance.text ? `<span class="trail-stat"><span class="trail-stat__value">${distance.calculated ? '≈' : ''}${escapeHtml(distance.text)}</span><span class="trail-stat__unit">km</span></span>` : ''}
                <span class="badge badge--${escapeHtml(difficultyKey)}">${escapeHtml(difficultyLabel)}</span>
                ${duration ? `<span class="trail-stat trail-stat--quiet">${escapeHtml(duration)}</span>` : ''}
                ${large && gain.text ? `<span class="trail-stat trail-stat--quiet">${gain.calculated ? '≈' : ''}${escapeHtml(gain.text)} m uspona</span>` : ''}
                ${hasElevation ? `<span class="trail-card__elevation" title="GPX sadrži visinske podatke">profil</span>` : ''}
            </span>
            ${large && description ? `<span class="trail-card__desc">${escapeHtml(description)}</span>` : ''}
            ${large && trail.code ? `<span class="trail-card__code">Oznaka ${escapeHtml(trail.code)}</span>` : ''}
        </a>
    `;
}

export function renderActiveFilters(container, filters, facets, onRemove) {
    if (!container) return;
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
    const distanceLabels = {
        '0-5': 'do 5 km',
        '5-10': '5–10 km',
        '10-20': '10–20 km',
        '20-30': '20–30 km',
        '30+': 'preko 30 km',
    };
    const elevationLabels = {
        '0-300': 'uspon do 300 m',
        '300-700': 'uspon 300–700 m',
        '700-1200': 'uspon 700–1.200 m',
        '1200+': 'uspon preko 1.200 m',
    };

    if (filters.q) chips.push({ key: 'q', label: `„${filters.q}“` });
    if (filters.region) chips.push({ key: 'region', label: regionLabel });
    if (filters.mountain) chips.push({ key: 'mountain', label: mountainLabel });
    for (const difficulty of filters.difficulty || []) {
        chips.push({ key: `difficulty:${difficulty}`, label: difficultyLabels[difficulty] || difficulty });
    }
    if (filters.dist) chips.push({ key: 'dist', label: distanceLabels[filters.dist] || filters.dist });
    if (filters.elev) chips.push({ key: 'elev', label: elevationLabels[filters.elev] || filters.elev });
    if (filters.category) {
        const categoryLabel = facets.categories?.find((item) => item.key === filters.category)?.label || filters.category;
        chips.push({ key: 'category', label: categoryLabel });
    }
    if (filters.gpx === 'yes') chips.push({ key: 'gpx', label: 'Sa GPX-om' });
    if (filters.gpx === 'no') chips.push({ key: 'gpx', label: 'Bez GPX-a' });

    container.innerHTML = chips.map((chip) => `
        <span class="active-chip">${escapeHtml(chip.label)}
            <button type="button" data-remove-filter="${escapeHtml(chip.key)}" aria-label="Ukloni filter ${escapeHtml(chip.label)}">${svgIcon('close')}</button>
        </span>
    `).join('');

    container.querySelectorAll('[data-remove-filter]').forEach((button) => {
        button.addEventListener('click', () => onRemove(button.dataset.removeFilter));
    });
}

export function syncFilterControls(filters) {
    const setValue = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.value = value || '';
    };

    setValue('filter-region', filters.region);
    setValue('filter-mountain', filters.mountain);
    setValue('filter-distance', filters.dist);
    setValue('filter-elevation', filters.elev);
    setValue('filter-category', filters.category);

    document.querySelectorAll('input[name="difficulty"]').forEach((input) => {
        input.checked = (filters.difficulty || []).includes(input.value);
    });

    document.querySelectorAll('input[name="gpx"]').forEach((input) => {
        input.checked = (input.value || '') === (filters.gpx || '');
    });
}

export function readFilterControls(base) {
    const value = (id) => document.getElementById(id)?.value || '';
    const difficulties = [...document.querySelectorAll('input[name="difficulty"]:checked')].map((input) => input.value);
    const gpx = document.querySelector('input[name="gpx"]:checked')?.value || '';

    return {
        ...base,
        region: value('filter-region'),
        mountain: value('filter-mountain'),
        difficulty: difficulties,
        dist: value('filter-distance'),
        elev: value('filter-elevation'),
        category: value('filter-category'),
        gpx,
    };
}

export function updateResultCount(element, count) {
    if (element) element.textContent = resultLabel(count);
}

export function updateFilterCount(element, count) {
    if (!element) return;
    element.textContent = formatInteger(count) || '0';
    element.hidden = count === 0;
}

/**
 * Expand the mountain select with the facets already present in the HTML.
 * Kept as a separate helper so the client never needs a second request.
 */
export function mountainOptions(facets) {
    return (facets.mountains || []).filter((item) => item.count > 0);
}
