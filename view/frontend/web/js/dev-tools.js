/**
 * Modracx Frontend Dev Tools — toolbar behaviour.
 *
 * Deliberately dependency-free. The three storefronts this has to run on load completely
 * different JavaScript: Luma boots RequireJS and Knockout, Hyva runs Alpine, Breeze ships its
 * own framework and navigates by replacing part of the document. Anything this file required
 * from the page would work on one of them and break on the other two — so it requires
 * nothing, touches no global the theme owns, and talks to the server over fetch().
 */
(function () {
    'use strict';

    var root = document.getElementById('mdx-fdt');
    var bootNode = document.getElementById('mdx-fdt-boot');

    if (!root || !bootNode) {
        return;
    }

    var boot;

    try {
        boot = JSON.parse(bootNode.textContent || '{}');
    } catch (e) {
        return;
    }

    var STORAGE_KEY = 'mdx-fdt-state';
    var drawer = root.querySelector('[id="mdx-fdt-drawer"]');
    var body = root.querySelector('[data-mdx-body]');
    var tabs = Array.prototype.slice.call(root.querySelectorAll('.mdx-fdt__tab'));
    var state = loadState();
    var clientEvents = [];
    var reported = 0;
    var ajaxProfiles = [];
    var navigations = [];

    /**
     * Declared up here with the rest of the state, not down beside the functions that fill
     * it, because the drawer can reopen on its last-used tab before this line would otherwise
     * have run — and `var` hoists the declaration without the assignment, so a panel opening
     * early would read it as undefined.
     */
    var vitals = {lcp: null, lcpElement: null, cls: 0, clsElement: null, inp: null, inpTarget: null};

    /**
     * Which run the panels are showing.
     *
     * Defaults to this page, but History can point it at any stored run — including the
     * background requests that never had a page of their own to draw a toolbar on.
     */
    var viewToken = boot.token;

    root.hidden = false;

    // Before anything else that can take time: these observers should be watching from the
    // earliest possible moment. Buffered entries cover paint and layout-shift retrospectively,
    // but an interaction that happens before the observer exists is simply not measured.
    observeVitals();

    if (state.open) {
        openDrawer(state.panel || 'overview', true);
    }

    /* ------------------------------------------------------------- state */

    function loadState() {
        try {
            return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function saveState() {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            /* private browsing, a full quota — not worth a broken toolbar */
        }
    }

    /* ------------------------------------------------------------ drawer */

    function openDrawer(panel, silent) {
        drawer.hidden = false;
        state.open = true;
        state.panel = panel;
        saveState();
        setExpanded(true);
        selectTab(panel);

        if (!silent) {
            body.focus();
        }
    }

    function closeDrawer() {
        drawer.hidden = true;
        state.open = false;
        saveState();
        setExpanded(false);

        var brand = root.querySelector('.mdx-fdt__brand');

        if (brand) {
            brand.focus();
        }
    }

    function setExpanded(open) {
        Array.prototype.forEach.call(
            root.querySelectorAll('[aria-controls="mdx-fdt-drawer"]'),
            function (el) {
                el.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        );
    }

    function selectTab(panel) {
        tabs.forEach(function (tab) {
            tab.setAttribute('aria-selected', tab.getAttribute('data-mdx-panel') === panel ? 'true' : 'false');
        });

        load(panel);
    }

    /* ------------------------------------------------------------ panels */

    function load(panel) {
        // Always refetched rather than cached in the DOM. This is diagnostic data, and stale
        // diagnostics are worse than slow ones.
        body.innerHTML = '<p class="mdx-fdt__empty">Loading…</p>';
        renderBanner();

        post(boot.panelUrl, {
            panel: panel,
            token: viewToken,
            client: JSON.stringify(clientEvents.slice(0, 200)),
            // Only gathered for the tab that shows it — walking the DOM for every panel
            // request would be a cost paid on every click for one tab's benefit.
            stack: panel === 'stack' ? JSON.stringify(stackSnapshot()) : '',
            vitals: panel === 'vitals' ? JSON.stringify(vitalsSnapshot()) : ''
        })
            .then(function (data) {
                if (data && data.html) {
                    body.innerHTML = data.html;
                    wirePanel();
                } else {
                    body.innerHTML = '<p class="mdx-fdt__empty">' + escapeHtml((data && data.message) || 'No data.') + '</p>';
                }
            })
            .catch(function (error) {
                body.innerHTML = '<p class="mdx-fdt__empty">' + escapeHtml(String(error)) + '</p>';
            });
    }

    /**
     * Panels are plain server-rendered HTML, so the only behaviour they need wiring is the
     * filter box — done here rather than inline so no panel has to ship a script tag.
     */
    function wirePanel() {
        // History rows are the way into every stored run, including the AJAX and GraphQL
        // requests that have no page of their own.
        Array.prototype.forEach.call(body.querySelectorAll('[data-mdx-run]'), function (row) {
            row.addEventListener('click', function () {
                viewToken = row.getAttribute('data-mdx-run');
                openDrawer('overview');
            });
        });

        var filter = body.querySelector('.mdx-filter');

        if (!filter) {
            return;
        }

        filter.addEventListener('input', function () {
            var needle = filter.value.toLowerCase();

            Array.prototype.forEach.call(body.querySelectorAll('[data-mdx-filterable]'), function (row) {
                var haystack = (row.getAttribute('data-mdx-filterable') || row.textContent || '').toLowerCase();

                row.style.display = !needle || haystack.indexOf(needle) !== -1 ? '' : 'none';
            });
        });
    }

    /**
     * Say when the panels are describing something other than the page on screen.
     *
     * Without this the toolbar silently lies: you click a History row, every tab changes, and
     * nothing tells you the numbers now belong to a request from four minutes ago.
     */
    function renderBanner() {
        var banner = root.querySelector('[data-mdx-banner]');

        if (!banner) {
            return;
        }

        if (viewToken === boot.token) {
            banner.hidden = true;

            return;
        }

        banner.hidden = false;
        banner.textContent = 'Showing a stored run, not this page. ';

        var back = document.createElement('button');

        back.type = 'button';
        back.textContent = 'Back to this page';
        back.addEventListener('click', function () {
            viewToken = boot.token;
            selectTab(state.panel || 'overview');
        });
        banner.appendChild(back);
    }

    /**
     * Reload the current URL in a way the full page cache cannot answer.
     *
     * On a cache hit no layout runs, so the toolbar can report the hit and almost nothing
     * else — no blocks, no queries, no theme resolution. Adding a unique parameter makes a
     * cache key nothing has stored, so the page is genuinely built and every panel fills in.
     */
    function reloadUncached() {
        var url = new URL(window.location.href);

        url.searchParams.set('mdxfresh', String(Math.floor(performance.now())) + String(clientEvents.length));
        window.location.href = url.toString();
    }

    /* ------------------------------------------------------------- vitals */

    /**
     * The numbers the visitor actually experiences.
     *
     * Every profiler in this space measures the server and stops there, which means none of
     * them can tell you whether a slow page is slow because of PHP or because of a 900 KB
     * hero image. These are read from the browser's own performance timeline and joined to
     * the server timing this module already has — the one measurement nobody else can make,
     * because it needs both halves.
     */
    function observeVitals() {
        if (typeof PerformanceObserver !== 'function') {
            return;
        }

        safeObserve('largest-contentful-paint', function (entries) {
            var last = entries[entries.length - 1];

            vitals.lcp = last.renderTime || last.loadTime || last.startTime;
            vitals.lcpElement = describe(last.element);
        });

        safeObserve('layout-shift', function (entries) {
            entries.forEach(function (entry) {
                // Shifts the user caused by interacting are not the site's fault.
                if (entry.hadRecentInput) {
                    return;
                }

                vitals.cls += entry.value;

                if (entry.sources && entry.sources.length) {
                    vitals.clsElement = describe(entry.sources[0].node);
                }
            });
        });

        // INP replaced FID as a Core Web Vital: it is the worst interaction latency on the
        // page, so it needs the whole session rather than the first input alone.
        safeObserve('event', function (entries) {
            entries.forEach(function (entry) {
                if (!entry.duration || (vitals.inp !== null && entry.duration <= vitals.inp)) {
                    return;
                }

                vitals.inp = entry.duration;
                vitals.inpTarget = describe(entry.target) + ' (' + entry.name + ')';
            });
        }, {durationThreshold: 40});
    }

    function safeObserve(type, handler, extra) {
        try {
            var options = {type: type, buffered: true};

            Object.keys(extra || {}).forEach(function (k) {
                options[k] = extra[k];
            });

            new PerformanceObserver(function (list) {
                handler(list.getEntries());
            }).observe(options);
        } catch (e) {
            /* entry type unsupported in this browser — the panel says so */
        }
    }

    function describe(node) {
        if (!node || !node.tagName) {
            return '(unknown)';
        }

        var out = node.tagName.toLowerCase();

        if (node.id) {
            out += '#' + node.id;
        } else if (node.className && typeof node.className === 'string') {
            out += '.' + node.className.trim().split(/\s+/).slice(0, 2).join('.');
        }

        if (node.tagName === 'IMG' && node.currentSrc) {
            out += ' ' + node.currentSrc.split('/').pop();
        }

        return out.slice(0, 160);
    }

    /**
     * Navigation and resource timing, summarised.
     */
    function vitalsSnapshot() {
        // Defensive: a panel must never be able to throw on the way to being drawn. Reporting
        // "no measurements yet" is a fair answer; taking the whole toolbar down is not.
        var v = vitals || {};

        var snapshot = {
            lcp: v.lcp, lcpElement: v.lcpElement,
            cls: Math.round((v.cls || 0) * 10000) / 10000, clsElement: v.clsElement,
            inp: v.inp, inpTarget: v.inpTarget,
            ttfb: null, fcp: null, domContentLoaded: null, loadComplete: null,
            byType: {}, total: 0, requests: 0, blocking: 0, uncompressed: [], undimensioned: 0
        };

        try {
            var nav = performance.getEntriesByType('navigation')[0];

            if (nav) {
                snapshot.ttfb = nav.responseStart;
                snapshot.domContentLoaded = nav.domContentLoadedEventEnd;
                snapshot.loadComplete = nav.loadEventEnd;
            }

            var fcp = performance.getEntriesByName('first-contentful-paint')[0];

            if (fcp) {
                snapshot.fcp = fcp.startTime;
            }

            performance.getEntriesByType('resource').forEach(function (entry) {
                var type = entry.initiatorType || 'other';
                var size = entry.decodedBodySize || 0;

                snapshot.requests++;
                snapshot.byType[type] = snapshot.byType[type] || {count: 0, bytes: 0, transferred: 0};
                snapshot.byType[type].count++;
                snapshot.byType[type].bytes += size;
                snapshot.byType[type].transferred += entry.transferSize || 0;
                snapshot.total += entry.transferSize || 0;

                // Served without compression: transfer size within a whisker of the decoded
                // size on something big enough for it to matter.
                if (size > 20480 && entry.transferSize > size * 0.9
                    && ['script', 'link', 'css', 'fetch', 'xmlhttprequest'].indexOf(type) !== -1) {
                    snapshot.uncompressed.push({
                        url: String(entry.name).split('/').pop().slice(0, 90),
                        kb: Math.round(size / 1024)
                    });
                }
            });

            snapshot.uncompressed = snapshot.uncompressed.slice(0, 20);
            snapshot.blocking = document.querySelectorAll(
                'head script[src]:not([defer]):not([async]),head link[rel="stylesheet"]'
            ).length;

            // Images with no intrinsic size reserved are the usual cause of layout shift.
            snapshot.undimensioned = Array.prototype.filter.call(
                document.querySelectorAll('img'),
                function (img) {
                    return !img.getAttribute('width') && !img.getAttribute('height')
                        && !(img.style && (img.style.aspectRatio || img.style.height));
                }
            ).length;
        } catch (e) {
            snapshot.error = String(e);
        }

        return snapshot;
    }

    /**
     * What the browser knows and PHP cannot: which components actually booted, what customer
     * data is in storage and how old it is.
     */
    function stackSnapshot() {
        var snapshot = {cards: [], components: [], sections: [], navigations: navigations.slice(0, 25), notes: {}};

        try {
            collectComponents(snapshot);
            collectSections(snapshot);
        } catch (e) {
            snapshot.notes['snapshot error'] = String(e);
        }

        return snapshot;
    }

    function collectComponents(snapshot) {
        var alpine = window.Alpine;

        if (alpine) {
            snapshot.notes['Alpine'] = alpine.version || 'present';
        }

        if (window.breeze) {
            snapshot.notes['Breeze'] = window.breeze.version || 'present';
        }

        if (window.require && window.require.s && window.require.s.contexts) {
            var ctx = window.require.s.contexts._;
            var defined = ctx && ctx.defined ? Object.keys(ctx.defined).length : 0;

            snapshot.notes['RequireJS modules'] = String(defined);
            snapshot.cards.push({label: 'RequireJS', value: String(defined), note: 'modules defined'});
        }

        // x-data covers Hyva/Alpine; data-mage-init and data-bind cover Luma and Breeze,
        // which both reuse Magento's markup contract even though the runtimes differ.
        var nodes = document.querySelectorAll('[x-data],[data-mage-init],[data-bind]');

        snapshot.cards.push({label: 'Components', value: String(nodes.length), note: 'initialised in DOM'});

        Array.prototype.slice.call(nodes, 0, 200).forEach(function (el) {
            var raw = el.getAttribute('x-data') || el.getAttribute('data-mage-init') || el.getAttribute('data-bind') || '';
            var name = raw.split(/[({\s]/)[0] || '(inline)';
            var keys = '';

            try {
                if (el._x_dataStack && el._x_dataStack[0]) {
                    keys = Object.keys(el._x_dataStack[0]).slice(0, 12).join(', ');
                }
            } catch (e) {
                keys = '(not readable)';
            }

            snapshot.components.push({
                element: el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''),
                name: name.slice(0, 120),
                keys: keys
            });
        });
    }

    function collectSections(snapshot) {
        var raw = null;

        try {
            raw = window.localStorage.getItem('mage-cache-storage');
        } catch (e) {
            return;
        }

        if (!raw) {
            return;
        }

        var data;

        try {
            data = JSON.parse(raw);
        } catch (e) {
            return;
        }

        var now = Math.floor(Date.now() / 1000);

        Object.keys(data).forEach(function (name) {
            var section = data[name] || {};
            var age = section.data_id ? now - Number(section.data_id) : null;

            snapshot.sections.push({
                name: name,
                age: age,
                age_label: age === null ? 'unknown' : (age > 3600 ? Math.round(age / 3600) + ' h' : age + ' s'),
                bytes: JSON.stringify(section).length
            });
        });

        snapshot.cards.push({label: 'Sections', value: String(snapshot.sections.length), note: 'in local storage'});
    }

    function post(url, payload) {
        var form = new FormData();

        form.append('form_key', boot.formKey);

        Object.keys(payload || {}).forEach(function (key) {
            if (payload[key] !== null && payload[key] !== undefined) {
                form.append(key, payload[key]);
            }
        });

        return fetch(url, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            return response.json();
        });
    }

    function escapeHtml(value) {
        var div = document.createElement('div');

        div.appendChild(document.createTextNode(String(value)));

        return div.innerHTML;
    }

    /* ------------------------------------------------------------ events */

    root.addEventListener('click', function (event) {
        var target = event.target.closest
            ? event.target.closest('[data-mdx-panel],[data-mdx-toggle],[data-mdx-hide],[data-mdx-hints],[data-mdx-fresh]')
            : null;

        if (!target || !root.contains(target)) {
            return;
        }

        if (target.hasAttribute('data-mdx-fresh')) {
            reloadUncached();

            return;
        }

        if (target.hasAttribute('data-mdx-hide')) {
            root.hidden = true;

            return;
        }

        if (target.hasAttribute('data-mdx-hints')) {
            cycleHints(target);

            return;
        }

        if (target.hasAttribute('data-mdx-toggle')) {
            if (drawer.hidden) {
                openDrawer(state.panel || 'overview');
            } else {
                closeDrawer();
            }

            return;
        }

        var panel = target.getAttribute('data-mdx-panel');

        if (drawer.hidden || state.panel !== panel) {
            openDrawer(panel);
        } else {
            closeDrawer();
        }
    });

    /**
     * off → on → blocks → off. Cycling rather than offering a menu because the whole point of
     * these hints is that they are one click away and one click gone.
     */
    function cycleHints(button) {
        var order = ['off', 'on', 'blocks'];
        var label = button.querySelector('[data-mdx-hints-state]');
        var current = label ? label.textContent.trim() : 'off';
        var next = order[(order.indexOf(current) + 1) % order.length];

        post(boot.hintsUrl, {mode: next}).then(function () {
            window.location.reload();
        });
    }

    document.addEventListener('keydown', function (event) {
        // Ctrl+Shift+D anywhere. Chosen because it collides with nothing in Magento's admin
        // or storefront, and because reaching for the mouse to open a debugger is a tax.
        if (event.ctrlKey && event.shiftKey && (event.key === 'D' || event.key === 'd')) {
            event.preventDefault();
            root.hidden = false;

            if (drawer.hidden) {
                openDrawer(state.panel || 'overview');
            } else {
                closeDrawer();
            }

            return;
        }

        if (drawer.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            closeDrawer();

            return;
        }

        if (event.key === 'Tab') {
            trapFocus(event);
        }
    });

    /**
     * Keep Tab inside the open drawer. Without this, tabbing walks straight out into the
     * storefront underneath and the developer loses the panel they were reading.
     */
    function trapFocus(event) {
        var focusable = root.querySelectorAll('button, [href], input, select, textarea, summary, [tabindex]:not([tabindex="-1"])');
        var visible = Array.prototype.filter.call(focusable, function (el) {
            return el.offsetParent !== null;
        });

        if (!visible.length) {
            return;
        }

        var first = visible[0];
        var last = visible[visible.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    /* ----------------------------------------------------- client signals */

    if (boot.collectClient) {
        collectClientErrors();
    }

    /**
     * Everything the server cannot see.
     *
     * A missing static file, a Knockout binding that threw, an Alpine expression that
     * referenced a property nobody defined, a content security policy quietly refusing an
     * inline handler — none of that reaches PHP, and all of it is why the page looks wrong.
     * These are buffered and sent with the next panel request rather than beaconed one by
     * one, so a page erroring in a loop cannot turn into a request flood.
     */
    function collectClientErrors() {
        window.addEventListener('error', function (event) {
            if (event.target && event.target !== window && event.target.tagName) {
                // A failed <script>/<link>/<img> — the usual sign of a static deploy that
                // did not finish, or of a theme override pointing at a file that moved.
                record('asset', (event.target.src || event.target.href || '(unknown)'), event.target.tagName.toLowerCase());

                return;
            }

            record('js', event.message, (event.filename || '') + ':' + (event.lineno || 0));
        }, true);

        window.addEventListener('unhandledrejection', function (event) {
            record('promise', String((event.reason && event.reason.message) || event.reason || 'rejected'), '');
        });

        document.addEventListener('securitypolicyviolation', function (event) {
            record('csp', event.violatedDirective + ' blocked ' + (event.blockedURI || 'inline'), event.sourceFile || '');
        });

        watchFetch();
        watchXhr();
    }

    function record(type, message, detail) {
        if (reported >= 200) {
            return;
        }

        reported++;
        clientEvents.push({type: type, message: String(message).slice(0, 500), detail: String(detail || '').slice(0, 500)});
    }

    /**
     * Note the profile token of background requests.
     *
     * Section loads, add-to-cart and GraphQL have no page for a toolbar to sit on, but they
     * are profiled all the same and the response carries the token. Picking it up here is
     * what lets the History tab show the request you just triggered.
     */
    function noteProfile(token, url, status) {
        if (!token) {
            return;
        }

        ajaxProfiles.unshift({token: token, url: url, status: status});
        ajaxProfiles = ajaxProfiles.slice(0, 25);
    }

    function watchFetch() {
        if (typeof window.fetch !== 'function') {
            return;
        }

        var original = window.fetch;

        window.fetch = function () {
            var url = arguments[0] && arguments[0].url ? arguments[0].url : String(arguments[0]);

            return original.apply(this, arguments).then(function (response) {
                try {
                    noteProfile(response.headers.get('X-Modracx-Profile'), url, response.status);

                    if (!response.ok) {
                        record('request', response.status + ' ' + url, 'fetch');
                    }
                } catch (e) {
                    /* opaque cross-origin response — nothing to read */
                }

                return response;
            }).catch(function (error) {
                record('request', String(error) + ' ' + url, 'fetch');

                throw error;
            });
        };
    }

    function watchXhr() {
        var open = window.XMLHttpRequest && window.XMLHttpRequest.prototype.open;

        if (!open) {
            return;
        }

        window.XMLHttpRequest.prototype.open = function (method, url) {
            this.addEventListener('load', function () {
                try {
                    noteProfile(this.getResponseHeader('X-Modracx-Profile'), url, this.status);

                    if (this.status >= 400) {
                        record('request', this.status + ' ' + url, 'xhr');
                    }
                } catch (e) {
                    /* headers not readable */
                }
            });

            this.addEventListener('error', function () {
                record('request', 'network error ' + url, 'xhr');
            });

            return open.apply(this, arguments);
        };
    }

    /* -------------------------------------------------- soft navigations */

    /**
     * Survive Breeze and Turbo.
     *
     * Breeze navigates by fetching the next page and swapping parts of the document, which
     * can take the toolbar with it — and the replacement page's own toolbar never arrives,
     * because a background fetch is not a document and does not get one injected. Re-attaching
     * the element we already have keeps the bar in place across a soft navigation; the numbers
     * still belong to the page that was loaded properly, and the History tab is where the
     * navigations in between are read.
     */
    ['turbo:load', 'turbolinks:load', 'breeze:load', 'pjax:end', 'popstate'].forEach(function (name) {
        document.addEventListener(name, function () {
            if (!document.body.contains(root)) {
                document.body.appendChild(root);
            }

            // Recorded so the Stack tab can show what happened after the page load the
            // numbers describe — otherwise a Breeze session looks like one long page view.
            navigations.unshift(name + ' → ' + window.location.pathname + window.location.search);
            navigations = navigations.slice(0, 25);
        });
    });

    /**
     * Hand over what the browser saw before the page goes away.
     *
     * Client errors otherwise only travel with the next panel request, which means the ones
     * on pages you clicked straight through — the pages nobody was watching — are exactly the
     * ones that get lost. sendBeacon survives teardown; fetch does not.
     */
    window.addEventListener('pagehide', function () {
        if (!clientEvents.length || !boot.clientUrl || typeof navigator.sendBeacon !== 'function') {
            return;
        }

        try {
            var form = new FormData();

            form.append('token', boot.token);
            form.append('events', JSON.stringify(clientEvents.slice(0, 200)));
            navigator.sendBeacon(boot.clientUrl, form);
        } catch (e) {
            /* nothing useful left to do at this point in a page's life */
        }
    });
})();
