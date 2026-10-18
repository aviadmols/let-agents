{{-- The ⓘ beside a number's title (Admin\Support\Tips): added after every render, on the titles it explains. --}}
<script>
(() => {
    const tips = @js($tips);
    const titles = '.lp-stat-l, .lp-card-t h2, .lp-lbl';
    const mark = () => document.querySelectorAll(titles).forEach((el) => {
        if (el.querySelector('.lp-tip')) return;
        const tip = tips[(el.textContent || '').split('·')[0].trim()];
        if (!tip) return;
        const i = document.createElement('span');
        i.className = 'lp-tip'; i.tabIndex = 0; i.setAttribute('role', 'img'); i.setAttribute('aria-label', tip); i.dataset.tip = tip; i.textContent = 'i';
        el.appendChild(i);
    });
    let soon = null;
    new MutationObserver(() => { clearTimeout(soon); soon = setTimeout(mark, 60); }).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('DOMContentLoaded', mark);
    mark();
})();
</script>