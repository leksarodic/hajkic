import { escapeHtml } from './lib/format.js';
import { isMobileView, showToast } from './lib/dom.js';

const DIFFICULTY_COLORS = {
    laka: '#245c3d',
    srednje_teska: '#8a6826',
    zahtevna: '#b9521f',
    vrlo_teska: '#8b3124',
    ostalo: '#7b8577',
    nepoznato: '#7b8577',
};

export class TrailMap {
    constructor(element, callbacks = {}) {
        this.element = element;
        this.callbacks = callbacks;
        this.config = window.HAJKIC?.map || {};
        this.map = null;
        this.providers = new Map();
        this.currentProvider = null;
        this.overviewCasing = null;
        this.overviewLayer = null;
        this.overviewLayers = new Map();
        this.overviewCasingLayers = new Map();
        this.overviewStyles = new Map();
        this.cluster = null;
        this.markers = new Map();
        this.markerData = new Map();
        this.selectedGroup = null;
        this.selectedSlug = null;
        this.hoveredSlug = null;
        this.profileMarker = null;
        this.locationLayer = null;
        this.countryLayer = null;
        this.countryLabels = [];
        this.trails = [];
    }

    init() {
        if (this.map) return this;

        const bounds = this.config.bounds || [[42.05, 18.55], [46.25, 23.25]];
        this.map = L.map(this.element, {
            zoomControl: false,
            attributionControl: true,
            preferCanvas: true,
            minZoom: this.config.min_zoom || 5,
            maxZoom: this.config.max_zoom || 19,
            maxBounds: L.latLngBounds(bounds).pad(0.25),
            maxBoundsViscosity: 0.55,
            worldCopyJump: true,
            zoomSnap: 0.25,
            zoomDelta: 0.5,
        });

        this.map.fitBounds(L.latLngBounds(bounds), { padding: [18, 18] });
        this.map.on('click', () => this.callbacks.onBackgroundClick?.());
        this.map.on('resize', () => this.map?.invalidateSize({ pan: false }));

        const providers = this.config.providers || [];
        for (const provider of providers) {
            const layer = L.tileLayer(provider.url, {
                attribution: provider.attribution,
                maxZoom: provider.max_zoom || 19,
                minZoom: provider.min_zoom || this.config.min_zoom || 5,
                subdomains: provider.subdomains || 'abc',
                crossOrigin: true,
            });
            this.providers.set(provider.id, layer);
            if (provider.default || (!this.currentProvider && this.providers.size === 1)) {
                this.currentProvider = provider.id;
            }
        }
        if (!this.currentProvider && providers[0]) this.currentProvider = providers[0].id;
        if (this.currentProvider) this.providers.get(this.currentProvider)?.addTo(this.map);

        L.control.scale({ position: 'bottomright', imperial: false, maxWidth: 110 }).addTo(this.map);

        this.overviewLayer = null;

        this.cluster = L.markerClusterGroup({
            maxClusterRadius: 46,
            disableClusteringAtZoom: 13,
            showCoverageOnHover: false,
            spiderfyOnMaxZoom: true,
            removeOutsideVisibleBounds: true,
            iconCreateFunction: (cluster) => {
                const count = cluster.getChildCount();
                const size = count >= 100 ? 40 : count >= 10 ? 35 : 30;
                return L.divIcon({
                    html: `<div class="hajkic-cluster ${size > 35 ? 'hajkic-cluster--lg' : ''}">${count}</div>`,
                    className: '',
                    iconSize: [size, size],
                });
            },
        }).addTo(this.map);

        window.addEventListener('resize', () => {
            this.map?.invalidateSize({ pan: false });
            if (this.selectedSlug) this.fitSelected();
        });

        return this;
    }

    setCountryBorder(geojson) {
        if (!this.map || !geojson?.features?.length) return this;
        if (this.countryLayer) {
            this.map.removeLayer(this.countryLayer);
            this.countryLayer = null;
        }

        this.countryLayer = L.geoJSON(geojson, {
            renderer: L.canvas({ padding: 0.3 }),
            interactive: false,
            style: { color: '#1f4a34', weight: 1.7, opacity: 0.62, fill: false, lineCap: 'round', lineJoin: 'round' },
        }).addTo(this.map);

        // A subtle cream under-stroke keeps the outline readable over relief.
        const casing = L.geoJSON(geojson, {
            renderer: L.canvas({ padding: 0.3 }),
            interactive: false,
            style: { color: '#ffffff', weight: 4.2, opacity: 0.28, fill: false, lineCap: 'round', lineJoin: 'round' },
        });
        casing.addTo(this.map);
        this.countryLayer.bringToBack();
        casing.bringToBack();
        this.overviewCasing?.bringToFront();
        this.overviewLayer?.bringToFront();

        this.countryLabels = [];
        for (const label of this.config.country_labels || []) {
            const [lat, lng] = label.center || [];
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
            const marker = L.marker([lat, lng], {
                icon: L.divIcon({
                    className: 'country-label-wrap',
                    html: `<span class="country-label">${escapeHtml(label.name)}</span>`,
                    iconSize: [1, 1],
                    iconAnchor: [0, 0],
                }),
                interactive: false,
                keyboard: false,
                zIndexOffset: -500,
            }).addTo(this.map);
            this.countryLabels.push({ marker, minZoom: Number(label.min_zoom || 0) });
        }

        const updateLabels = () => {
            const zoom = this.map.getZoom();
            for (const entry of this.countryLabels) {
                if (zoom >= entry.minZoom) {
                    if (!this.map.hasLayer(entry.marker)) entry.marker.addTo(this.map);
                } else if (this.map.hasLayer(entry.marker)) {
                    this.map.removeLayer(entry.marker);
                }
            }
        };
        this.map.on('zoomend', updateLabels);
        updateLabels();

        return this;
    }

    setIndex(index) {
        this.trails = index?.trails || [];
        this.renderMarkers();
        return this;
    }

    setOverview(geojson) {
        if (!this.map) return this;
        if (this.overviewLayer) {
            this.map.removeLayer(this.overviewLayer);
            this.overviewLayer = null;
        }
        if (this.overviewCasing) {
            this.map.removeLayer(this.overviewCasing);
            this.overviewCasing = null;
        }
        this.overviewLayers.clear();
        this.overviewCasingLayers.clear();
        this.overviewStyles.clear();

        // A soft cream casing makes the network legible on busy topo tiles.
        this.overviewCasing = L.geoJSON(geojson, {
            renderer: L.canvas({ padding: 0.35 }),
            interactive: false,
            style: { color: '#ffffff', weight: 5.4, opacity: 0.42, lineCap: 'round', lineJoin: 'round' },
            onEachFeature: (feature, layer) => {
                const slug = feature.properties?.slug;
                if (slug) this.overviewCasingLayers.set(slug, layer);
            },
        }).addTo(this.map);

        this.overviewLayer = L.geoJSON(geojson, {
            renderer: L.canvas({ padding: 0.35 }),
            style: (feature) => this.styleFor(feature.properties?.slug),
            onEachFeature: (feature, layer) => {
                const slug = feature.properties?.slug;
                if (!slug) return;
                this.overviewLayers.set(slug, layer);
                this.overviewStyles.set(slug, this.baseOverviewStyle(slug));
                layer.bindTooltip(escapeHtml(feature.properties?.name || 'Staza'), {
                    className: 'hajkic-tooltip',
                    direction: 'top',
                    sticky: true,
                    opacity: 1,
                });
                layer.on('click', (event) => {
                    L.DomEvent.stopPropagation(event);
                    this.callbacks.onSelect?.(slug);
                });
                layer.on('add', () => {
                    const canvas = layer.getElement?.();
                    if (canvas) canvas.style.cursor = 'pointer';
                });
            },
        }).addTo(this.map);

        this.overviewLayer.bringToFront();
        this.selectedGroup?.eachLayer((child) => child.bringToFront?.());
        return this;
    }

    baseOverviewStyle(slug) {
        const trail = this.trails.find((item) => item.slug === slug);
        const color = DIFFICULTY_COLORS[trail?.difficulty] || DIFFICULTY_COLORS.nepoznato;
        return { color, weight: 2.8, opacity: 0.82, lineCap: 'round', lineJoin: 'round' };
    }

    styleFor(slug) {
        return this.overviewStyles.get(slug) || {
            color: DIFFICULTY_COLORS.nepoznato,
            weight: 2.8,
            opacity: 0.82,
        };
    }

    renderMarkers() {
        if (!this.cluster) return;
        this.cluster.clearLayers();
        this.markers.clear();
        this.markerData.clear();

        for (const trail of this.trails) {
            const point = trail.start_point;
            if (!Array.isArray(point) || point.length < 2) continue;
            const [lng, lat] = point;
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;

            const marker = L.marker([lat, lng], {
                icon: this.markerIcon(false),
                keyboard: true,
                title: trail.name,
                alt: trail.name,
            });
            marker.bindTooltip(escapeHtml(trail.name), {
                className: 'hajkic-tooltip',
                direction: 'top',
                offset: [0, -7],
                opacity: 1,
            });
            marker.on('click', (event) => {
                L.DomEvent.stopPropagation(event);
                this.callbacks.onSelect?.(trail.slug);
            });
            this.markers.set(trail.slug, marker);
            this.markerData.set(trail.slug, trail);
        }

        this.cluster.addLayers([...this.markers.values()]);
    }

    markerIcon(selected) {
        return L.divIcon({
            className: 'trail-marker-icon',
            html: `<span class="trail-marker ${selected ? 'is-selected' : ''}"></span>`,
            iconSize: selected ? [15, 15] : [12, 12],
            iconAnchor: selected ? [7.5, 7.5] : [6, 6],
        });
    }

    setFiltered(slugs) {
        if (!this.cluster) return;
        const allowed = slugs instanceof Set ? slugs : new Set(slugs || []);
        const toAdd = [];
        const toRemove = [];

        for (const [slug, marker] of this.markers) {
            const visible = allowed.has(slug);
            const inCluster = this.cluster.hasLayer(marker);
            if (visible && !inCluster) toAdd.push(marker);
            if (!visible && inCluster) toRemove.push(marker);

            const layer = this.overviewLayers.get(slug);
            const casing = this.overviewCasingLayers.get(slug);
            if (!layer) continue;
            const base = this.overviewStyles.get(slug) || {};
            if (visible) {
                layer.setStyle({ ...base, opacity: this.hoveredSlug === slug ? 1 : 0.9, weight: this.hoveredSlug === slug ? 5.5 : base.weight });
                casing?.setStyle({ opacity: 0.42, weight: 5.4, color: '#ffffff' });
            } else {
                layer.setStyle({ opacity: 0.08, weight: 1.2, color: '#9a9586' });
                casing?.setStyle({ opacity: 0.05 });
            }
        }

        if (toRemove.length) this.cluster.removeLayers(toRemove);
        if (toAdd.length) this.cluster.addLayers(toAdd);
    }

    hover(slug) {
        if (this.hoveredSlug && this.hoveredSlug !== slug) {
            const previous = this.overviewLayers.get(this.hoveredSlug);
            if (previous) {
                const base = this.overviewStyles.get(this.hoveredSlug) || previous.options;
                previous.setStyle({ ...base, weight: 2.8, opacity: 0.82 });
            }
        }

        this.hoveredSlug = slug || null;
        if (!slug) return;
        const layer = this.overviewLayers.get(slug);
        if (!layer) return;
        layer.setStyle({ color: '#bf6530', weight: 5.2, opacity: 1 });
        layer.bringToFront();
        if (this.selectedSlug !== slug) {
            this.overviewLayer?.bringToBack();
            this.selectedGroup?.eachLayer((child) => child.bringToFront?.());
        }
    }

    async select(trail, geojson) {
        if (!this.map) return;
        this.clearSelectionLayers();
        this.selectedSlug = trail.slug;

        const previousMarker = this.markers.get(trail.slug);
        if (previousMarker) previousMarker.setIcon(this.markerIcon(true));

        this.selectedGroup = L.layerGroup().addTo(this.map);
        const renderer = L.svg({ padding: 0.4 });

        if (geojson?.features?.length) {
            const lineFeatures = {
                type: 'FeatureCollection',
                features: geojson.features.filter((feature) => feature?.properties?.kind === 'trail'),
            };
            const pointFeatures = geojson.features.filter((feature) => feature?.properties?.kind === 'waypoint');

            L.geoJSON(lineFeatures, {
                renderer,
                interactive: false,
                style: { color: '#ffffff', weight: 8, opacity: 0.95, lineCap: 'round', lineJoin: 'round' },
            }).addTo(this.selectedGroup);

            L.geoJSON(lineFeatures, {
                renderer,
                interactive: false,
                style: { color: '#bf6530', weight: 4, opacity: 1, lineCap: 'round', lineJoin: 'round' },
            }).addTo(this.selectedGroup);

            for (const feature of pointFeatures) {
                const [lng, lat] = feature.geometry.coordinates;
                const props = feature.properties || {};
                const marker = L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: 'trail-point-icon',
                        html: '<span class="trail-point trail-point--waypoint"></span>',
                        iconSize: [11, 11],
                        iconAnchor: [5.5, 5.5],
                    }),
                    title: props.name || 'Tačka na GPX-u',
                });
                const holder = document.createElement('div');
                const name = document.createElement('strong');
                name.textContent = props.name || 'Tačka na GPX-u';
                holder.appendChild(name);
                if (props.elevation_m !== null && props.elevation_m !== undefined) {
                    const ele = document.createElement('div');
                    ele.textContent = `${Math.round(props.elevation_m)} m`;
                    holder.appendChild(ele);
                }
                if (props.description) {
                    const desc = document.createElement('div');
                    desc.textContent = props.description;
                    holder.appendChild(desc);
                }
                marker.bindPopup(holder, { closeButton: false, offset: [0, -4] });
                marker.addTo(this.selectedGroup);
            }
        }

        const start = trail.geometry?.start_point;
        const end = trail.geometry?.end_point;
        if (Array.isArray(start)) {
            L.marker([start[1], start[0]], {
                icon: L.divIcon({
                    className: 'trail-point-icon',
                    html: '<span class="trail-point"></span>',
                    iconSize: [18, 18],
                    iconAnchor: [9, 9],
                }),
                title: `Početak${trail.start ? ': ' + trail.start : ''}`,
                zIndexOffset: 500,
            }).bindTooltip(`Početak${trail.start ? ' · ' + escapeHtml(trail.start) : ''}`, {
                className: 'hajkic-tooltip',
                direction: 'top',
                offset: [0, -10],
                opacity: 1,
            }).addTo(this.selectedGroup);
        }
        if (Array.isArray(end)) {
            L.marker([end[1], end[0]], {
                icon: L.divIcon({
                    className: 'trail-point-icon',
                    html: '<span class="trail-point trail-point--end"></span>',
                    iconSize: [17, 17],
                    iconAnchor: [8.5, 8.5],
                }),
                title: `Kraj${trail.end ? ': ' + trail.end : ''}`,
                zIndexOffset: 500,
            }).bindTooltip(`Kraj${trail.end ? ' · ' + escapeHtml(trail.end) : ''}`, {
                className: 'hajkic-tooltip',
                direction: 'top',
                offset: [0, -10],
                opacity: 1,
            }).addTo(this.selectedGroup);
        }

        this.fitSelected();
        return this;
    }

    fitSelected() {
        const trail = this.trails.find((item) => item.slug === this.selectedSlug);
        const bbox = trail?.bbox;
        if (!this.map || !Array.isArray(bbox) || bbox.length !== 4) return;
        const bounds = L.latLngBounds([bbox[1], bbox[0]], [bbox[3], bbox[2]]);

        const mobile = isMobileView();
        const paddingTopLeft = mobile ? [18, 74] : [18, 18];
        const paddingBottomRight = mobile ? [18, 178] : [476, 24];

        this.map.fitBounds(bounds, {
            paddingTopLeft,
            paddingBottomRight,
            maxZoom: 15,
            animate: true,
        });
    }

    clearSelectionLayers() {
        if (this.profileMarker) {
            this.profileMarker.remove();
            this.profileMarker = null;
        }
        if (this.selectedGroup) {
            this.selectedGroup.remove();
            this.selectedGroup = null;
        }
        if (this.selectedSlug) {
            const marker = this.markers.get(this.selectedSlug);
            if (marker) marker.setIcon(this.markerIcon(false));
        }
        this.selectedSlug = null;
    }

    clearSelection() {
        this.clearSelectionLayers();
    }

    setProfilePoint(point) {
        if (!this.map) return;
        if (!point) {
            if (this.profileMarker) {
                this.profileMarker.remove();
                this.profileMarker = null;
            }
            return;
        }
        const latlng = [point.lat, point.lng];
        if (!this.profileMarker) {
            this.profileMarker = L.marker(latlng, {
                icon: L.divIcon({
                    className: 'trail-point-icon',
                    html: '<span class="profile-map-point"></span>',
                    iconSize: [12, 12],
                    iconAnchor: [6, 6],
                }),
                zIndexOffset: 900,
                interactive: false,
            }).addTo(this.map);
        } else {
            this.profileMarker.setLatLng(latlng);
        }
    }

    resetView() {
        if (!this.map) return;
        const bounds = this.config.bounds || [[42.05, 18.55], [46.25, 23.25]];
        this.map.fitBounds(L.latLngBounds(bounds), { padding: [18, 18] });
        if (isMobileView() && this.map.getZoom() < 7) {
            this.map.setZoom(7);
        }
    }

    setProvider(id) {
        if (!this.map || !this.providers.has(id)) return;
        for (const [key, layer] of this.providers) {
            if (key === id) {
                if (!this.map.hasLayer(layer)) layer.addTo(this.map);
                if (layer.bringToBack) layer.bringToBack();
            } else if (this.map.hasLayer(layer)) {
                this.map.removeLayer(layer);
            }
        }
        this.currentProvider = id;
        this.map.attributionControl?.setPrefix('');
    }

    renderLayerOptions(container) {
        if (!container) return;
        const providers = this.config.providers || [];
        container.innerHTML = providers.map((provider) => `
            <label class="layer-option">
                <input type="radio" name="basemap" value="${escapeHtml(provider.id)}" ${provider.id === this.currentProvider ? 'checked' : ''}>
                <span>${escapeHtml(provider.name)}</span>
                <small>${escapeHtml(provider.note ? 'demo' : provider.id === 'topo' ? 'reljef' : 'ulice')}</small>
            </label>
        `).join('');
        container.addEventListener('change', (event) => {
            const input = event.target.closest('input[name="basemap"]');
            if (input) this.setProvider(input.value);
        });
    }

    locate() {
        if (!navigator.geolocation) {
            showToast('Vaš pregledač ne podržava lokaciju.');
            this.callbacks.onLocationError?.();
            return;
        }

        this.callbacks.onLocating?.();
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const { latitude, longitude, accuracy } = position.coords;
                if (this.locationLayer) this.locationLayer.remove();
                this.locationLayer = L.layerGroup().addTo(this.map);
                L.circleMarker([latitude, longitude], {
                    radius: 8,
                    color: '#ffffff',
                    weight: 3,
                    fillColor: '#1d5a3b',
                    fillOpacity: 1,
                    className: 'user-location-marker',
                }).bindTooltip('Vaša lokacija', {
                    className: 'hajkic-tooltip',
                    direction: 'top',
                    offset: [0, -10],
                    opacity: 1,
                }).addTo(this.locationLayer);
                if (accuracy) {
                    L.circle([latitude, longitude], {
                        radius: Math.min(accuracy, 1500),
                        color: '#1f4a34',
                        weight: 1,
                        opacity: 0.45,
                        fillColor: '#1f4a34',
                        fillOpacity: 0.08,
                    }).addTo(this.locationLayer);
                }
                this.map.fitBounds(
                    L.latLngBounds([latitude, longitude], [latitude, longitude]).pad(0.02),
                    { maxZoom: 14 }
                );
                this.callbacks.onLocated?.({ lat: latitude, lng: longitude, accuracy });
            },
            (error) => {
                const messages = {
                    1: 'Pristup lokaciji je odbijen.',
                    2: 'Lokacija trenutno nije dostupna.',
                    3: 'Traženje lokacije je isteklo.',
                };
                showToast(messages[error.code] || 'Lokaciju nije moguće dobiti.');
                this.callbacks.onLocationError?.();
            },
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
        );
    }

    zoomIn() { this.map?.zoomIn(); }
    zoomOut() { this.map?.zoomOut(); }
}
