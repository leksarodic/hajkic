import { haversine } from './format.js';

/**
 * Build one continuous profile from a GeoJSON FeatureCollection.
 * Segment gaps add no fake distance: they are marked as breaks instead.
 */
export function buildProfile(geojson) {
    const features = (geojson?.features || [])
        .filter((feature) => feature?.properties?.kind === 'trail' && feature.geometry?.type === 'LineString')
        .sort((a, b) => {
            const track = (a.properties.track ?? 0) - (b.properties.track ?? 0);
            if (track !== 0) return track;
            return (a.properties.segment ?? 0) - (b.properties.segment ?? 0);
        });

    const points = [];
    let total = 0;
    let previous = null;
    let validElevations = 0;

    for (const feature of features) {
        const coordinates = feature.geometry.coordinates || [];
        const elevations = feature.properties?.elevations_m || [];
        let firstOfSegment = true;

        for (let index = 0; index < coordinates.length; index++) {
            const [lng, lat] = coordinates[index];
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;

            if (previous && !firstOfSegment) {
                total += haversine(previous[1], previous[0], lat, lng);
            }

            const rawElevation = elevations[index];
            const elevation = rawElevation === null || rawElevation === undefined || !Number.isFinite(Number(rawElevation))
                ? null
                : Number(rawElevation);
            if (elevation !== null) validElevations++;

            points.push({
                d: total,
                ele: elevation,
                lat,
                lng,
                break: firstOfSegment,
            });

            previous = [lng, lat];
            firstOfSegment = false;
        }
    }

    const valid = points.filter((point) => point.ele !== null);
    let min = null;
    let max = null;
    if (valid.length) {
        min = Math.min(...valid.map((point) => point.ele));
        max = Math.max(...valid.map((point) => point.ele));
    }

    const hasElevation = valid.length >= 5 && (max === null || min === null || max - min >= 5);

    return {
        points,
        totalM: total,
        min,
        max,
        hasElevation,
        validCount: valid.length,
    };
}

/**
 * Downsample for drawing while keeping first/last and null gaps.
 */
export function sampleProfile(profile, maxPoints = 480) {
    const points = profile?.points || [];
    if (points.length <= maxPoints) return points;

    const step = points.length / maxPoints;
    const sampled = [];
    for (let i = 0; i < maxPoints; i++) {
        sampled.push(points[Math.min(points.length - 1, Math.floor(i * step))]);
    }
    const last = points[points.length - 1];
    if (sampled[sampled.length - 1] !== last) sampled.push(last);
    return sampled;
}

export function nearestPoint(profile, distanceMeters) {
    const points = profile?.points || [];
    if (!points.length) return null;
    const target = Math.max(0, Math.min(profile.totalM, Number(distanceMeters) || 0));

    let low = 0;
    let high = points.length - 1;
    while (low < high) {
        const mid = Math.floor((low + high) / 2);
        if (points[mid].d < target) low = mid + 1;
        else high = mid;
    }

    const current = points[low];
    const previous = points[Math.max(0, low - 1)];
    return Math.abs(previous.d - target) <= Math.abs(current.d - target) ? previous : current;
}
