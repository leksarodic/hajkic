import {
    filterTrails,
    parseFilters,
    filtersToParams,
    countActiveFilters,
    removeFilter,
} from './lib/filters.js';
import {
    debounce,
    isMobileView,
    showToast,
} from './lib/dom.js';
import {
    loadIndex,
    loadOverview,
    loadTrail,
    loadCountryBorder,
    trailPageUrl,
} from './lib/store.js';
import {
    trailCardHtml,
    renderActiveFilters,
    syncFilterControls,
    readFilterControls,
    updateResultCount,
    updateFilterCount,
} from './ui.js';
import { TrailMap } from './map.js';
import { renderDetail } from './detail.js';
import { bearingDegrees, formatShortDistance, haversine } from './lib/format.js';

const config = window.HAJKIC || {};
const page = config.page || 'map';

const state = {
    page,
    index: null,
    trails: [],
    facets: {},
    filters: parseFilters(new URLSearchParams(window.location.search)),
    results: [],
    selectedSlug: config.selected || (new URLSearchParams(window.location.search).get('trail') || null),
    rendered: 0,
    chunkSize: config.ui?.results_chunk || 36,
    map: null,
    detailController: null,
    detailInstance: null,
    sheet: 'peek',
    lastFocused: null,
    overviewReady: false,
};

const elements = {
    searchDesktop: document.getElementById('trail-search'),
    searchMobile: document.getElementById('trail-search-mobile'),
    searchClear: document.getElementById('search-clear'),
    list: document.getElementById('trail-list'),
    grid: document.getElementById('catalogue-grid'),
    empty: document.getElementById('list-empty'),
    sentinel: document.getElementById('list-sentinel'),
    resultCount: document.getElementById('result-count'),
    filterCount: document.getElementById('filter-count'),
    mobileFilterCount: document.getElementById('mobile-filter-count'),
    filterPanel: document.getElementById('filter-panel'),
    filterToggle: document.getElementById('filter-toggle'),
    mobileFilterBtn: document.getElementById('mobile-filter-btn'),
    filterApplyCount: document.getElementById('filter-apply-count'),
    activeFilters: document.getElementById('active-filters'),
    panel: document.getElementById('trail-panel'),
    panelContent: document.getElementById('trail-panel-content'),
    panelActions: document.getElementById('trail-panel-actions'),
    explorer: document.getElementById('explorer'),
    mapStatus: document.getElementById('map-status'),
    mapStatusText: document.getElementById('map-status-text'),
    nearbyPanel: document.getElementById('nearby-panel'),
    nearbyList: document.getElementById('nearby-list'),
    nearbyMeta: document.getElementById('nearby-meta'),
    loadMore: document.getElementById('load-more'),
};

const debouncedApply = debounce(() => applyMapFilters(), 160);
let listObserver = null;

boot().catch((error) => {
    console.error(error);
    showFatal(error);
});

async function boot() {
    setupSearch();
    setupFilters();
    setupKeyboard();
    setupSheet();

    const index = await loadIndex();
    state.index = index;
    state.trails = Array.isArray(index.trails) ? index.trails : [];
    state.facets = index.facets || {};

    syncFilterControls(state.filters);
    syncSearchInputs(state.filters.q);

    if (page === 'map') {
        await bootMap();
    } else if (page === 'catalogue') {
        applyCatalogueFilters();
    }
}

async function bootMap() {
    const mapElement = document.getElementById('map');
    if (!mapElement) return;

    state.map = new TrailMap(mapElement, {
        onSelect: (slug) => selectTrail(slug),
        onBackgroundClick: () => closeDetail(),
        onLocating: () => showStatus('Tražim vašu lokaciju…'),
        onLocated: (position) => {
            hideStatus();
            renderNearby(position);
        },
        onLocationError: () => hideStatus(),
    });

    try {
        state.map.init();
    } catch (error) {
        console.error(error);
        showFatal(new Error('Mapu nije moguće pokrenuti.'));
        return;
    }

    state.map.setIndex(state.index);
    state.map.renderLayerOptions(document.getElementById('layer-options'));
    setupMapControls();

    loadCountryBorder()
        .then((border) => {
            if (border) state.map.setCountryBorder(border);
        })
        .catch((error) => console.warn('Granica nije učitana.', error));

    applyMapFilters({ initial: true });

    // Leaflet may measure the container before the CSS grid has settled.
    window.requestAnimationFrame(() => {
        state.map?.map?.invalidateSize({ pan: false });
        state.map?.resetView();
    });

    // Load the lightweight overview after the list is interactive.
    showStatus('Učitavanje pregleda staza…');
    loadOverview()
        .then((overview) => {
            state.map.setOverview(overview);
            state.overviewReady = true;
            hideStatus();
            // Re-apply the filter once overview layers exist.
            state.map.setFiltered(new Set(state.results.map((trail) => trail.slug)));
        })
        .catch((error) => {
            console.error(error);
            showStatus('Pregled staza nije učitan. Mapa i dalje radi.', true);
            window.setTimeout(hideStatus, 4500);
        });

    if (state.selectedSlug) {
        await selectTrail(state.selectedSlug, { initial: true });
    }
}

// ---------------------------------------------------------------------------
// Filters, search, URL
// ---------------------------------------------------------------------------

function setupSearch() {
    const inputs = [elements.searchDesktop, elements.searchMobile].filter(Boolean);

    for (const input of inputs) {
        input.addEventListener('input', () => {
            const value = input.value;
            for (const other of inputs) {
                if (other !== input && other.value !== value) other.value = value;
            }
            if (elements.searchClear) elements.searchClear.hidden = value.length === 0;
            state.filters.q = value;
            if (page === 'map') debouncedApply();
            else debouncedApplyCatalogue();
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                input.value = '';
                state.filters.q = '';
                if (elements.searchClear) elements.searchClear.hidden = true;
                if (page === 'map') applyMapFilters();
                else applyCatalogueFilters();
            }
        });
    }

    elements.searchClear?.addEventListener('click', () => {
        for (const input of inputs) input.value = '';
        state.filters.q = '';
        elements.searchClear.hidden = true;
        if (page === 'map') applyMapFilters();
        else applyCatalogueFilters();
        inputs[0]?.focus();
    });

    document.getElementById('search-form')?.addEventListener('submit', (event) => event.preventDefault());
    document.getElementById('mobile-search-form')?.addEventListener('submit', (event) => event.preventDefault());

    if (elements.searchDesktop) elements.searchClear && (elements.searchClear.hidden = elements.searchDesktop.value.length === 0);

    document.querySelectorAll('[data-search-trigger]').forEach((button) => {
        button.addEventListener('click', () => {
            const mobile = isMobileView();
            const target = mobile && elements.searchMobile ? elements.searchMobile : elements.searchDesktop;
            target?.focus();
            target?.select();
        });
    });
}

function syncSearchInputs(value) {
    for (const input of [elements.searchDesktop, elements.searchMobile]) {
        if (input && input.value !== value) input.value = value;
    }
    if (elements.searchClear) elements.searchClear.hidden = !value;
}

const debouncedApplyCatalogue = debounce(() => applyCatalogueFilters(), 160);

function setupFilters() {
    elements.filterToggle?.addEventListener('click', () => toggleFilters());
    elements.mobileFilterBtn?.addEventListener('click', () => toggleFilters(true));
    document.getElementById('filter-close')?.addEventListener('click', () => closeFilters());
    document.getElementById('filter-apply')?.addEventListener('click', () => closeFilters());
    document.getElementById('filter-reset')?.addEventListener('click', resetFilters);
    document.getElementById('empty-reset')?.addEventListener('click', resetFilters);

    elements.filterPanel?.addEventListener('change', () => {
        state.filters = readFilterControls(state.filters);
        if (page === 'map') applyMapFilters();
        else applyCatalogueFilters();
    });

    elements.filterPanel?.addEventListener('input', (event) => {
        if (event.target.matches('select, input')) {
            state.filters = readFilterControls(state.filters);
            updateLiveCounts();
        }
    });
}

function toggleFilters(open = null) {
    if (!elements.filterPanel) return;
    const shouldOpen = open === null ? elements.filterPanel.hidden : open;
    elements.filterPanel.hidden = !shouldOpen;
    elements.filterToggle?.setAttribute('aria-expanded', String(shouldOpen));
    elements.mobileFilterBtn?.setAttribute('aria-expanded', String(shouldOpen));
    if (shouldOpen) {
        if (page === 'map' && !isMobileView()) {
            elements.filterPanel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        elements.filterPanel.querySelector('select, input, button')?.focus({ preventScroll: true });
    }
}

function closeFilters() {
    toggleFilters(false);
}

function resetFilters() {
    state.filters = {
        q: '',
        region: '',
        mountain: '',
        difficulty: [],
        dist: '',
        elev: '',
        gpx: '',
        category: '',
    };
    syncSearchInputs('');
    syncFilterControls(state.filters);
    if (page === 'map') applyMapFilters();
    else applyCatalogueFilters();
}

function updateLiveCounts() {
    const count = filterTrails(state.trails, state.filters).length;
    if (elements.filterApplyCount) elements.filterApplyCount.textContent = String(count);
    updateFilterCount(elements.filterCount, countActiveFilters(state.filters));
    updateFilterCount(elements.mobileFilterCount, countActiveFilters(state.filters));
    refreshActiveFilterChips();
}

function refreshActiveFilterChips() {
    renderActiveFilters(elements.activeFilters, state.filters, state.facets, (key) => {
        state.filters = removeFilter(state.filters, key);
        if (key === 'q') syncSearchInputs('');
        syncFilterControls(state.filters);
        if (page === 'map') applyMapFilters();
        else applyCatalogueFilters();
    });
}

function applyMapFilters(options = {}) {
    if (page !== 'map') return;
    state.filters = readFilterControls(state.filters);

    // The search field is the source of truth while typing.
    const activeInput = document.activeElement;
    if (activeInput === elements.searchDesktop || activeInput === elements.searchMobile) {
        state.filters.q = activeInput.value;
    }

    state.results = filterTrails(state.trails, state.filters);
    state.rendered = 0;
    state.map?.setFiltered(new Set(state.results.map((trail) => trail.slug)));

    closeListObserver();
    updateMapList();
    updateResultCount(elements.resultCount, state.results.length);
    updateLiveCounts();
    updateUrl();

    if (options.initial && state.selectedSlug) {
        markSelectedCard(state.selectedSlug);
    }
}

function applyCatalogueFilters() {
    if (page !== 'catalogue') return;
    state.filters = readFilterControls(state.filters);

    const activeInput = document.activeElement;
    if (activeInput === elements.searchDesktop) state.filters.q = activeInput.value;

    state.results = filterTrails(state.trails, state.filters);
    state.rendered = 0;
    if (elements.grid) elements.grid.innerHTML = '';
    appendCatalogueChunk();

    updateResultCount(elements.resultCount, state.results.length);
    updateLiveCounts();
    updateUrl();
}

function updateMapList() {
    if (!elements.list) return;
    elements.list.innerHTML = '';
    elements.empty.hidden = state.results.length !== 0;
    elements.list.hidden = state.results.length === 0;

    if (!state.results.length) {
        return;
    }

    appendMapChunk();
    setupListObserver();
}

function appendMapChunk() {
    if (!elements.list) return;
    const next = state.results.slice(state.rendered, state.rendered + state.chunkSize);
    if (!next.length) return;

    const fragment = document.createElement('div');
    fragment.innerHTML = next.map((trail) => trailCardHtml(trail)).join('');
    const cards = [...fragment.children];
    for (const card of cards) {
        if (card.dataset.slug === state.selectedSlug) card.classList.add('is-selected');
        elements.list.appendChild(card);
    }
    state.rendered += next.length;
    bindCardHover();
}

function setupListObserver() {
    closeListObserver();
    if (!elements.sentinel || state.rendered >= state.results.length) return;

    listObserver = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            appendMapChunk();
            if (state.rendered >= state.results.length) closeListObserver();
        }
    }, { root: elements.list.closest('.explorer__scroll'), rootMargin: '200px' });

    listObserver.observe(elements.sentinel);
}

function closeListObserver() {
    if (listObserver) {
        listObserver.disconnect();
        listObserver = null;
    }
}

function appendCatalogueChunk() {
    if (!elements.grid) return;
    const chunk = state.chunkSize + 6;
    const next = state.results.slice(state.rendered, state.rendered + chunk);
    if (next.length) {
        elements.grid.insertAdjacentHTML('beforeend', next.map((trail) => trailCardHtml(trail, { large: true })).join(''));
        state.rendered += next.length;
    }

    elements.empty.hidden = state.results.length !== 0;
    elements.grid.hidden = state.results.length === 0;
    if (elements.loadMore) {
        elements.loadMore.hidden = state.rendered >= state.results.length;
        elements.loadMore.textContent = `Prikaži još staza (${Math.max(0, state.results.length - state.rendered)})`;
    }
}

document.getElementById('load-more')?.addEventListener('click', () => {
    appendCatalogueChunk();
});

function bindCardHover() {
    if (!elements.list || elements.list.dataset.hoverBound === '1') return;
    elements.list.dataset.hoverBound = '1';

    elements.list.addEventListener('mouseover', (event) => {
        const card = event.target.closest('.trail-card');
        if (!card) return;
        const slug = card.dataset.slug;
        state.map?.hover(slug);
        card.classList.add('is-hovered');
    });

    elements.list.addEventListener('mouseout', (event) => {
        const card = event.target.closest('.trail-card');
        if (!card) return;
        const related = event.relatedTarget?.closest?.('.trail-card');
        if (related === card) return;
        state.map?.hover(null);
        card.classList.remove('is-hovered');
    });

    elements.list.addEventListener('focusin', (event) => {
        const card = event.target.closest('.trail-card');
        if (card) state.map?.hover(card.dataset.slug);
    });

    elements.list.addEventListener('focusout', (event) => {
        if (!event.relatedTarget?.closest?.('.trail-card')) {
            state.map?.hover(null);
        }
    });

    elements.list.addEventListener('click', (event) => {
        const card = event.target.closest('.trail-card');
        if (!card || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        selectTrail(card.dataset.slug);
    });
}

function markSelectedCard(slug) {
    document.querySelectorAll('.trail-card.is-selected').forEach((card) => card.classList.remove('is-selected'));
    if (!slug) return;
    const card = document.querySelector(`.trail-card[data-slug="${CSS.escape(slug)}"]`);
    card?.classList.add('is-selected');
}

function updateUrl() {
    const params = filtersToParams(state.filters);
    let path;

    if (page === 'catalogue') {
        path = `${String(config.base || '/').replace(/\/?$/, '/')}staze`;
    } else {
        path = config.base || '/';
        if (state.selectedSlug) params.set('trail', state.selectedSlug);
    }

    const query = params.toString();
    const url = `${path}${query ? `?${query}` : ''}`;
    try {
        window.history.replaceState({ filters: state.filters, trail: state.selectedSlug }, '', url);
    } catch {
        // History can be unavailable in some embedded browsers; filtering still works.
    }
}

// ---------------------------------------------------------------------------
// Detail panel
// ---------------------------------------------------------------------------

async function selectTrail(slug, options = {}) {
    if (!slug) return;

    state.lastFocused = document.activeElement;
    state.detailController?.abort();
    state.detailController = new AbortController();

    if (elements.panel) {
        elements.panel.hidden = false;
        if (!elements.panelContent.dataset.loading) {
            elements.panelContent.dataset.loading = '1';
            elements.panelContent.innerHTML = `
                <div class="detail-head">
                    <div class="detail-eyebrow">Učitavanje staze…</div>
                    <h2 class="detail-title">${escapeBasic(slug.replace(/-/g, ' '))}</h2>
                </div>
                <div class="detail-empty">Učitavanje detalja i GPX geometrije…</div>
            `;
        }
    }

    if (isMobileView()) setSheet('peek');

    try {
        const { trail, geojson } = await loadTrail(slug, { signal: state.detailController.signal });
        if (!trail) throw new Error('Staza nema lokalne podatke.');

        state.selectedSlug = slug;
        await state.map?.select(trail, geojson);
        markSelectedCard(slug);
        document.getElementById('map-clear')?.removeAttribute('hidden');

        document.body.classList.add('has-detail');
        state.detailInstance?.destroy();
        state.detailInstance = renderDetail(elements.panelContent, trail, geojson, {
            actionsHost: elements.panelActions,
            onProfileHover: (point) => state.map?.setProfilePoint(point),
        });
        delete elements.panelContent.dataset.loading;
        elements.panel.hidden = false;
        elements.panel.scrollTop = 0;
        elements.panelContent.scrollTop = 0;

        const bindAction = (name, handler) => {
            elements.panelContent.querySelector(`[data-action="${name}"]`)?.addEventListener('click', handler);
            elements.panelActions.querySelector(`[data-action="${name}"]`)?.addEventListener('click', handler);
        };
        bindAction('close', () => closeDetail());
        bindAction('fit', () => {
            state.map?.fitSelected();
            if (isMobileView()) elements.panel.hidden = true;
        });
        bindAction('copy', () => copyTrailLink(slug));
        bindAction('share', () => shareTrail(trail, slug));
        bindAction('ar', async () => {
            if (!trail.geometry?.start_point && !geojson?.features?.length) {
                showToast('AR nema poznatu lokaciju staze.');
                return;
            }
            try {
                const { openAR } = await import('./ar.js');
                openAR({ trail, geojson });
            } catch (error) {
                console.error(error);
                showToast('AR pogled nije dostupan na ovom uređaju.');
            }
        });

        if (!options.initial) {
            updateUrl();
        } else {
            updateUrl();
        }

        if (!options.initial) {
            const title = elements.panelContent.querySelector('#detail-title');
            title?.focus({ preventScroll: true });
        }
    } catch (error) {
        if (error.name === 'AbortError') return;
        console.error(error);
        delete elements.panelContent.dataset.loading;
        elements.panelActions.innerHTML = '';
        elements.panelContent.innerHTML = `
            <div class="detail-head">
                <button type="button" class="detail-close" data-action="close" aria-label="Zatvori detalje">${icon('close')}</button>
                <p class="detail-eyebrow">Greška</p>
                <h2 class="detail-title">Staza nije dostupna</h2>
            </div>
            <div class="detail-empty">Detalji ove staze trenutno nisu dostupni. Pokušajte ponovo ili otvorite originalnu PSS stranicu.</div>
        `;
        elements.panelContent.querySelector('[data-action="close"]')?.addEventListener('click', () => closeDetail());
        showToast('Detalji staze nisu dostupni.');
    }
}

function closeDetail(keepSelection = false) {
    if (elements.panel) elements.panel.hidden = true;
    document.body.classList.remove('has-detail');
    if (elements.panelActions) elements.panelActions.innerHTML = '';
    state.detailInstance?.destroy();
    state.detailInstance = null;
    state.map?.setProfilePoint(null);

    if (!keepSelection) {
        state.map?.clearSelection();
        state.selectedSlug = null;
        markSelectedCard(null);
        document.getElementById('map-clear')?.setAttribute('hidden', '');
        updateUrl();
    }

    if (state.lastFocused && document.contains(state.lastFocused)) {
        state.lastFocused.focus?.({ preventScroll: true });
    }
}

async function copyTrailLink(slug) {
    const url = new URL(trailPageUrl(slug), window.location.origin).href;
    try {
        await navigator.clipboard.writeText(url);
        showToast('Link staze je kopiran.');
    } catch {
        fallbackCopy(url);
    }
}

function fallbackCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try {
        document.execCommand('copy');
        showToast('Link staze je kopiran.');
    } catch {
        showToast('Kopiranje nije uspelo. Označite link ručno.');
    }
    area.remove();
}

async function shareTrail(trail, slug) {
    const url = new URL(trailPageUrl(slug), window.location.origin).href;
    if (navigator.share) {
        try {
            await navigator.share({
                title: `${trail.name} — Hajkić`,
                text: `${trail.name} · ${trail.mountain || trail.region || 'planinarska staza'}`,
                url,
            });
            return;
        } catch (error) {
            if (error.name === 'AbortError') return;
        }
    }
    await copyTrailLink(slug);
}

// ---------------------------------------------------------------------------
// Map controls, status, sheet
// ---------------------------------------------------------------------------

function setupMapControls() {
    document.getElementById('zoom-in')?.addEventListener('click', () => state.map?.zoomIn());
    document.getElementById('zoom-out')?.addEventListener('click', () => state.map?.zoomOut());
    document.getElementById('map-reset')?.addEventListener('click', () => state.map?.resetView());
    document.getElementById('map-clear')?.addEventListener('click', () => closeDetail());
    document.getElementById('map-locate')?.addEventListener('click', locateMe);
    document.getElementById('locate-nearby')?.addEventListener('click', locateMe);

    document.querySelectorAll('#basemap-switch [data-basemap]').forEach((button) => {
        button.addEventListener('click', () => setBasemap(button.dataset.basemap));
    });
    document.getElementById('layer-options')?.addEventListener('change', (event) => {
        const input = event.target.closest('input[name="basemap"]');
        if (input) setBasemap(input.value);
    });
    document.getElementById('nearby-close')?.addEventListener('click', () => {
        if (elements.nearbyPanel) elements.nearbyPanel.hidden = true;
    });

    const layersButton = document.getElementById('map-layers');
    const layerPanel = document.getElementById('layer-panel');
    layersButton?.addEventListener('click', () => {
        const open = layerPanel.hidden;
        layerPanel.hidden = !open;
        layersButton.setAttribute('aria-expanded', String(open));
    });

    document.addEventListener('click', (event) => {
        if (!layerPanel || layerPanel.hidden) return;
        if (layerPanel.contains(event.target) || layersButton?.contains(event.target)) return;
        layerPanel.hidden = true;
        layersButton?.setAttribute('aria-expanded', 'false');
    });

    document.getElementById('legend-toggle')?.addEventListener('click', () => {
        const legend = document.getElementById('map-legend');
        if (!legend) return;
        if (isMobileView()) {
            const open = legend.classList.toggle('is-open');
            document.getElementById('legend-toggle')?.setAttribute('aria-expanded', String(open));
        } else {
            const collapsed = legend.classList.toggle('is-collapsed');
            document.getElementById('legend-toggle')?.setAttribute('aria-expanded', String(!collapsed));
        }
    });
}

function setBasemap(providerId) {
    if (!providerId) return;
    state.map?.setProvider(providerId);
    document.querySelectorAll('#basemap-switch [data-basemap]').forEach((button) => {
        const active = button.dataset.basemap === providerId;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', String(active));
    });
    document.querySelectorAll('#layer-options input[name="basemap"]').forEach((input) => {
        input.checked = input.value === providerId;
    });
}

function locateMe() {
    if (!state.map) return;
    if (isMobileView()) {
        elements.nearbyPanel?.setAttribute('hidden', '');
    }
    showStatus('Tražim vašu lokaciju…');
    state.map.locate();
}

function renderNearby(position) {
    if (!elements.nearbyPanel || !elements.nearbyList) return;
    state.userLocation = position;

    const candidates = state.trails
        .map((trail) => {
            const point = Array.isArray(trail.start_point) ? trail.start_point : (Array.isArray(trail.center) ? trail.center : null);
            if (!point) return null;
            const distance = haversine(position.lat, position.lng, point[1], point[0]);
            return {
                trail,
                distance,
                bearing: bearingDegrees(position.lat, position.lng, point[1], point[0]),
            };
        })
        .filter(Boolean)
        .sort((a, b) => a.distance - b.distance)
        .slice(0, 6);

    if (!candidates.length) {
        elements.nearbyList.innerHTML = '<li class="nearby-item"><span class="nearby-item__name">Nema staza sa poznatim početkom u katalogu.</span></li>';
        elements.nearbyMeta.textContent = '';
        elements.nearbyPanel.hidden = false;
        return;
    }

    elements.nearbyMeta.textContent = `Vaša lokacija je pronađena. Udaljenosti su vazdušne linije do početka staze.`;
    elements.nearbyList.innerHTML = candidates.map(({ trail, distance, bearing }) => `
        <li>
            <button type="button" class="nearby-item" data-nearby-slug="${escapeBasic(trail.slug)}">
                <span class="nearby-item__arrow" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="transform: rotate(${Math.round(bearing)}deg)"><path d="M12 21V5m0 0-5 5m5-5 5 5"/></svg>
                </span>
                <span class="nearby-item__body">
                    <span class="nearby-item__name">${escapeBasic(trail.name)}</span>
                    <span class="nearby-item__place">${escapeBasic(trail.mountain || trail.region || '')} · ${Math.round(bearing)}°</span>
                </span>
                <span class="nearby-item__distance">${escapeBasic(formatShortDistance(distance) || '')}</span>
            </button>
        </li>
    `).join('');

    elements.nearbyPanel.hidden = false;
    elements.nearbyList.querySelectorAll('[data-nearby-slug]').forEach((button) => {
        button.addEventListener('click', () => selectTrail(button.dataset.nearbySlug));
    });

    if (isMobileView()) {
        setSheet('half');
    } else {
        elements.nearbyPanel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function showStatus(message, isError = false) {
    if (!elements.mapStatus) return;
    elements.mapStatus.hidden = false;
    elements.mapStatus.classList.toggle('is-error', Boolean(isError));
    if (elements.mapStatusText) elements.mapStatusText.textContent = message;
    const spinner = elements.mapStatus.querySelector('.spinner');
    if (spinner) spinner.style.display = isError ? 'none' : '';
}

function hideStatus() {
    if (elements.mapStatus) elements.mapStatus.hidden = true;
}

function setupSheet() {
    if (!elements.explorer || page !== 'map') return;
    setSheet('peek');

    const grab = document.getElementById('sheet-grab');
    if (!grab) return;

    let startY = 0;
    let startTop = 0;
    let dragging = false;
    let moved = false;

    const height = () => elements.explorer.getBoundingClientRect().height;

    const onPointerDown = (event) => {
        if (!isMobileView()) return;
        dragging = true;
        moved = false;
        startY = event.clientY;
        startTop = elements.explorer.getBoundingClientRect().top;
        elements.explorer.classList.add('is-dragging');
        grab.setPointerCapture?.(event.pointerId);
    };

    const onPointerMove = (event) => {
        if (!dragging) return;
        const delta = event.clientY - startY;
        if (Math.abs(delta) > 4) moved = true;
        const top = Math.max(0, Math.min(height() - 146, startTop + delta));
        elements.explorer.style.transform = `translateY(${top}px)`;
    };

    const onPointerUp = () => {
        if (!dragging) return;
        dragging = false;
        elements.explorer.classList.remove('is-dragging');
        const top = elements.explorer.getBoundingClientRect().top;
        const h = height();
        elements.explorer.style.transform = '';
        if (!moved) {
            setSheet(state.sheet === 'peek' ? 'half' : state.sheet === 'half' ? 'full' : 'peek');
            return;
        }
        if (top < h * 0.28) setSheet('full');
        else if (top < h * 0.68) setSheet('half');
        else setSheet('peek');
    };

    grab.addEventListener('pointerdown', onPointerDown);
    grab.addEventListener('pointermove', onPointerMove);
    grab.addEventListener('pointerup', onPointerUp);
    grab.addEventListener('pointercancel', onPointerUp);
    grab.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            setSheet(state.sheet === 'peek' ? 'half' : state.sheet === 'half' ? 'full' : 'peek');
        }
    });
}

function setSheet(value) {
    state.sheet = value;
    elements.explorer?.setAttribute('data-sheet', value);
}

// ---------------------------------------------------------------------------
// Keyboard, fatal errors
// ---------------------------------------------------------------------------

function setupKeyboard() {
    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            const target = isMobileView() && elements.searchMobile ? elements.searchMobile : elements.searchDesktop;
            target?.focus();
            target?.select();
            return;
        }

        if (event.key === 'Escape') {
            const layerPanel = document.getElementById('layer-panel');
            if (layerPanel && !layerPanel.hidden) {
                layerPanel.hidden = true;
                document.getElementById('map-layers')?.setAttribute('aria-expanded', 'false');
                return;
            }
            if (elements.filterPanel && !elements.filterPanel.hidden) {
                closeFilters();
                return;
            }
            if (elements.panel && !elements.panel.hidden) {
                if (document.activeElement === elements.panelContent.querySelector('#detail-title')) {
                    closeDetail();
                } else {
                    elements.panelContent.querySelector('[data-action="close"]')?.focus();
                }
            }
        }
    });
}

function showFatal(error) {
    console.error(error);
    if (elements.list) {
        elements.list.innerHTML = '';
        elements.list.hidden = true;
    }
    if (elements.empty) {
        elements.empty.hidden = false;
        elements.empty.innerHTML = `
            <div class="list-empty__mark">${icon('info')}</div>
            <h2>Podaci nisu dostupni.</h2>
            <p>Dataset trenutno ne može da se učita. Pokušajte da osvežite stranicu.</p>
            <button type="button" class="btn btn--primary" onclick="location.reload()">Osveži stranicu</button>
        `;
    }
    showStatus('Dataset nije učitan. Pokušajte ponovo.', true);
}

function escapeBasic(value) {
    return String(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[character]);
}

function icon(name) {
    const paths = {
        close: '<path d="m6 6 12 12M18 6 6 18"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8" r=".6" fill="currentColor" stroke="none"/>',
    };
    return `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || ''}</svg>`;
}
