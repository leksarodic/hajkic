import {
    escapeHtml,
    formatDate,
    formatDistance,
    formatDuration,
    formatGain,
    formatInteger,
    formatNumber,
    trailPlace,
} from './lib/format.js';
import { buildProfile } from './lib/elevation.js';
import { ElevationProfile } from './profile.js';

function paragraphs(text) {
    if (!text) return '';
    return String(text)
        .split(/\n{2,}/)
        .map((block) => `<p>${escapeHtml(block).replace(/\n/g, '<br>')}</p>`)
        .join('');
}

function stat(label, value, sub = '', options = {}) {
    const valueClass = options.text ? 'detail-stat__value detail-stat__value--text' : 'detail-stat__value';
    return `
        <div class="detail-stat">
            <span class="detail-stat__label">${escapeHtml(label)}</span>
            <span class="${valueClass}">${value}</span>
            ${sub ? `<span class="detail-stat__sub ${options.calculated ? 'detail-stat__sub--calc' : ''}">${sub}</span>` : ''}
        </div>
    `;
}

function missingStat(label) {
    return stat(label, '—', 'nije dostupno');
}

function distanceStats(trail) {
    const distance = formatDistance(trail);
    if (distance.value === null) return missingStat('Dužina');

    const official = trail.distance?.official_km;
    const calculated = trail.distance?.calculated_km;
    let sub = 'zvanično, PSS';
    let calculatedStyle = false;
    if (official === null || official === undefined) {
        sub = 'izračunato iz GPX-a';
        calculatedStyle = true;
    } else if (calculated !== null && calculated !== undefined && Math.abs(official - calculated) >= 0.1) {
        sub = `GPX: ${formatNumber(calculated, 1)} km`;
        calculatedStyle = true;
    }

    return stat('Dužina', `${escapeHtml(distance.text)} <span class="trail-stat__unit">km</span>`, sub, { calculated: calculatedStyle });
}

function durationStat(trail) {
    const text = trail.duration?.official_text || formatDuration(trail.duration?.official_minutes);
    if (!text) return missingStat('Vreme obilaska');
    return stat('Vreme obilaska', escapeHtml(text), 'procena, PSS', { text: true });
}

function elevationStat(trail, kind) {
    const isGain = kind === 'gain';
    const official = isGain ? trail.elevation?.official_gain_m : trail.elevation?.official_loss_m;
    const calculated = isGain ? trail.elevation?.calculated_gain_m : trail.elevation?.calculated_loss_m;
    const value = official ?? calculated;
    const label = isGain ? 'Ukupan uspon' : 'Ukupan spust';

    if (value === null || value === undefined) return missingStat(label);

    let sub = 'zvanično, PSS';
    let calculatedStyle = false;
    if (official === null || official === undefined) {
        sub = 'izračunato iz GPX-a';
        calculatedStyle = true;
    } else if (calculated !== null && calculated !== undefined && Math.abs(official - calculated) >= 15) {
        sub = `GPX: ${formatInteger(calculated)} m`;
        calculatedStyle = true;
    }

    return stat(label, `${formatInteger(value)} <span class="trail-stat__unit">m</span>`, sub, { calculated: calculatedStyle });
}

function fact(label, value, options = {}) {
    if (value === null || value === undefined || value === '') return '';
    const html = options.html ? value : escapeHtml(value);
    return `
        <div class="detail-fact ${options.stack ? 'detail-fact--stack' : ''}">
            <dt>${escapeHtml(label)}</dt>
            <dd>${html}</dd>
        </div>
    `;
}

function section(title, body) {
    if (!body) return '';
    return `<section class="detail-section"><h3>${escapeHtml(title)}</h3>${body}</section>`;
}

/**
 * Render the complete trail detail panel.
 */
export function renderDetail(container, trail, geojson, options = {}) {
    const place = trailPlace(trail);
    const code = trail.code ? `Oznaka ${trail.code}` : 'Bez oznake';
    const difficulty = trail.difficulty?.label || 'Nije navedeno';
    const difficultyKey = trail.difficulty?.key || 'nepoznato';
    const gpx = trail.gpx || {};
    const sourceUrl = trail.source?.canonical_url || trail.source?.url || '';
    const checked = formatDate(trail.source?.last_checked_at);
    const updated = formatDate(trail.source?.updated_at);

    const badges = [
        `<span class="badge badge--${escapeHtml(difficultyKey)}">${escapeHtml(difficulty)}</span>`,
        gpx.available
            ? `<span class="badge badge--laka">GPX</span>`
            : `<span class="badge badge--nepoznato">bez GPX-a</span>`,
        gpx.has_elevation ? `<span class="badge badge--laka">visinski profil</span>` : '',
    ].filter(Boolean).join('');

    const facts = [
        fact('Početak', trail.start),
        fact('Kraj', trail.end),
        fact('Oznaka', trail.code),
        fact('Planina', trail.mountain),
        fact('Region', trail.region),
        fact('Karta', trail.map_sheet),
        fact('Mapa', trail.map_reference),
    ].join('');

    const club = trail.club;
    let clubBody = '';
    if (club?.name) {
        const link = club.url
            ? `<a href="${escapeHtml(club.url)}" target="_blank" rel="noopener">${escapeHtml(club.name)}</a>`
            : escapeHtml(club.name);
        clubBody = `<p>${link}</p>`;
        if (club.contact) {
            clubBody += `<div class="detail-source" style="white-space:pre-line">${escapeHtml(club.contact)}</div>`;
        }
        if (Array.isArray(club.clubs) && club.clubs.length > 1) {
            clubBody += '<ul>' + club.clubs.slice(1).map((item) => `<li>${escapeHtml(item.name)}</li>`).join('') + '</ul>';
        }
    }

    const additional = Object.entries(trail.additional || {})
        .filter(([, value]) => value)
        .map(([label, value]) => fact(label, value))
        .join('');

    const qualityIssues = trail.data_quality?.issues || [];
    const hasMissingDescription = qualityIssues.includes('missing_description');
    const gpxError = gpx.error || null;
    const gpxStatus = gpx.status || 'missing';

    let gpxNote = '';
    if (!gpx.available) {
        if (gpxStatus === 'invalid') {
            gpxNote = `<p>PSS GPX datoteka postoji, ali nije upotrebljiva (nije validan GPX). Prikazana je samo dostupna geometrija iz drugih izvora — trenutno je nema.</p>`;
        } else if (gpxStatus === 'error' || gpxStatus === 'stale') {
            gpxNote = `<p>GPX trenutno nije moguće preuzeti${gpxError ? `: ${escapeHtml(gpxError)}` : ''}.</p>`;
        } else {
            gpxNote = `<p>Za ovu stazu PSS nije objavio GPX datoteku. Zvanična dužina i opis su prikazani bez izmišljene geometrije.</p>`;
        }
    }

    const downloadUrl = gpx.download?.url || gpx.source_url;
    const downloadMode = gpx.download?.mode || 'source';
    const downloadButton = gpx.available && downloadUrl
        ? `<a class="btn btn--primary" href="${escapeHtml(downloadUrl)}" target="_blank" rel="noopener" data-action="download">
                ${hajkicIcon('download')} Preuzmi GPX${downloadMode === 'source' ? ' (PSS)' : ''}
           </a>`
        : `<span class="btn btn--ghost" aria-disabled="true">${hajkicIcon('gpx')} GPX nije dostupan</span>`;

    container.innerHTML = `
        <div class="detail-head">
            <button type="button" class="detail-close" data-action="close" aria-label="Zatvori detalje">${hajkicIcon('close')}</button>
            <p class="detail-eyebrow">${escapeHtml(code)}${place ? ' · ' + escapeHtml(place) : ''}</p>
            <h2 class="detail-title" id="detail-title" tabindex="-1">${escapeHtml(trail.name)}</h2>
            ${place ? `<p class="detail-place">${escapeHtml(place)}</p>` : ''}
            <div class="detail-badges">
                ${badges}
                ${trail.data_quality?.level === 'partial' ? `<span class="detail-quality">${hajkicIcon('info')} delimični podaci</span>` : ''}
            </div>
        </div>

        <div class="detail-stats">
            ${distanceStats(trail)}
            ${durationStat(trail)}
            ${elevationStat(trail, 'gain')}
            ${elevationStat(trail, 'loss')}
        </div>

        <section class="detail-section" data-profile-section>
            <div data-profile-host></div>
        </section>

        ${section('Opis staze', hasMissingDescription
            ? '<p class="detail-muted">PSS nije objavio opis ove staze.</p>'
            : paragraphs(trail.description))}

        ${facts ? section('Osnovni podaci', `<dl class="detail-facts">${facts}</dl>`) : ''}

        ${section('Značajni lokaliteti', paragraphs(trail.landmarks))}
        ${section('Markacija i uređenost', `
            ${trail.marking ? `<dl class="detail-facts">${fact('Markacija', trail.marking)}${fact('Uređenost', trail.maintenance)}</dl>` : (trail.maintenance ? `<dl class="detail-facts">${fact('Uređenost', trail.maintenance)}</dl>` : '')}
        `)}
        ${section('Klub i kontakt', clubBody)}
        ${section('Smeštaj i snadbevenost', paragraphs(trail.accommodation))}
        ${section('Napomene', paragraphs(trail.notes))}
        ${additional ? section('Dodatni podaci', `<dl class="detail-facts">${additional}</dl>`) : ''}
        ${Array.isArray(trail.legend) && trail.legend.length ? section('Skraćenice sa PSS stranice', `<ul>${trail.legend.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>`) : ''}

        ${section('O podacima', `
            ${gpxNote}
            <div class="detail-source">
                <p><strong>Izvor:</strong> ${sourceUrl ? `<a href="${escapeHtml(sourceUrl)}" target="_blank" rel="noopener">Planinarski savez Srbije</a>` : 'Planinarski savez Srbije'}</p>
                ${updated ? `<p><strong>Poslednja izmena na PSS-u:</strong> ${escapeHtml(updated)}</p>` : ''}
                ${checked ? `<p><strong>Hajkić proverio:</strong> ${escapeHtml(checked)}</p>` : ''}
                <p>Zvanične vrednosti dolaze sa PSS stranice. Vrednosti označene sa „GPX” ili „≈” izračunate su iz geometrije i nisu zvanične. <a href="${escapeHtml(window.HAJKIC?.base || '/')}o-projektu">Više o podacima</a>.</p>
                <p>AR pogled koristi kameru, GPS i kompas telefona. Radio samo preko HTTPS-a i nije zamena za mapu.</p>
                <p>Hajkić nije zvanični proizvod PSS-a. Proverite uslove na terenu pre polaska.</p>
            </div>
        `)}

    `;

    const actionsHost = options.actionsHost;
    if (actionsHost) {
        actionsHost.innerHTML = `
            <div class="detail-actions">
                ${downloadButton}
                <button type="button" class="btn btn--ghost" data-action="fit">${hajkicIcon('pin')} Prikaži na mapi</button>
                <a class="btn btn--ghost" href="${escapeHtml(sourceUrl || '#')}" target="_blank" rel="noopener">${hajkicIcon('external')} PSS stranica</a>
                <button type="button" class="btn btn--ghost" data-action="ar">${hajkicIcon('camera')} AR pogled</button>
                <button type="button" class="btn btn--ghost" data-action="copy">${hajkicIcon('copy')} Kopiraj link</button>
                <button type="button" class="btn btn--ghost" data-action="share">${hajkicIcon('share')} Podeli</button>
            </div>
        `;
    }

    const profileHost = container.querySelector('[data-profile-host]');
    const sectionHost = container.querySelector('[data-profile-section]');
    let profile = null;
    if (profileHost) {
        const built = buildProfile(geojson);
        if (built.points.length) {
            profile = new ElevationProfile(profileHost, {
                onHover: (point) => options.onProfileHover?.(point),
            });
            profile.render(built, {
                ascentText: trail.elevation?.calculated_gain_m !== null && trail.elevation?.calculated_gain_m !== undefined
                    ? `${formatInteger(trail.elevation.calculated_gain_m)} m`
                    : null,
                descent: trail.elevation?.calculated_loss_m,
            });
        } else {
            sectionHost?.remove();
        }
    }

    if (!geojson?.features?.length) {
        sectionHost?.remove();
    }

    return {
        profile,
        destroy() {
            profile?.destroy();
        },
    };
}

/**
 * Small icon subset used inside JS-rendered detail markup.
 * Mirrors includes/icons.php; duplication is intentional to avoid an extra
 * request for five glyphs.
 */
function hajkicIcon(name) {
    const paths = {
        close: '<path d="m6 6 12 12M18 6 6 18"/>',
        download: '<path d="M12 4v11m0 0 4-4m-4 4-4-4M5 20h14"/>',
        external: '<path d="M14 5h5v5M19 5l-8 8"/><path d="M19 14v5H5V5h5"/>',
        pin: '<path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"/>',
        share: '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.3M8.2 13.2l7.6 4.3"/>',
        gpx: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 15h6"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8" r=".6" fill="currentColor" stroke="none"/>',
        camera: '<path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.2"/>',
    };
    return `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.info}</svg>`;
}
