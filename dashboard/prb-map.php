<?php require __DIR__ . "/src/bootstrap.php"; ?>
<?php const DEBUG = false; ?>
<!doctype html>
<html lang="en" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>PRB Heatmap</title>
        <link rel="preconnect" href="https://api.fontshare.com">
        <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&display=swap" rel="stylesheet">
        <style>
            :root, [data-theme="light"] {
                --bg: #f7f6f2;
                --surface: #fbfbf9;
                --surface-2: #f1efe9;
                --text: #28251d;
                --muted: #6f6d66;
                --primary: #01696f;
                --shadow: 0 12px 30px rgba(40, 37, 29, .08);
                --space-2: .5rem;
                --space-3: .75rem;
                --space-4: 1rem;
                --space-5: 1.25rem;
                --space-6: 1.5rem;
                --space-8: 2rem;
                --space-10: 2.5rem;
                --radius: 22px;
                --grid-stroke: rgba(0, 0, 0, .12)
            }

            [data-theme="dark"] {
                --bg: #171614;
                --surface: #1d1b19;
                --surface-2: #26231f;
                --text: #ece8df;
                --muted: #a7a198;
                --primary: #4f98a3;
                --shadow: 0 12px 30px rgba(0, 0, 0, .35);
                --grid-stroke: rgba(255, 255, 255, .1)
            }

            * {
                box-sizing: border-box
            }

            html, body {
                margin: 0;
                padding: 0;
                background: var(--bg);
                color: var(--text);
                font-family: 'Satoshi', system-ui, sans-serif
            }

            body {
                min-height: 100vh;
                background: radial-gradient(circle at top right, color-mix(in srgb, var(--primary) 10%, transparent), transparent 28%), var(--bg)
            }

            .page {
                max-width: 1640px;
                margin: 0 auto;
                padding: var(--space-8)
            }

            .topbar {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: var(--space-4);
                margin-bottom: var(--space-6)
            }

            .title h1 {
                margin: 0;
                font-size: clamp(1.8rem, 1.4rem + 1.6vw, 3rem);
                line-height: 1.02
            }

            .title p {
                margin: .65rem 0 0;
                color: var(--muted);
                max-width: 80ch
            }

            .actions {
                display: flex;
                gap: var(--space-3);
                align-items: center
            }

            .btn {
                min-height: 44px;
                padding: 0 1rem;
                border-radius: 999px;
                border: 1px solid color-mix(in srgb, var(--text) 10%, transparent);
                background: var(--surface);
                color: var(--text);
                cursor: pointer
            }

            .btn.primary {
                background: var(--primary);
                color: #fff;
                border-color: transparent
            }

            .tenant-section {
                display: grid;
                gap: var(--space-4);
                margin-bottom: var(--space-6)
            }

            .tenant-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: var(--space-4)
            }

            .tenant-head h2 {
                margin: 0;
                font-size: 1.15rem
            }

            .tenant-head p {
                margin: .25rem 0 0;
                color: var(--muted);
                font-size: .88rem
            }

            .tenant-badge {
                padding: .45rem .8rem;
                border-radius: 999px;
                background: color-mix(in srgb, var(--primary) 14%, var(--surface));
                color: var(--primary);
                font-size: .82rem;
                font-weight: 700
            }

            .maps {
                display: grid;
                grid-template-columns:1fr 1fr;
                gap: var(--space-5);
                align-items: start
            }

            .card {
                background: color-mix(in srgb, var(--surface) 94%, transparent);
                border: 1px solid color-mix(in srgb, var(--text) 8%, transparent);
                border-radius: var(--radius);
                box-shadow: var(--shadow)
            }

            .map-card {
                padding: var(--space-5)
            }

            .map-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: var(--space-4);
                margin-bottom: var(--space-4)
            }

            .map-head h3 {
                margin: 0;
                font-size: 1rem
            }

            .map-head p {
                margin: .2rem 0 0;
                color: var(--muted);
                font-size: .82rem
            }

            .pill {
                padding: .45rem .75rem;
                border-radius: 999px;
                background: color-mix(in srgb, var(--primary) 16%, var(--surface));
                color: var(--primary);
                font-size: .82rem;
                font-weight: 700
            }

            .prb-grid {
                display: grid;
                grid-template-columns: repeat(10, minmax(0, 1fr));
                gap: 3px;
                background: var(--grid-stroke);
                padding: 3px;
                border-radius: 20px;
                overflow: hidden;
            }

            .cell {
                aspect-ratio: 1/1;
                border-radius: 4px;
                background: var(--surface-2);
                position: relative
            }

            .cell::after {
                content: '';
                position: absolute;
                inset: 0;
                border: 1px solid var(--grid-stroke);
                border-radius: 4px
            }

            .cell.empty {
                background: color-mix(in srgb, var(--surface-2) 88%, transparent)
            }

            .legend {
                display: grid;
                grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));
                gap: 12px
            }

            .legend-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: var(--space-3);
                padding: .85rem 1rem;
                border-radius: 16px;
                background: var(--surface);
                border: 1px solid color-mix(in srgb, var(--text) 8%, transparent)
            }

            .legend-left {
                display: flex;
                align-items: center;
                gap: .8rem;
                min-width: 0
            }

            .swatch {
                width: 14px;
                height: 14px;
                border-radius: 999px;
                flex: 0 0 auto;
                box-shadow: 0 0 0 2px rgba(255, 255, 255, .35) inset
            }

            .legend-label strong {
                display: block;
                font-size: .95rem
            }

            .legend-label span {
                display: block;
                color: var(--muted);
                font-size: .8rem
            }

            .legend-right {
                text-align: right;
                font-variant-numeric: tabular-nums;
                flex-shrink: 0;
                display: flex;
                flex-direction: column;
                justify-content: center;
                gap: 2px;
            }

            .legend-right strong {
                display: block;
                font-size: .85rem;
            }

            .legend-right span {
                display: block;
                color: var(--muted);
                font-size: .76rem
            }

            .empty-state {
                padding: var(--space-6);
                text-align: center;
                color: var(--muted)
            }

            @media (max-width: 1260px) {
                .maps {
                    grid-template-columns:1fr
                }
            }

            @media (max-width: 860px) {
                .page {
                    padding: var(--space-5)
                }

                .topbar, .tenant-head {
                    flex-direction: column;
                    align-items: flex-start
                }
            }

            .nav-menu {
                display: flex;
                gap: var(--space-3);
                align-items: center;
                flex-wrap: wrap;
            }

            .nav-link {
                text-decoration: none;
                padding: var(--space-2) var(--space-4);
                border-radius: 999px;
                color: var(--muted);
                font-weight: 500;
                font-size: .875rem;
                border: 1px solid color-mix(in srgb, var(--text) 10%, transparent);
                background: var(--surface);
                transition: all 0.2s ease;
            }

            .nav-link:hover {
                color: var(--text);
                border-color: color-mix(in srgb, var(--text) 20%, transparent);
            }

            .nav-link.active {
                background: var(--primary);
                color: #fff;
                border-color: transparent;
            }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="topbar">
                <div class="title">
                    <h1>PRB usage heatmap</h1>
                    <p>One per each authorised tenant</p>
                </div>
                <nav class="nav-menu">
                    <a href="index.php" class="nav-link">Overview</a>
                    <a href="prb-map.php" class="nav-link active">PRB Heatmap</a>
                    <a href="traffic-control.php" class="nav-link">Traffic Control</a>
                    <a href="resource-control.php" class="nav-link">Resource Control</a>
                </nav>
                <div class="actions">
                    <span id="lastUpdate" class="pill">Updating…</span>
                    <button id="themeBtn" class="btn">◐</button>
                    <button id="refreshBtn" class="btn primary">Update</button>
                </div>
            </div>

            <div id="tenantContainer"></div>
        </div>
        <script>
            (() => {
                const qs = s => document.querySelector(s);
                const PALETTE = ['#2e7d32', '#c2185b', '#1e88e5', '#ef6c00', '#d4b000', '#6a1b9a', '#00897b', '#7b1f1f', '#3949ab', '#5d4037', '#00acc1', '#8e24aa'];
                const state = {theme: matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'};
                document.documentElement.setAttribute('data-theme', state.theme);
                qs('#themeBtn').addEventListener('click', () => {
                    state.theme = state.theme === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', state.theme);
                });

                const esc = str => String(str ?? '').replace(/[&<>"']/g, m => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[m]));
                const pct = v => Math.max(0, Math.min(100, Math.round(Number(v || 0))));

                async function api(action) {
                    const res = await fetch(`api.php?action=${action}`, {
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json'}
                    });
                    if (!res.ok) throw new Error('API error');
                    return res.json();
                }

                function buildTenantGroups(data) {
                    const sessions = data.live_metrics?.sessions || [];
                    const kpms = data.live_metrics?.kpm || {};
                    const groups = new Map();

                    sessions.forEach((session, index) => {
                        const imsi = String(session.imsi);
                        const tenant = imsi.slice(0, 5);
                        const amfId = String(session.amf_id);
                        const kpm = kpms[amfId] || {};
                        if (!groups.has(tenant)) groups.set(tenant, []);
                        groups.get(tenant).push({
                            order: index,
                            imsi,
                            amfId,
                            ueId: session.ue_id,
                            color: PALETTE[index % PALETTE.length],
                            ulPct: pct(kpm.PrbTotUl_pct),
                            dlPct: pct(kpm.PrbTotDl_pct),
                        });
                    });

                    return Array.from(groups.entries()).map(([tenant, items]) => ({tenant, items}));
                }

                function expandBlocks(items, field) {
                    const blocks = [];
                    for (const item of items) {
                        const count = pct(item[field]);
                        for (let i = 0; i < count; i += 1) blocks.push(item);
                    }
                    return blocks.length > 100 ? blocks.slice(0, 100) : blocks.concat(Array(Math.max(0, 100 - blocks.length)).fill(null));
                }

                function gridHtml(blocks) {
                    return blocks.map(cell =>
                        cell
                            ? `<div class="cell" title="IMSI ${esc(cell.imsi)}" style="background:${cell.color}"></div>`
                            : '<div class="cell empty"></div>'
                    ).join('');
                }

                function legendHtml(items) {
                    if (!items.length) return '<div class="empty-state">No user is active for this tenant.</div>';
                    return `<div class="legend">${items.map(item => `
      <div class="legend-item">
        <div class="legend-left">
          <span class="swatch" style="background:${item.color}"></span>
          <div class="legend-label">
            <strong>${esc(item.imsi)}</strong>
            <?php if (DEBUG): ?>
            <span>AMF ${esc(item.amfId)} · UE ${esc(item.ueId)}</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="legend-right">
          <strong>UL ${item.ulPct}%</strong>
          <strong>DL ${item.dlPct}%</strong>
        </div>
      </div>
    `).join('')}</div>`;
                }

                function tenantSection(group) {
                    const ulBlocks = expandBlocks(group.items, 'ulPct');
                    const dlBlocks = expandBlocks(group.items, 'dlPct');
                    const ulUsage = Math.min(100, group.items.reduce((sum, item) => sum + item.ulPct, 0));
                    const dlUsage = Math.min(100, group.items.reduce((sum, item) => sum + item.dlPct, 0));

                    return `
      <section class="tenant-section">
        <div class="tenant-head">
          <div>
            <h2>PLMN ${esc(group.tenant)}</h2>
          </div>
          <span class="tenant-badge">${group.items.length} currently active sessions</span>
        </div>

        <div class="maps">
          <article class="card map-card">
            <div class="map-head">
              <div>
                <h3>Uplink PRB map</h3>
              </div>
              <span class="pill">UL ${ulUsage}%</span>
            </div>
            <div class="prb-grid">${gridHtml(ulBlocks)}</div>
          </article>

          <article class="card map-card">
            <div class="map-head">
              <div>
                <h3>Downlink PRB map</h3>
              </div>
              <span class="pill">DL ${dlUsage}%</span>
            </div>
            <div class="prb-grid">${gridHtml(dlBlocks)}</div>
          </article>
        </div>

        ${legendHtml(group.items)}
      </section>
    `;
                }

                function render(data) {
                    const groups = buildTenantGroups(data);
                    const tenants = data.tenants || [];
                    const visibleGroups = groups.filter(group => tenants.includes(group.tenant));

                    qs('#tenantContainer').innerHTML = visibleGroups.length
                        ? visibleGroups.map(tenantSection).join('')
                        : '<div class="card empty-state">No active sessions on currently athorized tenants</div>';

                    qs('#lastUpdate').textContent = `Last updated on ${new Date().toLocaleTimeString('it-IT')}`;
                }

                async function load() {
                    try {
                        const data = await api('dashboard');
                        render(data);
                    } catch (err) {
                        qs('#tenantContainer').innerHTML = '<div class="card empty-state">Loading error...</div>';
                    }
                }

                const REFRESH_INTERVAL = 5000;
                qs('#refreshBtn').addEventListener('click', load);
                load();
                setInterval(load, REFRESH_INTERVAL);
            })();
        </script>
    </body>
</html>
