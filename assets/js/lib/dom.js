export function $(selector, root = document) {
    return root.querySelector(selector);
}

export function $$(selector, root = document) {
    return [...root.querySelectorAll(selector)];
}

export function debounce(fn, wait = 150) {
    let timer = null;
    return function debounced(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), wait);
    };
}

export function showToast(message, duration = 2600) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => {
        toast.hidden = true;
    }, duration);
}

export function setPressed(element, pressed) {
    if (!element) return;
    element.setAttribute('aria-pressed', String(Boolean(pressed)));
}

export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function isMobileView() {
    return window.matchMedia('(max-width: 860px)').matches;
}
