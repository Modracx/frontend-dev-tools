# Modracx Frontend Dev Tools

![Packagist version](https://img.shields.io/packagist/v/modracx/mage-frontend-dev-tools.svg)
![License](https://img.shields.io/packagist/l/modracx/mage-frontend-dev-tools.svg)

## Overview

A **storefront developer toolbar and profiler** for Magento 2 that lives on the **top‑center** of every storefront page. Compatible out-of-the-box with **Luma**, **Hyvä**, and **Breeze** themes, it delivers real-time visibility into database query counts, N+1 query patterns, block rendering hierarchies, layout handles, Full Page Cache (FPC) state, theme file fallback resolution, event observer overhead, client-side JavaScript errors, Core Web Vitals, and frontend state (Alpine, Breeze, RequireJS/Knockout) – all without slowing down the page or interfering with your site's design.

The toolbar features a **3-layer security gate** (module switch/mode, IP CIDR whitelist, and signed crypt-key access tokens) to ensure zero impact on regular shoppers and zero exposure of sensitive store telemetry in production environments.

---

## Features

| Feature | What it does | Key UI cue |
|---|---|---|
| **Overview & Headline Profiling** | Displays total request duration, peak memory, query count/time, block count/time, event count, and Full Page Cache (FPC) status (`HIT`, `MISS`, `UNCACHEABLE`, `OFF`). Includes comparison against the previous run of the same action. | Overview tab |
| **Issue Detector & Guidance** | Automatically turns timing data into actionable findings split into userland vs core. Identifies N+1 query loops, duplicate statements, slow block renders, slow observers, and heavy modules with estimated time savings and fix recommendations. | Issues tab |
| **Core Web Vitals** | Measures real browser performance metrics directly in the client: Largest Contentful Paint (LCP), Cumulative Layout Shift (CLS), Interaction to Next Paint (INP), Time to First Byte (TTFB), First Contentful Paint (FCP), and DOM Interactive timing. | Vitals tab |
| **Query Profiler & N+1 Detector** | Captures every SQL query executed during the request. Groups identical statements, flags duplicate executions and N+1 loop patterns, and provides full call stack traces down to the originating file and line number. | SQL tab |
| **Block Render Hierarchy** | Renders a tree of layout blocks with exclusive vs inclusive render times. Highlights slow block rendering, template paths, block classes, cache keys, and cache lifetimes. | Blocks tab |
| **Layout & FPC Diagnostics** | Inspects loaded layout handles, merged layout XML updates, and identifies non-cacheable blocks (`cacheable="false"`) that break Full Page Caching for the page. | Layout tab |
| **Theme Stack & Fallback Resolver** | Detects the active frontend stack (Hyvä, Breeze, Luma), displays the complete theme inheritance chain, and records template and asset resolution paths to show which module or theme file won. | Theme tab |
| **Cache & Private Content** | Shows Full Page Cache status, assigned cache tags, and monitors customer section data (private content) loading state and cache TTL. | Cache tab |
| **Events & Observer Inspector** | Profiles every dispatched Magento event and observer execution time. Identifies slow observers that block request dispatch. | Events tab |
| **Module Performance Breakdown** | Aggregates execution time, query count, block count, and observer count attributed to each active Magento or third-party module. | Modules tab |
| **Client-Side Error Beaconing** | Intercepts frontend JS errors, failed AJAX/fetch requests, Content Security Policy (CSP) violations, and 404 missing assets, reporting them directly into the toolbar. | Client tab |
| **Frontend Stack Inspector** | Inspects active frontend state and context for Alpine.js (Hyvä), Breeze runtime, or RequireJS/Knockout (Luma). | Stack tab |
| **Environment & Indexer Audit** | Reports PHP version, OPcache, Xdebug, Magento mode, database settings, and runs background indexer health checks safely without affecting profiled timings. | Env tab |
| **Run History & AJAX Profiler** | Logs recent storefront requests including background AJAX calls and GraphQL queries with full profile inspection. | History tab |
| **On-Demand Template & Block Hints** | Toggles storefront template hints and block name hints for *your browser only* via signed cookie without turning on global store-wide configuration hints. | Toolbar header (Hints toggle) |
| **CLI Access Token Generator** | Command `bin/magento modracx:frontend:token` generates secure opt-in links for developer browsers. | Terminal CLI |

---

## UI Design & Architecture

* **Launcher & Severity Indicator** – A dimmed `MODRACX` pill centered at the top edge of the screen. The pill changes color depending on the severity of issues detected on the page (`clean`, `low`, `medium`, `high`, `critical`).
* **Lazy-Loaded Drawer** – Clicking the pill slides down a tab strip. Panel content is fetched lazily via AJAX (`modracx_fdt/panel/view`), ensuring the profiler never adds overhead to the page rendering time.
* **3-Layer Security Gate** –
  1. Master configuration switch & Magento mode check (`allow_production`).
  2. Client IP check supporting exact IPs and CIDR ranges (e.g. `10.0.0.0/8`).
  3. HMAC token cookie (`mdx_fdt`) derived from Magento's `crypt/key`.
* **Smart Theme Asset Resolution** – Resolves theme assets directly using `resolvedTheme()` so the toolbar's CSS and JS load cleanly even on Full Page Cache (FPC) hits without 404 errors under placeholder paths like `frontend/_view/en_US/`.
* **Non-Intrusive & Persistent Position** – Drag the launcher horizontally if it overlaps page elements; position is saved in `localStorage`. Fits securely between standard layouts with `z-index: 999999`.
* **Keyboard Shortcuts & CSP Safe** – Supports `Esc` to close, `Tab` focus trapping, and arrow key tab switching. Hydrates runtime state via inert JSON script blocks to remain fully compliant with strict Content Security Policy (CSP) rules on Magento 2.4.8+.

---

## Installation

```bash
composer require modracx/mage-frontend-dev-tools
php bin/magento module:enable Modracx_FrontendDevTools
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

---

## Storefront Access (Opt-in Workflow)

Because the storefront is public, access requires explicit opt-in so shoppers never see profiling data:

1. Generate your developer access link via CLI:
   ```bash
   bin/magento modracx:frontend:token
   ```
2. Open the generated link in the browser where you want the toolbar enabled:
   ```text
   https://your-store.test/modracx_fdt/access/enable?token=<generated_token>
   ```
   This sets a signed `mdx_fdt` cookie valid for 30 days.
3. Ensure your IP address is allowed in **Stores → Configuration → Advanced → Modracx Frontend Dev Tools → Allowed IP Addresses**.
4. To disable access for your browser, click **Disable Toolbar** in the toolbar drawer footer or navigate to `/modracx_fdt/access/disable`.

---

## Configuration

Configure options under **Stores → Settings → Configuration → Advanced → Modracx Frontend Dev Tools**:

| Group | Field | Description | Default |
|---|---|---|---|
| **General** | `enabled` | Master toolbar switch. When off, zero code executes on the storefront. | `Yes` |
| | `allow_production` | Allow toolbar execution in Production mode. | `No` |
| | `allowed_ips` | Comma/newline separated IP addresses or CIDR ranges (`127.0.0.1,::1`). | `127.0.0.1,::1` |
| | `require_cookie` | Also require signed access cookie derived from CLI token. | `Yes` |
| **Collectors** | `db` | Collect database queries, execution time, and backtraces. | `Yes` |
| | `blocks` | Collect block render tree and timing. | `Yes` |
| | `events` | Collect dispatched event names. | `Yes` |
| | `observers` | Collect observer class execution times. | `Yes` |
| | `layout` | Collect layout handles and uncacheable block detection. | `Yes` |
| | `fallback` | Collect theme file fallback resolution paths. | `Yes` |
| | `cache` | Collect FPC status, cache tags, and customer section data state. | `Yes` |
| | `client` | Collect client-side JS errors, failed network requests, and CSP violations. | `Yes` |
| | `translations` | Collect translated phrases (Off by default due to high volume). | `No` |
| **Thresholds** | `slow_query` | Query time threshold in ms to flag as slow. | `50` |
| | `slow_block` | Block render time threshold in ms to flag as slow. | `50` |
| | `slow_observer` | Observer execution time threshold in ms to flag as slow. | `30` |
| | `duplicate_limit` | Identical query count threshold before flagging duplicate queries. | `3` |
| | `nplus1_limit` | Iterated query count threshold before flagging N+1 loop patterns. | `3` |
| | `heavy_module` | Total module execution time threshold in ms to flag as heavy. | `100` |
| **Storage** | `persist` | Store profile runs in DB for History tab & AJAX request profiling. | `Yes` |
| | `retention_hours` | Retention window in hours before profile history is pruned. | `24` |
| | `max_events` | Hard cap on max recorded entries per collector to protect memory. | `5000` |

---

## Permissions & ACL

Control access to profiler administration under **System → Permissions → User Roles → Role Resources**:

| Resource | Description |
|---|---|
| `Modracx_FrontendDevTools::runs` | Access Storefront Profiler Runs history in Admin panel (**System → Other Settings → Storefront Profiler Runs**) |
| `Modracx_FrontendDevTools::config` | Manage Modracx Frontend Dev Tools configuration section in System Configuration |

---

## Routes

| Action | URL / Path |
|---|---|
| Enable Access Cookie | `modracx_fdt/access/enable?token=<token>` |
| Disable Access Cookie | `modracx_fdt/access/disable` |
| View Panel Content | `modracx_fdt/panel/view?panel=<name>&token=<token>` |
| Toggle Template Hints | `modracx_fdt/hints/toggle?mode=on\|blocks\|off` |
| Collect Client Errors | `modracx_fdt/client/collect` |
| Admin Profiler Runs List | `admin/modracx_fdt/runs/index` |

---

## CLI Commands

| Command | Description |
|---|---|
| `bin/magento modracx:frontend:token` | Displays the current crypt-key access token and auto-generated browser opt-in URLs. |

---

## Safety & Security

* **Zero Shopper Overhead** – Requests from unallowed IPs or browsers lacking the access cookie undergo zero timing, zero database logging, and zero DOM injection.
* **Crypt-Key HMAC Verification** – Access token is derived dynamically from `deploymentConfig::get('crypt/key')` using SHA-256 HMAC (`Modracx\FrontendDevTools\Model\AccessGate`). It is never stored in the database or exposed in plain text.
* **Memory & Storage Defense** – Each collector respects a configurable cap (`max_events`, default 5,000). Stored profiles are automatically pruned by background cron (`Modracx\FrontendDevTools\Cron\PruneProfiles`) according to `retention_hours` (default 24h).
* **Isolated Template Hints** – Toggling template or block hints relies on a secure cookie (`mdx_fdt_hints`) scoped strictly to your browser session without altering global Magento store settings.
* **Sensitive Value Masking** – Database queries and environment diagnostic parameters automatically sanitize credentials and tokens.

---

## User Guide (full walkthrough)

### Accessing the Toolbar

1. Run `bin/magento modracx:frontend:token` on your server terminal.
2. Copy the enablement URL and open it in your web browser.
3. Visit any storefront page. Locate the **dimmed "MODRACX" pill** centered on the top edge of the browser viewport.
4. Hover over the pill to see overall page status; click to slide down the developer toolbar.

### Navigating the Drawer

Use mouse or keyboard navigation:

| Key | Action |
|---|---|
| `Esc` | Close drawer |
| `Tab` | Cycle focus within open drawer |
| Arrow left / right | Switch between tabs |
| `Home` / `End` | Jump to first / last tab |

### Panel Walkthrough

#### 1. Overview Tab
* Displays headline timing stats: total request time, peak memory usage, DB query count & time, block count & time, dispatched events, and FPC cache status (`HIT`, `MISS`, `UNCACHEABLE`, `OFF`).
* Shows a direct comparison against the previous run of the same action to immediately spot performance regressions.

#### 2. Issues Tab
* Separates findings into **Userland** (your custom/third-party code) and **Core** (Magento/Hyvä framework code).
* Groups findings by severity (`critical`, `high`, `medium`, `low`).
* Identifies N+1 query loops, duplicate queries, slow block renders, and slow observers with exact code origins and estimated time savings (`saving_ms`).

#### 3. Vitals Tab
* Displays real user browser performance metrics:
  * **LCP** (Largest Contentful Paint)
  * **CLS** (Cumulative Layout Shift)
  * **INP** (Interaction to Next Paint)
  * **TTFB** (Time to First Byte)
  * **FCP** (First Contentful Paint)
  * **DOM Interactive** & Load event timing.

#### 4. SQL Tab
* Lists every database query executed during the request.
* Groups queries by fingerprint, showing execution counts, average/total times, and distinct parameter bindings.
* Expands any query to view full PHP stack backtraces identifying the caller class, method, file, and line number.

#### 5. Blocks Tab
* Visualizes the complete block rendering tree.
* Details **Exclusive Time** (time spent purely in the block method/template) versus **Inclusive Time** (time including child blocks).
* Shows block class names, assigned template files, cache keys, and cache lifetimes.

#### 6. Layout Tab
* Displays all active layout handles applied to the request (e.g. `default`, `catalog_product_view`).
* Inspects merged layout XML structure.
* Flags non-cacheable blocks (`cacheable="false"`) that prevent Full Page Caching.

#### 7. Theme Tab
* Displays active frontend stack detection (**Hyvä**, **Breeze**, or **Luma**).
* Shows the active theme inheritance chain.
* Resolves theme fallback files to pinpoint exactly which module or theme template override rendered.

#### 8. Cache Tab
* Inspects Full Page Cache hit/miss/uncacheable status and response headers.
* Lists active cache tags generated for the page.
* Monitors customer section data (private content) state, invalidation triggers, and section payload TTLs.

#### 9. Events Tab
* Lists all Magento events dispatched during the request lifecycle.
* Displays registered observer classes, sort order, and individual execution times in milliseconds.

#### 10. Modules Tab
* Summarizes total CPU time, database query count, block count, and observer overhead grouped by module namespace.
* Helps immediately pinpoint heavy third-party vendor modules.

#### 11. Client Tab
* Reports frontend runtime errors captured in the browser:
  * JavaScript uncaught exceptions & stack traces.
  * Failed AJAX / fetch network requests.
  * Content Security Policy (CSP) violations.
  * 404 missing static assets.

#### 12. Stack Tab
* Provides deep state inspection for your active frontend framework:
  * **Hyvä**: Alpine.js component data & customer section store state.
  * **Breeze**: Breeze JS components & PJAX state.
  * **Luma**: RequireJS loaded modules & Knockout component registry.

#### 13. Environment Tab
* Summarizes PHP version, memory limits, OPcache & Xdebug state, Magento mode, active database connection, and indexer status.

#### 14. History Tab
* Logs recent storefront page loads, AJAX requests, and GraphQL operations.
* Click any historical run to inspect its complete profile in the toolbar.

---

## Toggling Template & Block Hints

Use the **Hints** toggle button in the top right of the toolbar drawer to switch template hints on demand:
* **Off**: Standard storefront rendering.
* **Templates**: Shows template file paths (`.phtml`) directly on storefront markup for your session only.
* **Blocks**: Shows template file paths alongside Magento Block class names.

---

## Security & Best Practices

1. **Keep `require_cookie` Enabled**: Ensures only authorized developers with valid tokens can view profiler output.
2. **Restrict IP Ranges**: Limit `allowed_ips` to known office/VPN IP subnets.
3. **Production Mode Guard**: Leave `allow_production` set to `No` unless actively debugging a staging/production issue under strict IP restrictions.

---

## Uninstalling the Extension

```bash
php bin/magento module:disable Modracx_FrontendDevTools
php bin/magento setup:upgrade
# Optionally remove codebase:
composer remove modracx/mage-frontend-dev-tools
```

Clear caches after disabling to remove toolbar assets from storefront pages.

---

## Happy profiling!

Elevate your storefront performance analysis with **Modracx Frontend Dev Tools**. For updates, documentation, or support, visit the [Modracx Portal](https://modracx.dpdns.org/) or check the repository.