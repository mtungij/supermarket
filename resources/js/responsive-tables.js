/**
 * Stacked tables on phones: copies each column's header text into a
 * `data-label` attribute on the matching cells of `table[data-stack]`, so
 * CSS (app.css, "stacked tables") can show "Label: value" rows below 640px.
 * Re-applied after Livewire DOM updates via a MutationObserver.
 */
function label(table) {
    const headers = [...table.querySelectorAll(':scope > thead th')].map((th) => th.innerText.trim());
    for (const row of table.querySelectorAll(':scope > tbody > tr')) {
        let col = 0;
        for (const cell of row.children) {
            const text = headers[col] ?? '';
            if (cell.getAttribute('data-label') !== text) cell.setAttribute('data-label', text);
            col += Number(cell.getAttribute('colspan') || 1);
        }
    }
}

function labelAll(root = document) {
    root.querySelectorAll('table[data-stack]').forEach(label);
}

let scheduled = false;
const observer = new MutationObserver(() => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; labelAll(); });
});

document.addEventListener('DOMContentLoaded', () => {
    labelAll();
    observer.observe(document.body, { childList: true, subtree: true });
});
