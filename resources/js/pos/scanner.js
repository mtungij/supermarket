/**
 * Keyboard-wedge barcode scanner support for the POS (ROMESON BS31 and any
 * scanner that "types" the code and presses Enter).
 *
 * - Enter takes the current input value, clears the input immediately and
 *   queues the code; codes are sent to the server ONE AT A TIME, so a fast
 *   second scan is never lost and a single scan is never processed twice.
 * - No request is made per keystroke.
 * - Keystrokes typed while focus is elsewhere (not in another field) are
 *   routed to the scan input, so the scanner keeps working after clicks.
 * - Characters are never altered: keyboard mapping is an OS concern.
 *
 * Future offline POS: the same queue will resolve codes against the local
 * IndexedDB catalogue before falling back to the server.
 */
export function posScanner() {
    return {
        queue: [],
        busy: false,

        init() {
            this.focus();

            this._onKey = (event) => {
                const target = event.target;
                if (event.ctrlKey || event.metaKey || event.altKey) return;
                if (target && (['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(target.tagName) || target.isContentEditable)) return;
                if (event.key.length === 1 || event.key === 'Enter') this.$refs.scan?.focus();
            };
            window.addEventListener('keydown', this._onKey);
            window.addEventListener('pos-focus', () => this.focus());
        },

        destroy() {
            window.removeEventListener('keydown', this._onKey);
        },

        submit() {
            const input = this.$refs.scan;
            const code = (input.value || '').trim();
            input.value = '';
            if (code === '') return;
            this.queue.push(code);
            this.drain();
        },

        async drain() {
            if (this.busy) return;
            this.busy = true;
            try {
                while (this.queue.length > 0) {
                    await this.$wire.scan(this.queue.shift());
                }
            } finally {
                this.busy = false;
                this.focus();
            }
        },

        focus() {
            requestAnimationFrame(() => this.$refs.scan?.focus({ preventScroll: true }));
        },
    };
}

/** Print a receipt through a hidden iframe (no popup blocker issues). */
export function printReceipt(url) {
    const old = document.getElementById('pos-receipt-frame');
    if (old) old.remove();

    const frame = document.createElement('iframe');
    frame.id = 'pos-receipt-frame';
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    frame.src = url;
    document.body.appendChild(frame);
}
