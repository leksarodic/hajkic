/**
 * Formatting helpers shared by server and browser UI.
 * Everything is Serbian Latin and uses a comma decimal mark.
 */

export function fold(value) {
    return String(value ?? '')
        .toLocaleLowerCase('sr-Latn')
        .replace(/[čć]/g, 'c')
        .replace(/š/g, 's')
        .replace(/ž/g, 'z')
        .replace(/đ/g, 'dj');
}

export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

export function formatNumber(value, decimals = 1) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return null;
    const fixed = Number(value).toFixed(decimals);
    let [integer, fraction = ''] = fixed.split('.');
    if (integer === '-0') integer = '0';
    integer = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    fraction = fraction.replace(/0+$/, '');
    return fraction ? `${integer},${fraction}` : integer;
}

export function formatInteger(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return null;
    return Math.round(Number(value)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

export function formatDistance(trail) {
    const official = trail?.distance?.official_km ?? null;
    const calculated = trail?.distance?.calculated_km ?? null;
    const value = official ?? calculated;
    return {
        value,
        text: value === null ? null : formatNumber(value, 1),
        calculated: official === null && calculated !== null,
    };
}

export function formatGain(trail) {
    const official = trail?.elevation?.official_gain_m ?? null;
    const calculated = trail?.elevation?.calculated_gain_m ?? null;
    const value = official ?? calculated;
    return {
        value,
        text: value === null ? null : formatInteger(value),
        calculated: official === null && calculated !== null,
    };
}

export function formatDuration(minutes) {
    if (minutes === null || minutes === undefined || Number(minutes) <= 0) return null;
    const total = Math.round(Number(minutes));
    const hours = Math.floor(total / 60);
    const rest = total % 60;
    if (hours === 0) return `${rest} min`;
    return `${hours} h${rest > 0 ? ` ${String(rest).padStart(2, '0')} min` : ''}`;
}

function pluralStaza(n) {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return 'staza';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'staze';
    return 'staza';
}

export function resultLabel(n) {
    return `${formatInteger(n) ?? 0} ${pluralStaza(Number(n))}`;
}

export function trailPlace(trail) {
    const parts = [];
    if (trail?.mountain) parts.push(trail.mountain);
    if (trail?.region && trail.region !== trail.mountain) parts.push(trail.region);
    return parts.join(' · ');
}

export function formatDate(iso) {
    if (!iso) return null;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return null;
    return new Intl.DateTimeFormat('sr-Latn-RS', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(date);
}

export function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

export function haversine(aLat, aLon, bLat, bLon) {
    const radius = 6371008.8;
    const toRad = (deg) => (deg * Math.PI) / 180;
    const dLat = toRad(bLat - aLat);
    const dLon = toRad(bLon - aLon);
    const lat1 = toRad(aLat);
    const lat2 = toRad(bLat);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
    return 2 * radius * Math.atan2(Math.sqrt(h), Math.sqrt(Math.max(0, 1 - h)));
}

export function bearingDegrees(aLat, aLon, bLat, bLon) {
    const toRad = (deg) => (deg * Math.PI) / 180;
    const toDeg = (rad) => (rad * 180) / Math.PI;
    const phi1 = toRad(aLat);
    const phi2 = toRad(bLat);
    const dLon = toRad(bLon - aLon);
    const y = Math.sin(dLon) * Math.cos(phi2);
    const x = Math.cos(phi1) * Math.sin(phi2) - Math.sin(phi1) * Math.cos(phi2) * Math.cos(dLon);
    return (toDeg(Math.atan2(y, x)) + 360) % 360;
}

export function formatShortDistance(meters) {
    if (meters === null || meters === undefined || Number.isNaN(Number(meters))) return null;
    if (meters < 950) return `${Math.round(meters / 10) * 10} m`;
    return `${formatNumber(meters / 1000, 1)} km`;
}

export function compassLabel(degrees) {
    const labels = ['S', 'SI', 'I', 'JI', 'J', 'JZ', 'Z', 'SZ'];
    return labels[Math.round((((degrees % 360) + 360) % 360) / 45) % 8];
}
