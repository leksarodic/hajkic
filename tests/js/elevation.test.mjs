import test from 'node:test';
import assert from 'node:assert/strict';

import { buildProfile, sampleProfile, nearestPoint } from '../../assets/js/lib/elevation.js';

function feature(segment, coordinates, elevations, track = 0) {
    return {
        type: 'Feature',
        properties: { kind: 'trail', track, segment, elevations_m: elevations },
        geometry: { type: 'LineString', coordinates },
    };
}

const geojson = {
    type: 'FeatureCollection',
    features: [
        feature(0, [[20.0, 44.0], [20.001, 44.0], [20.001, 44.001]], [100, 150, 200]),
        feature(1, [[20.002, 44.0], [20.003, 44.0]], [200, 150]),
        {
            type: 'Feature',
            properties: { kind: 'waypoint', name: 'Vrh', elevation_m: 200 },
            geometry: { type: 'Point', coordinates: [20.001, 44.001] },
        },
    ],
};

test('gradi profil iz više segmenata bez dodavanja praznina u distancu', () => {
    const profile = buildProfile(geojson);
    assert.equal(profile.points.length, 5);
    assert.equal(profile.points.filter((point) => point.break).length, 2);
    assert.ok(profile.totalM > 200 && profile.totalM < 350);
    assert.equal(profile.min, 100);
    assert.equal(profile.max, 200);
    assert.equal(profile.hasElevation, true);
});

test('čuva distance i visinu za hover i pronalazi najbližu tačku', () => {
    const profile = buildProfile(geojson);
    assert.deepEqual(
        profile.points.map((point) => point.ele),
        [100, 150, 200, 200, 150]
    );

    const middle = profile.totalM / 2;
    const nearest = nearestPoint(profile, middle);
    assert.ok(nearest);
    assert.ok(Math.abs(nearest.d - middle) <= Math.max(...profile.points.map((point) => Math.abs(point.d - middle))));
});

test('profil bez visina je eksplicitno neupotrebljiv', () => {
    const profile = buildProfile({
        type: 'FeatureCollection',
        features: [feature(0, [[20.0, 44.0], [20.001, 44.0]], [null, null])],
    });
    assert.equal(profile.hasElevation, false);
    assert.equal(profile.min, null);
    assert.equal(profile.points.length, 2);
});

test('sampleProfile ograničava broj tačaka i čuva kraj', () => {
    const coordinates = [];
    const elevations = [];
    for (let i = 0; i < 2000; i++) {
        coordinates.push([20 + i * 0.000001, 44]);
        elevations.push(100 + (i % 50));
    }
    const profile = buildProfile({
        type: 'FeatureCollection',
        features: [feature(0, coordinates, elevations)],
    });
    const sampled = sampleProfile(profile, 400);
    assert.ok(sampled.length <= 401);
    assert.equal(sampled[0], profile.points[0]);
    assert.equal(sampled[sampled.length - 1], profile.points[profile.points.length - 1]);
});

test('neispravne koordinate se preskaču', () => {
    const profile = buildProfile({
        type: 'FeatureCollection',
        features: [feature(0, [[20.0, 44.0], [null, 44.0], [20.002, 44.0]], [100, 120, 140])],
    });
    assert.equal(profile.points.length, 2);
});
