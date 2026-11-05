(() => {
    const navigation = document.querySelector('.section-nav[data-repository]');
    const content = document.getElementById('repository-section');
    const status = document.querySelector('.navigation-status');
    if (!navigation || !content || !status) return;

    const repositoryId = navigation.dataset.repository;
    let displayedUrl = location.href;
    let desired = null;
    let running = false;

    function announce(message, loading) {
        status.textContent = message;
        status.classList.toggle('visually-hidden', !loading);
        content.toggleAttribute('aria-busy', loading);
        if (loading) content.setAttribute('aria-busy', 'true');
        navigation.classList.toggle('is-loading', loading);
    }

    async function drain() {
        if (running) return;
        running = true;
        try {
            while (desired) {
                const target = desired;
                desired = null;
                if (target.url.href === displayedUrl) {
                    announce('Section ready.', false);
                    continue;
                }
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 20000);
                try {
                    const response = await fetch(target.url, {
                        signal: controller.signal,
                        credentials: 'same-origin',
                        headers: { Accept: 'text/html' }
                    });
                    // Newer clicks take precedence without leaving orphaned requests.
                    if (desired) continue;
                    if (response.redirected) {
                        location.assign(response.url);
                        return;
                    }
                    if (!response.ok) throw new Error('Section unavailable');
                    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                    if (desired) continue;
                    const next = page.getElementById('repository-section');
                    const nextNavigation = page.querySelector('.section-nav[data-repository]');
                    const activeHref = nextNavigation?.querySelector('[aria-current]')?.getAttribute('href');
                    if (!next || nextNavigation?.dataset.repository !== repositoryId || !activeHref) {
                        throw new Error('Invalid section response');
                    }

                    content.replaceChildren(...next.childNodes);
                    content.setAttribute('aria-label', next.getAttribute('aria-label'));
                    document.title = page.title;
                    const activePath = new URL(activeHref, target.url).pathname;
                    for (const link of navigation.querySelectorAll('a')) {
                        if (new URL(link.href).pathname === activePath) link.setAttribute('aria-current', 'page');
                        else link.removeAttribute('aria-current');
                    }
                    if (target.pushHistory) history.pushState(null, '', target.url);
                    displayedUrl = location.href;
                    announce(next.getAttribute('aria-label') + ' loaded.', false);
                    content.focus({ preventScroll: true });
                } catch (error) {
                    if (desired) continue;
                    announce('This section could not load. Try again, or ', true);
                    const fallback = document.createElement('a');
                    fallback.href = target.url.href;
                    fallback.textContent = 'open it directly.';
                    status.append(fallback);
                } finally {
                    clearTimeout(timeout);
                }
            }
        } finally {
            running = false;
            content.removeAttribute('aria-busy');
            navigation.classList.remove('is-loading');
        }
    }

    function requestSection(url, pushHistory, label) {
        desired = { url, pushHistory };
        announce('Opening ' + label + '…', true);
        void drain();
    }

    navigation.addEventListener('click', event => {
        const link = event.target.closest('a');
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey ||
            event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
        const url = new URL(link.href);
        if (url.origin !== location.origin) return;
        event.preventDefault();
        requestSection(url, true, link.getAttribute('aria-label') || link.textContent.trim());
    });

    window.addEventListener('popstate', () => {
        const url = new URL(location.href);
        if (!url.pathname.startsWith('/r/' + repositoryId + '/') && url.pathname !== '/r/' + repositoryId) {
            location.reload();
            return;
        }
        requestSection(url, false, 'section');
    });
})();
