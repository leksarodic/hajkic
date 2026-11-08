import test from 'node:test';
import assert from 'node:assert/strict';

import {
    emptyFilters,
    filterTrails,
    trailMatches,
    parseFilters,
    filtersToParams,
    countActiveFilters,
    removeFilter,
    activeFilterChips,
} from '../../assets/js/lib/filters.js';

const trails = [
    {
        slug: 'zlatibor-5',
        name: 'Staza br. 5: Jokina ćuprija – Konjoder',
        code: '5-12-9',
        region: 'Jugozapadna Srbija',
        mountain: 'Zlatibor',
        difficulty: 'laka',
        distance: { official_km: 14.5, calculated_km: 14.5 },
        elevation: { official_gain_m: 550, calculated_gain_m: 540 },
        gpx: { available: true, has_elevation: true },
        search: 'staza br 5 jokina cuprija konjoder zlatibor jugozapadna srbija 5-12-9',
    },
    {
        slug: 'fruska-1',
        name: 'Fruškogorska staza 1',
        code: '1-1-1',
        region: 'Vojvodina',
        mountain: 'Fruška gora',
        difficulty: 'srednje_teska',
        distance: { official_km: 22, calculated_km: 21.8 },
        elevation: { official_gain_m: null, calculated_gain_m: 760 },
        gpx: { available: true, has_elevation: true },
        search: 'fruskogorska staza 1 fruska gora vojvodina 1-1-1',
    },
    {
        slug: 'suva-2',
        name: 'Trem preko Devojačkog groba',
        code: '7-3-2',
        region: 'Jugoistočna Srbija',
        mountain: 'Suva planina',
        difficulty: 'zahtevna',
        distance: { official_km: null, calculated_km: 7.2 },
        elevation: { official_gain_m: null, calculated_gain_m: 410 },
        gpx: { available: false, has_elevation: false },
        search: 'trem preko devojackog groba suva planina jugoistocna srbija 7-3-2',
    },
    {
        slug: 'beograd-1',
        name: 'Avala, staza 1',
        code: '3-1-1',
        region: 'Šumadija',
        mountain: 'Avala',
        difficulty: 'laka',
        distance: { official_km: 3.4, calculated_km: 3.2 },
        elevation: { official_gain_m: 120, calculated_gain_m: 130 },
        gpx: { available: false, has_elevation: false },
        search: 'avala staza 1 sumadija 3-1-1',
    },
];

test('pretraga je neosetljiva na dijakritike', () => {
    const filters = { ...emptyFilters(), q: 'cuprija' };
    const result = filterTrails(trails, filters);
    assert.equal(result.length, 1);
    assert.equal(result[0].slug, 'zlatibor-5');
});

test('pretraga radi po planini, regionu, oznaci i klubu', () => {
    assert.equal(filterTrails(trails, { ...emptyFilters(), q: 'Fruška' })[0].slug, 'fruska-1');
    assert.equal(filterTrails(trails, { ...emptyFilters(), q: 'Vojvodina' })[0].slug, 'fruska-1');
    assert.equal(filterTrails(trails, { ...emptyFilters(), q: '7-3-2' })[0].slug, 'suva-2');
});

test('filter regiona, planine i težine', () => {
    assert.equal(filterTrails(trails, { ...emptyFilters(), region: 'Šumadija' }).length, 1);
    assert.equal(filterTrails(trails, { ...emptyFilters(), mountain: 'Suva planina' }).length, 1);
    assert.equal(filterTrails(trails, { ...emptyFilters(), difficulty: ['laka'] }).length, 2);
    assert.equal(filterTrails(trails, { ...emptyFilters(), difficulty: ['laka', 'zahtevna'] }).length, 3);
});

test('filteri dužine koriste zvaničnu, pa izračunatu dužinu', () => {
    const short = filterTrails(trails, { ...emptyFilters(), dist: '0-5' }).map((trail) => trail.slug);
    assert.deepEqual(short, ['beograd-1']);
    const medium = filterTrails(trails, { ...emptyFilters(), dist: '5-10' }).map((trail) => trail.slug);
    assert.deepEqual(medium, ['suva-2']);
    const long = filterTrails(trails, { ...emptyFilters(), dist: '10-20' }).map((trail) => trail.slug);
    assert.deepEqual(long, ['zlatibor-5']);
});

test('filteri uspona koriste zvanični, pa izračunat uspon', () => {
    assert.deepEqual(
        filterTrails(trails, { ...emptyFilters(), elev: '0-300' }).map((trail) => trail.slug),
        ['beograd-1']
    );
    assert.deepEqual(
        filterTrails(trails, { ...emptyFilters(), elev: '300-700' }).map((trail) => trail.slug),
        ['zlatibor-5', 'suva-2']
    );
    assert.deepEqual(
        filterTrails(trails, { ...emptyFilters(), elev: '700-1200' }).map((trail) => trail.slug),
        ['fruska-1']
    );
});

test('GPX filter i kombinacija filtera', () => {
    assert.deepEqual(
        filterTrails(trails, { ...emptyFilters(), gpx: 'yes' }).map((trail) => trail.slug),
        ['zlatibor-5', 'fruska-1']
    );
    assert.deepEqual(
        filterTrails(trails, { ...emptyFilters(), gpx: 'no' }).map((trail) => trail.slug),
        ['suva-2', 'beograd-1']
    );

    const combined = filterTrails(trails, {
        ...emptyFilters(),
        q: 'staza',
        region: 'Vojvodina',
        difficulty: ['srednje_teska'],
        gpx: 'yes',
    });
    assert.deepEqual(combined.map((trail) => trail.slug), ['fruska-1']);
});

test('rezultati sa pogotkom u nazivu idu ispred slabijih pogodaka', () => {
    const result = filterTrails(trails, { ...emptyFilters(), q: 'avala' });
    assert.equal(result[0].slug, 'beograd-1');
});

test('URL parametri se parsiraju i serijalizuju bez gubitaka', () => {
    const filters = parseFilters(new URLSearchParams('q=Trem&region=Jugoisto%C4%8Dna+Srbija&difficulty=laka,zahtevna&gpx=no&dist=5-10'));
    assert.equal(filters.q, 'Trem');
    assert.equal(filters.region, 'Jugoistočna Srbija');
    assert.deepEqual(filters.difficulty, ['laka', 'zahtevna']);
    assert.equal(filters.gpx, 'no');
    assert.equal(filters.dist, '5-10');

    const serialized = filtersToParams(filters).toString();
    assert.match(serialized, /q=Trem/);
    assert.match(serialized, /difficulty=laka%2Czahtevna/);
    assert.match(serialized, /gpx=no/);
});

test('aktivni filteri se broje i uklanjaju pojedinačno', () => {
    const filters = {
        ...emptyFilters(),
        q: 'trem',
        region: 'Jugoistočna Srbija',
        difficulty: ['laka', 'zahtevna'],
        gpx: 'no',
    };
    assert.equal(countActiveFilters(filters), 5);

    let next = removeFilter(filters, 'difficulty:laka');
    assert.deepEqual(next.difficulty, ['zahtevna']);
    next = removeFilter(next, 'gpx');
    assert.equal(next.gpx, '');
    next = removeFilter(next, 'q');
    assert.equal(next.q, '');
});

test('chip prikazuje čitljive labele', () => {
    const chips = activeFilterChips(
        { ...emptyFilters(), region: 'Vojvodina', dist: '20-30', gpx: 'yes' },
        { regions: [{ key: 'Vojvodina', label: 'Vojvodina' }] }
    );
    assert.deepEqual(chips.map((chip) => chip.label), ['Vojvodina', '20–30 km', 'Sa GPX-om']);
});

test('nema filtera znači sve staze', () => {
    const filters = emptyFilters();
    assert.equal(filterTrails(trails, filters).length, trails.length);
    assert.ok(trailMatches(trails[0], filters));
});
