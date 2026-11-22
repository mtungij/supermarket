/**
 * POS product search (name / SKU / barcode) for products without a usable
 * barcode. Works next to the keyboard-wedge scanner (scanner.js):
 *
 * - Debounced (250 ms) calls to the RENDERLESS Livewire method
 *   searchProducts(): the POS is not re-rendered while typing and the
 *   catalogue is never loaded into the browser.
 * - Every request carries a sequence number; a slower, older response is
 *   ignored, so results always match what is currently typed.
 * - ArrowDown / ArrowUp move the highlight, Enter adds it, Escape closes.
 *   Mouse and touch select directly.
 * - Enter before results arrive waits for the pending search. A result that
 *   is an EXACT barcode/SKU match (e.g. a scanner fired while this box had
 *   focus) goes through the scan queue instead, so packaging barcodes
 *   still add the carton/crate unit.
 * - The server re-resolves product, unit, price and stock on add.
 */
export function posSearch() {
    return {
        term: '',
        results: [],
        resultsFor: '',
        open: false,
        active: -1,
        loading: false,
        adding: false,
        seq: 0,
        timer: null,
        pending: null,

        onInput() {
            clearTimeout(this.timer);
            const term = this.term.trim();
            if (term.length < 2) {
                this.seq++;            // drop any in-flight response
                this.results = [];
                this.resultsFor = '';
                this.open = false;
                this.loading = false;
                this.pending = null;
                return;
            }
            this.loading = true;
            this.pending = new Promise((resolve) => {
                this.timer = setTimeout(() => this.fetch(term).then(resolve), 250);
            });
        },

        async fetch(term) {
            const seq = ++this.seq;
            let response;
            try {
                response = await this.$wire.searchProducts(term);
            } catch (e) {
                if (seq === this.seq) this.loading = false;
                return;
            }
            if (seq !== this.seq || term !== this.term.trim()) return;   // stale
            this.results = response?.results ?? [];
            this.resultsFor = term;
            this.active = this.results.findIndex((r) => r.status === 'in_stock');
            if (this.active === -1 && this.results.length) this.active = 0;
            this.open = true;
            this.loading = false;
        },

        move(step) {
            if (!this.open || !this.results.length) return;
            this.active = (this.active + step + this.results.length) % this.results.length;
            this.$nextTick(() => this.$refs.list?.querySelector(`[data-index="${this.active}"]`)?.scrollIntoView({ block: 'nearest' }));
        },

        async enter() {
            const term = this.term.trim();
            if (term === '') return;
            if (this.resultsFor !== term && this.pending) await this.pending;
            if (this.resultsFor === term && this.results[this.active]) {
                await this.choose(this.active);
            } else if (this.resultsFor === term) {
                // nothing matched: let the scan lookup explain ("No product found …")
                window.dispatchEvent(new CustomEvent('pos-scan', { detail: { code: term } }));
                this.reset();
            }
        },

        async choose(index) {
            const r = this.results[index];
            if (!r || this.adding) return;
            if (r.exact) {
                window.dispatchEvent(new CustomEvent('pos-scan', { detail: { code: this.resultsFor } }));
                this.reset();
                return;
            }
            this.adding = true;
            try {
                const added = await this.$wire.addProduct(r.uuid);
                if (added) {
                    this.reset();
                } else {
                    this.open = true;      // keep the list; the reason is shown above it
                    this.$refs.input?.focus({ preventScroll: true });
                }
            } finally {
                this.adding = false;
            }
        },

        close() {
            this.open = false;
        },

        reset() {
            clearTimeout(this.timer);
            this.seq++;
            this.term = '';
            this.results = [];
            this.resultsFor = '';
            this.open = false;
            this.active = -1;
            this.loading = false;
            this.pending = null;
            window.dispatchEvent(new CustomEvent('pos-focus'));
        },
    };
}
