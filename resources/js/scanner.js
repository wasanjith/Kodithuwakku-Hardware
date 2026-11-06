/**
 * USB barcode scanners act as a keyboard: they type the code very fast and press Enter.
 * This listener tells a scan from typing by the speed of the keystrokes and announces it
 * as a window event, whatever field had the cursor:
 *
 *   window 'barcode-scan'  detail: { code, target, handled: false, keepText: false }
 *
 * A screen that uses scans (POS counter, product picker, count sheet …) listens with
 * `@barcode-scan.window="onScan($event.detail)"` and sets `detail.handled = true`. It sets
 * `detail.keepText = true` when the scan went into its own search box (that box keeps the
 * code, so "12*" typed before a scan still counts).
 *
 * The scanner's Enter never submits a form. When a screen handled the scan and the code
 * went into some other field (e.g. the quantity box of the last line), that field gets back
 * the value it had before the scan. Fields marked `data-barcode-field` (the barcode box on
 * the product form) simply keep what was scanned.
 */

const MIN_LENGTH = 6; // shortest code taken as a scan (EAN-8 is 8, a few Code 128 labels are shorter)
const MAX_GAP_MS = 50; // scanners send a character every 5–30 ms; people type much slower

const isField = (element) => element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement;

export function installScanner(target = window) {
    let buffer = '';
    let lastAt = 0;
    let field = null;
    let fieldValue = '';

    target.addEventListener(
        'keydown',
        (event) => {
            const now = performance.now();

            if (event.key === 'Enter') {
                const code = buffer;
                const scanned = code.length >= MIN_LENGTH && now - lastAt <= MAX_GAP_MS * 2;
                const element = field;
                const before = fieldValue;
                buffer = '';
                field = null;

                if (!scanned) return;

                event.preventDefault();
                event.stopImmediatePropagation();

                if (element?.closest?.('[data-barcode-field]')) return;

                const detail = { code, target: element ?? document.activeElement, handled: false, keepText: false };
                window.dispatchEvent(new CustomEvent('barcode-scan', { detail }));

                if (detail.handled && !detail.keepText && isField(element)) {
                    element.value = before;
                    element.dispatchEvent(new Event('input', { bubbles: true }));
                }
                return;
            }

            if (event.key.length !== 1 || event.ctrlKey || event.altKey || event.metaKey) {
                if (!['Shift', 'CapsLock'].includes(event.key)) buffer = '';
                return;
            }

            // A pause starts a new burst: remember the field and its value before the first character goes in.
            if (now - lastAt > MAX_GAP_MS || buffer === '') {
                buffer = '';
                field = document.activeElement;
                fieldValue = isField(field) ? field.value : '';
            }

            buffer += event.key;
            lastAt = now;
        },
        true,
    );
}

/** Short high beep: the scanned item was found. */
export function scanOk() {
    tone(1400, 0.08, 'sine', 0.12);
}

/** Low buzz: the barcode is not known. */
export function scanMissing() {
    tone(180, 0.35, 'square', 0.08);
}

let context = null;

function tone(frequency, seconds, type, volume) {
    try {
        context ??= new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = type;
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(volume, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + seconds);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + seconds);
    } catch {
        // Sound is optional.
    }
}
