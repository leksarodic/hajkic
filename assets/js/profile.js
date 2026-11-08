import { formatInteger, formatNumber } from './lib/format.js';
import { nearestPoint } from './lib/elevation.js';

const SVG_W = 440;
const SVG_H = 168;
const MARGIN = { top: 14, right: 12, bottom: 24, left: 40 };

export class ElevationProfile {
    constructor(container, options = {}) {
        this.container = container;
        this.onHover = options.onHover || (() => {});
        this.profile = null;
        this.svg = null;
        this.tooltip = null;
        this.crosshair = null;
        this.dot = null;
        this.hiddenText = null;
        this.point = null;
        this.handleMove = this.handleMove.bind(this);
        this.handleLeave = this.handleLeave.bind(this);
    }

    render(profile, meta = {}) {
        this.destroy();
        this.profile = profile;

        if (!profile?.hasElevation || !profile.points?.length) {
            this.container.innerHTML = `
                <div class="profile-card">
                    <div class="profile-head">
                        <div>
                            <p class="profile-title">Visinski profil</p>
                            <p class="profile-meta">nema upotrebljivih visinskih podataka</p>
                        </div>
                    </div>
                    <p class="profile-empty">GPX za ovu stazu ne sadrži pouzdane visine, pa profil nije prikazan. Dužina i geometrija su i dalje dostupne.</p>
                </div>
            `;
            return;
        }

        const min = profile.min;
        const max = profile.max;
        const span = Math.max(20, max - min);
        const yMin = Math.floor((min - span * 0.08) / 10) * 10;
        const yMax = Math.ceil((max + span * 0.08) / 10) * 10;
        const plotW = SVG_W - MARGIN.left - MARGIN.right;
        const plotH = SVG_H - MARGIN.top - MARGIN.bottom;
        const total = Math.max(1, profile.totalM);

        const x = (distance) => MARGIN.left + (distance / total) * plotW;
        const y = (elevation) => MARGIN.top + ((yMax - elevation) / (yMax - yMin)) * plotH;

        const grid = [];
        const yTicks = 4;
        for (let i = 0; i <= yTicks; i++) {
            const elevation = yMin + ((yMax - yMin) * i) / yTicks;
            const yy = y(elevation);
            grid.push(`<line class="profile-grid" x1="${MARGIN.left}" y1="${yy.toFixed(1)}" x2="${SVG_W - MARGIN.right}" y2="${yy.toFixed(1)}"/>`);
            grid.push(`<text class="profile-axis" x="${MARGIN.left - 8}" y="${(yy + 3).toFixed(1)}" text-anchor="end">${formatInteger(elevation)}</text>`);
        }

        const xTicks = 5;
        for (let i = 0; i <= xTicks; i++) {
            const distance = (total * i) / xTicks;
            const xx = x(distance);
            grid.push(`<line class="profile-grid" x1="${xx.toFixed(1)}" y1="${MARGIN.top}" x2="${xx.toFixed(1)}" y2="${MARGIN.top + plotH}" opacity=".45"/>`);
            const label = distance === 0 ? '0' : formatNumber(distance / 1000, 1);
            grid.push(`<text class="profile-axis" x="${xx.toFixed(1)}" y="${SVG_H - 8}" text-anchor="${i === 0 ? 'start' : i === xTicks ? 'end' : 'middle'}">${label}${i === xTicks ? ' km' : ''}</text>`);
        }

        const sampled = sample(profile.points, 520);
        const runs = [];
        let current = [];
        let previous = null;
        for (const point of sampled) {
            const gap = point.break || point.ele === null || (previous && point.d - previous.d > Math.max(120, total * 0.02));
            if (gap && current.length) {
                runs.push(current);
                current = [];
            }
            if (point.ele !== null) current.push(point);
            previous = point;
        }
        if (current.length) runs.push(current);

        const linePath = runs.map((run) => run.map((point, index) => {
            const command = index === 0 ? 'M' : 'L';
            return `${command}${x(point.d).toFixed(1)},${y(point.ele).toFixed(1)}`;
        }).join(' ')).filter(Boolean).join(' ');

        const areaPath = runs.map((run) => {
            if (!run.length) return '';
            const first = run[0];
            const last = run[run.length - 1];
            const top = run.map((point, index) => `${index === 0 ? 'M' : 'L'}${x(point.d).toFixed(1)},${y(point.ele).toFixed(1)}`).join(' ');
            return `${top} L${x(last.d).toFixed(1)},${(MARGIN.top + plotH).toFixed(1)} L${x(first.d).toFixed(1)},${(MARGIN.top + plotH).toFixed(1)} Z`;
        }).filter(Boolean).join(' ');

        const minText = formatInteger(profile.min);
        const maxText = formatInteger(profile.max);
        const totalText = formatNumber(profile.totalM / 1000, 1);
        const ascent = meta.ascentText;

        this.container.innerHTML = `
            <div class="profile-card">
                <div class="profile-head">
                    <div>
                        <p class="profile-title">Visinski profil</p>
                        <p class="profile-meta">${minText}–${maxText} m · ${totalText} km</p>
                    </div>
                    <span class="profile-calc" title="Vrednosti su izračunate iz GPX geometrije">iz GPX-a</span>
                </div>
                <div class="profile-chart" data-profile-chart>
                    <svg viewBox="0 0 ${SVG_W} ${SVG_H}" role="img" aria-label="Visinski profil staze, dužina ${totalText} kilometara, od ${minText} do ${maxText} metara nadmorske visine">
                        ${grid.join('')}
                        <path class="profile-area" d="${areaPath}"/>
                        <path class="profile-line" d="${linePath}"/>
                        <line class="profile-crosshair" data-crosshair x1="0" y1="${MARGIN.top}" x2="0" y2="${MARGIN.top + plotH}" opacity="0"/>
                        <circle class="profile-dot" data-dot cx="0" cy="0" r="4" opacity="0"/>
                    </svg>
                    <div class="profile-tooltip" data-tooltip hidden></div>
                </div>
                <div class="profile-summary">
                    <span>uspon ${ascent || '—'}</span>
                    <span>spust ${formatInteger(meta.descent) || '—'} m</span>
                    <span>pređi prstom preko profila</span>
                </div>
                <span class="sr-only" data-profile-live aria-live="polite"></span>
            </div>
        `;

        this.svg = this.container.querySelector('svg');
        this.tooltip = this.container.querySelector('[data-tooltip]');
        this.crosshair = this.container.querySelector('[data-crosshair]');
        this.dot = this.container.querySelector('[data-dot]');
        this.hiddenText = this.container.querySelector('[data-profile-live]');
        this.geometry = { x, y, yMin, yMax, total, plotW, plotH };

        this.svg.addEventListener('pointermove', this.handleMove, { passive: true });
        this.svg.addEventListener('pointerdown', this.handleMove, { passive: true });
        this.svg.addEventListener('pointerleave', this.handleLeave);
        this.svg.addEventListener('pointercancel', this.handleLeave);
    }

    handleMove(event) {
        if (!this.profile?.points?.length || !this.svg) return;
        const rect = this.svg.getBoundingClientRect();
        if (!rect.width) return;
        const ratio = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
        const viewX = ratio * SVG_W;
        const plotRatio = Math.max(0, Math.min(1, (viewX - MARGIN.left) / this.geometry.plotW));
        const distance = plotRatio * this.geometry.total;
        const point = nearestPoint(this.profile, distance);
        if (!point) return;

        let displayPoint = point;
        if (displayPoint.ele === null) {
            const index = this.profile.points.indexOf(point);
            displayPoint = this.profile.points.slice(Math.max(0, index - 3), index + 4).find((item) => item.ele !== null);
        }
        if (!displayPoint || displayPoint.ele === null) {
            this.handleLeave();
            return;
        }

        this.point = displayPoint;
        const x = this.geometry.x(displayPoint.d);
        const y = this.geometry.y(displayPoint.ele);
        this.crosshair.setAttribute('x1', x);
        this.crosshair.setAttribute('x2', x);
        this.crosshair.setAttribute('opacity', '1');
        this.dot.setAttribute('cx', x);
        this.dot.setAttribute('cy', y);
        this.dot.setAttribute('opacity', '1');

        const chart = this.svg.closest('[data-profile-chart]');
        const chartRect = chart.getBoundingClientRect();
        const svgRect = this.svg.getBoundingClientRect();
        const px = svgRect.left - chartRect.left + (x / SVG_W) * svgRect.width;
        const py = svgRect.top - chartRect.top + (y / SVG_H) * svgRect.height;
        this.tooltip.style.left = `${px}px`;
        this.tooltip.style.top = `${Math.max(24, py - 8)}px`;
        this.tooltip.innerHTML = `${formatNumber(displayPoint.d / 1000, 1)} km · ${formatInteger(displayPoint.ele)} m`;
        this.tooltip.hidden = false;
        if (this.hiddenText) this.hiddenText.textContent = `${formatNumber(displayPoint.d / 1000, 1)} kilometara, ${formatInteger(displayPoint.ele)} metara`;

        this.onHover({
            lat: displayPoint.lat,
            lng: displayPoint.lng,
            ele: displayPoint.ele,
            distance: displayPoint.d,
        });
    }

    handleLeave() {
        if (!this.crosshair) return;
        this.crosshair.setAttribute('opacity', '0');
        this.dot.setAttribute('opacity', '0');
        if (this.tooltip) this.tooltip.hidden = true;
        this.onHover(null);
    }

    destroy() {
        if (this.svg) {
            this.svg.removeEventListener('pointermove', this.handleMove);
            this.svg.removeEventListener('pointerdown', this.handleMove);
            this.svg.removeEventListener('pointerleave', this.handleLeave);
            this.svg.removeEventListener('pointercancel', this.handleLeave);
        }
        this.svg = null;
        this.tooltip = null;
        this.crosshair = null;
        this.dot = null;
        this.point = null;
    }
}

function sample(points, maxPoints) {
    if (points.length <= maxPoints) return points;
    const step = points.length / maxPoints;
    const result = [];
    for (let i = 0; i < maxPoints; i++) {
        result.push(points[Math.min(points.length - 1, Math.floor(i * step))]);
    }
    const last = points[points.length - 1];
    if (result[result.length - 1] !== last) result.push(last);
    return result;
}
