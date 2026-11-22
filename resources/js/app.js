/**
 * Application JavaScript. (resources/js/pos/ holds the online POS helpers.)
 *
 * Alpine.js is provided by Livewire 3, so it is not imported here.
 *
 * Future offline POS code will live under resources/js/pos/ (see
 * docs/offline-pos-design.md). Nothing offline is implemented in Phase 1.
 */

import { posScanner, printReceipt } from './pos/scanner';
import { posSearch } from './pos/search';
import './responsive-tables';

window.posScanner = posScanner;
window.posSearch = posSearch;
window.printReceipt = printReceipt;

const THEME_KEY = 'theme';

window.toggleTheme = function toggleTheme() {
    const dark = document.documentElement.classList.toggle('dark');

    try {
        localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light');
    } catch (e) {
        // Storage may be unavailable (private mode); the toggle still works for this page.
    }

    return dark;
};
