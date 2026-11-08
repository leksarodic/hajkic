import { bearingDegrees, escapeHtml, formatShortDistance, haversine } from './lib/format.js';
import { showToast } from './lib/dom.js';

const HORIZONTAL_FOV = 66;

/**
 * Camera + compass AR view.
 *
 * This is deliberately dependency-free and works without WebXR: the rear
 * camera is the background, and the phone compass projects the trail start,
 * the nearest route point and nearby GPX waypoints into the camera view.
 * It is an orientation aid, not a substitute for a map or judgement.
 */
export function openAR({ trail, geojson, onClose = () => {} }) {
    if (document.getElementById('ar-view')) return null;

    const overlay = document.createElement('div');
    overlay.className = 'ar-view';
    overlay.id = 'ar-view';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'AR pogled na stazu');
    overlay.innerHTML = `
        <video class="ar-video" id="ar-video" playsinline muted autoplay aria-hidden="true"></video>
        <div class="ar-shade" aria-hidden="true"></div>
        <div class="ar-top">
            <div class="ar-title">
                <p>AR pogled</p>
                <h2>${escapeHtml(trail.name)}</h2>
            </div>
            <button type="button" class="ar-close" id="ar-close" aria-label="Zatvori AR pogled">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="ar-compass" aria-hidden="true" id="ar-compass"></div>
        <div class="ar-reticle" aria-hidden="true"><span></span></div>
        <div class="ar-targets" id="ar-targets" aria-hidden="true"></div>
        <div class="ar-bottom">
            <div class="ar-readout">
                <p class="ar-kicker">Najbliži ciljevi</p>
                <ul class="ar-target-list" id="ar-target-list"></ul>
                <p class="ar-hint" id="ar-hint">Uključujem kameru i GPS…</p>
                <p class="ar-disclaimer">AR je eksperimentalan i zavisi od kompasa, GPS-a i kamere. Nije zamena za mapu, markacije ni sopstvenu procenu.</p>
            </div>
            <div class="ar-actions">
                <button type="button" class="btn btn--ghost" id="ar-compass-permission" hidden>Uključi kompas</button>
                <button type="button" class="btn btn--primary" id="ar-close-bottom">Zatvori</button>
            </div>
        </div>
        <div class="ar-fatal" id="ar-fatal" hidden>
            <div>
                <h2>AR trenutno nije dostupan</h2>
                <p id="ar-fatal-text"></p>
                <button type="button" class="btn btn--primary" id="ar-fatal-close">Nazad na stazu</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    document.body.classList.add('ar-open');

    const video = overlay.querySelector('#ar-video');
    const targetsHost = overlay.querySelector('#ar-targets');
    const targetList = overlay.querySelector('#ar-target-list');
    const hint = overlay.querySelector('#ar-hint');
    const compass = overlay.querySelector('#ar-compass');
    const fatal = overlay.querySelector('#ar-fatal');
    const fatalText = overlay.querySelector('#ar-fatal-text');
    const permissionButton = overlay.querySelector('#ar-compass-permission');

    let stream = null;
    let watchId = null;
    let position = null;
    let heading = null;
    let compassSource = null;
    let destroyed = false;
    let targets = [];

    const onKey = (event) => {
        if (event.key === 'Escape') close();
    };

    const cleanup = () => {
        if (destroyed) return;
        destroyed = true;
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        window.removeEventListener('deviceorientation', handleOrientation, true);
        window.removeEventListener('deviceorientationabsolute', handleOrientation, true);
        if (stream) stream.getTracks().forEach((track) => track.stop());
        document.removeEventListener('keydown', onKey);
        overlay.remove();
        document.body.classList.remove('ar-open');
        onClose();
    };

    const close = () => cleanup();
    overlay.querySelector('#ar-close')?.addEventListener('click', close);
    overlay.querySelector('#ar-close-bottom')?.addEventListener('click', close);
    overlay.querySelector('#ar-fatal-close')?.addEventListener('click', close);
    document.addEventListener('keydown', onKey);

    function fatalError(message) {
        fatal.hidden = false;
        fatalText.textContent = message;
    }

    function normalise(angle) {
        let value = angle % 360;
        if (value > 180) value -= 360;
        if (value < -180) value += 360;
        return value;
    }

    function handleOrientation(event) {
        if (typeof event.webkitCompassHeading === 'number' && Number.isFinite(event.webkitCompassHeading)) {
            heading = event.webkitCompassHeading;
            compassSource = 'ios';
        } else if (typeof event.alpha === 'number' && Number.isFinite(event.alpha)) {
            heading = (360 - event.alpha + 360) % 360;
            compassSource = event.absolute ? 'absolute' : 'relative';
        }
        if (heading !== null) {
            permissionButton.hidden = true;
            hint.textContent = compassSource === 'relative'
                ? 'Kompas je relativan. Držite telefon uspravno i okrenite se ka severu.'
                : 'Okrenite telefon polako. Ciljevi se pojavljuju u pravcu kretanja.';
        }
        render();
    }

    async function requestCompass() {
        try {
            if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function') {
                const permission = await DeviceOrientationEvent.requestPermission();
                if (permission !== 'granted') {
                    hint.textContent = 'Pristup kompasu je odbijen. Ciljevi su prikazani prema severu.';
                    return;
                }
            }
            window.addEventListener('deviceorientationabsolute', handleOrientation, true);
            window.addEventListener('deviceorientation', handleOrientation, true);
            permissionButton.hidden = true;
            hint.textContent = 'Kompas je uključen. Okrenite telefon polako.';
        } catch {
            hint.textContent = 'Kompas nije dostupan. Ciljevi su prikazani prema severu.';
        }
    }
    permissionButton.addEventListener('click', requestCompass);

    function startCompass() {
        if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function') {
            permissionButton.hidden = false;
            hint.textContent = 'Dodirnite „Uključi kompas“ da biste dobili pravac.';
        } else {
            window.addEventListener('deviceorientationabsolute', handleOrientation, true);
            window.addEventListener('deviceorientation', handleOrientation, true);
            window.setTimeout(() => {
                if (heading === null) {
                    hint.textContent = 'Kompas nije dostupan. Ciljevi su prikazani prema severu.';
                }
            }, 2500);
        }
    }

    async function startCamera() {
        if (!navigator.mediaDevices?.getUserMedia) {
            fatalError('Ovaj pregledač nema pristup kameri. Otvorite Hajkić na telefonu sa kamerom ili koristite mapu.');
            return;
        }
        if (!window.isSecureContext) {
            fatalError('Kamera radi samo preko HTTPS-a (ili na localhost-u). Otvorite sajt preko sigurne veze.');
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
            video.srcObject = stream;
            await video.play().catch(() => {});
        } catch (error) {
            const message = error?.name === 'NotAllowedError'
                ? 'Pristup kameri je odbijen. Dozvolite kameru u pregledaču ili koristite mapu.'
                : 'Kameru nije moguće pokrenuti na ovom uređaju.';
            fatalError(message);
        }
    }

    function collectTargets() {
        const list = [];
        const start = trail.geometry?.start_point;
        if (Array.isArray(start) && start.length >= 2) {
            list.push({ name: 'Početak staze', detail: trail.start || trail.name, lat: start[1], lng: start[0], kind: 'start' });
        }

        let nearest = null;
        if (geojson?.features?.length && position) {
            for (const feature of geojson.features) {
                if (feature.properties?.kind !== 'trail' || feature.geometry?.type !== 'LineString') continue;
                for (const coordinate of feature.geometry.coordinates) {
                    const distance = haversine(position.lat, position.lng, coordinate[1], coordinate[0]);
                    if (!nearest || distance < nearest.distance) {
                        nearest = { lat: coordinate[1], lng: coordinate[0], distance };
                    }
                }
            }
            if (nearest && nearest.distance > 20) {
                list.push({ name: 'Najbliža tačka staze', detail: 'na ruti', lat: nearest.lat, lng: nearest.lng, kind: 'route' });
            }
            for (const feature of geojson.features) {
                if (feature.properties?.kind !== 'waypoint') continue;
                const [lng, lat] = feature.geometry?.coordinates || [];
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
                const distance = haversine(position.lat, position.lng, lat, lng);
                if (distance > 4000) continue;
                list.push({
                    name: feature.properties.name || 'Tačka na GPX-u',
                    detail: feature.properties.description || 'GPX tačka',
                    lat,
                    lng,
                    kind: 'waypoint',
                    distance,
                });
            }
        }

        const seen = new Set();
        return list
            .map((target) => {
                const distance = position ? haversine(position.lat, position.lng, target.lat, target.lng) : null;
                const bearing = position ? bearingDegrees(position.lat, position.lng, target.lat, target.lng) : null;
                return { ...target, distance, bearing };
            })
            .filter((target) => {
                const key = `${target.name}:${target.lat.toFixed(4)}:${target.lng.toFixed(4)}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            })
            .sort((a, b) => (a.distance ?? Infinity) - (b.distance ?? Infinity))
            .slice(0, 5);
    }

    function renderCompass() {
        if (heading === null) {
            compass.innerHTML = '<span class="ar-compass__wait">kompas se kalibriše…</span>';
            return;
        }
        const cardinal = [
            { label: 'S', angle: 0 },
            { label: 'I', angle: 90 },
            { label: 'J', angle: 180 },
            { label: 'Z', angle: 270 },
        ];
        const marks = cardinal.map((item) => {
            const diff = normalise(item.angle - heading);
            if (Math.abs(diff) > 55) return '';
            const left = 50 + (diff / 55) * 48;
            return `<span class="ar-compass__cardinal" style="left:${left}%">${item.label}</span>`;
        }).join('');
        compass.innerHTML = `<span class="ar-compass__heading">${Math.round(heading)}°</span>${marks}<span class="ar-compass__centre"></span>`;
    }

    function render() {
        if (destroyed) return;
        targets = collectTargets();
        renderCompass();

        if (!position) {
            targetsHost.innerHTML = '';
            targetList.innerHTML = '<li class="ar-wait">Čekam GPS signal…</li>';
            return;
        }

        if (!targets.length) {
            targetsHost.innerHTML = '';
            targetList.innerHTML = '<li class="ar-wait">Nema poznatih ciljeva u blizini.</li>';
            return;
        }

        const effectiveHeading = heading ?? 0;
        const placed = [];

        targets.forEach((target, index) => {
            if (target.distance === null || target.bearing === null) return;
            let diff = normalise(target.bearing - effectiveHeading);
            let edge = null;
            if (Math.abs(diff) > HORIZONTAL_FOV / 2) {
                edge = diff > 0 ? 'right' : 'left';
                diff = diff > 0 ? HORIZONTAL_FOV / 2 : -HORIZONTAL_FOV / 2;
            }
            const left = 50 + (diff / (HORIZONTAL_FOV / 2)) * 48;
            const top = 45 + Math.min(index, 2) * 8;
            placed.push(`<div class="ar-target ${edge ? 'ar-target--edge' : ''} ${edge === 'left' ? 'ar-target--left' : ''} ${edge === 'right' ? 'ar-target--right' : ''}" style="left:${left}%;top:${top}%">
                <span class="ar-target__pin"></span>
                <span class="ar-target__label">
                    <strong>${escapeHtml(target.name)}</strong>
                    <em>${escapeHtml(formatShortDistance(target.distance) || '')} · ${Math.round(target.bearing)}°</em>
                </span>
            </div>`);
        });
        targetsHost.innerHTML = placed.join('');

        targetList.innerHTML = targets.map((target) => `
            <li>
                <span>${escapeHtml(target.name)}</span>
                <strong>${escapeHtml(formatShortDistance(target.distance) || '—')} · ${Math.round(target.bearing)}°</strong>
            </li>
        `).join('');
    }

    function startPosition() {
        if (!navigator.geolocation) {
            fatalError('Ovaj pregledač ne podržava GPS lokaciju.');
            return;
        }
        watchId = navigator.geolocation.watchPosition(
            (next) => {
                position = { lat: next.coords.latitude, lng: next.coords.longitude, accuracy: next.coords.accuracy };
                if (hint.textContent.startsWith('Čekam')) {
                    hint.textContent = 'GPS je pronađen. Okrenite telefon ka cilju.';
                }
                render();
            },
            (error) => {
                const message = error.code === 1
                    ? 'Pristup lokaciji je odbijen. Dozvolite lokaciju da bi AR mogao da izračuna pravac.'
                    : 'GPS signal nije dostupan. Izadjite na otvoreno i pokušajte ponovo.';
                fatalError(message);
            },
            { enableHighAccuracy: true, maximumAge: 4000, timeout: 15000 }
        );
    }

    overlay.querySelector('#ar-close')?.focus({ preventScroll: true });
    startCamera();
    startCompass();
    startPosition();
    render();

    return { close };
}
