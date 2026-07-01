<?php require __DIR__ . "/src/bootstrap.php"; ?>
<?php const DEBUG = false; ?>
<!doctype html>
<html lang="en" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Tenant Live Overview</title>
        <link rel="preconnect" href="https://api.fontshare.com">
        <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&display=swap" rel="stylesheet">
        <style>
            :root, [data-theme="light"] {
                --text-xs: clamp(.75rem, .7rem + .25vw, .875rem);
                --text-sm: clamp(.875rem, .8rem + .35vw, 1rem);
                --text-base: clamp(1rem, .95rem + .25vw, 1.125rem);
                --text-lg: clamp(1.125rem, 1rem + .75vw, 1.5rem);
                --text-xl: clamp(1.5rem, 1.2rem + 1.25vw, 2.25rem);
                --space-1: .25rem;
                --space-2: .5rem;
                --space-3: .75rem;
                --space-4: 1rem;
                --space-5: 1.25rem;
                --space-6: 1.5rem;
                --space-8: 2rem;
                --space-10: 2.5rem;
                --space-12: 3rem;
                --color-bg: #f7f6f2;
                --color-surface: #fbfbf9;
                --color-surface-2: #f3f0ec;
                --color-border: #d4d1ca;
                --color-text: #28251d;
                --color-text-muted: #6f6d66;
                --color-primary: #01696f;
                --color-primary-2: #dbe8e7;
                --color-success: #437a22;
                --color-warning: #da7101;
                --shadow-sm: 0 1px 2px rgba(40, 37, 29, .06);
                --shadow-md: 0 12px 30px rgba(40, 37, 29, .09);
                --radius-md: .9rem;
                --radius-lg: 1.25rem;
                --radius-full: 999px;
                --font-body: 'Satoshi', system-ui, sans-serif
            }

            [data-theme="dark"] {
                --color-bg: #171614;
                --color-surface: #1c1b19;
                --color-surface-2: #22211f;
                --color-border: #393836;
                --color-text: #ece8df;
                --color-text-muted: #afaaa0;
                --color-primary: #4f98a3;
                --color-primary-2: #253336;
                --color-success: #76b85d;
                --color-warning: #fdab43;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, .2);
                --shadow-md: 0 12px 30px rgba(0, 0, 0, .35)
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0
            }

            html, body {
                height: 100%;
                overflow: hidden
            }

            body {
                font-family: var(--font-body);
                font-size: var(--text-base);
                color: var(--color-text);
                background: radial-gradient(circle at top right, color-mix(in srgb, var(--color-primary) 8%, transparent), transparent 32%), var(--color-bg)
            }

            button, input {
                font: inherit;
                color: inherit
            }

            button {
                border: 0;
                background: none;
                cursor: pointer
            }

            .shell {
                display: grid;
                grid-template-columns:280px 1fr;
                grid-template-rows:auto 1fr;
                height: 100dvh
            }

            .sidebar {
                grid-row: 1/-1;
                padding: var(--space-6);
                border-right: 1px solid color-mix(in srgb, var(--color-text) 12%, transparent);
                background: color-mix(in srgb, var(--color-surface) 92%, transparent);
                backdrop-filter: blur(10px);
                overflow: auto
            }

            .header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: var(--space-4);
                padding: var(--space-5) var(--space-6);
                border-bottom: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                background: color-mix(in srgb, var(--color-bg) 75%, transparent);
                backdrop-filter: blur(14px)
            }

            .main {
                overflow: auto;
                padding: var(--space-6)
            }

            .logo {
                display: flex;
                gap: var(--space-3);
                align-items: center;
                margin-bottom: var(--space-8)
            }

            .logo-mark {
                /* width: 40px;
                height: 40px; */
                border-radius: 14px;
                background: linear-gradient(135deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 45%, white));
                display: grid;
                place-items: center;
                box-shadow: var(--shadow-md);
                color: #fff
            }

            .logo-text strong {
                display: block;
                font-size: var(--text-sm)
            }

            .logo-text span, .muted, .hint {
                color: var(--color-text-muted);
                font-size: var(--text-sm)
            }

            .btn {
                min-height: 44px;
                padding: 0 var(--space-4);
                border-radius: var(--radius-full);
                border: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                background: var(--color-surface)
            }

            .btn.primary {
                background: var(--color-primary);
                color: #fff;
                border-color: transparent
            }

            .grid {
                display: grid;
                gap: var(--space-5)
            }

            .kpis {
                grid-template-columns:repeat(4, minmax(0, 1fr));
                margin-bottom: var(--space-6)
            }

            .content {
                grid-template-columns:minmax(0, 1.35fr) minmax(320px, .65fr)
            }

            .card {
                background: color-mix(in srgb, var(--color-surface) 92%, transparent);
                border: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                border-radius: var(--radius-lg);
                box-shadow: var(--shadow-sm)
            }

            .panel, .kpi {
                padding: var(--space-5)
            }

            .kpi label {
                font-size: var(--text-xs);
                text-transform: uppercase;
                letter-spacing: .08em;
                color: var(--color-text-muted)
            }

            .kpi strong {
                display: block;
                margin-top: var(--space-3);
                font-size: clamp(1.8rem, 1.6rem + 1vw, 2.5rem);
                line-height: 1;
                font-variant-numeric: tabular-nums
            }

            .pill {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: var(--space-3) var(--space-4);
                border-radius: var(--radius-md);
                background: var(--color-surface);
                border: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                margin-bottom: var(--space-3)
            }

            .split {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: var(--space-4)
            }

            table {
                width: 100%;
                border-collapse: collapse
            }

            th, td {
                text-align: left;
                padding: var(--space-3);
                border-bottom: 1px solid color-mix(in srgb, var(--color-text) 8%, transparent);
                font-size: var(--text-sm);
                vertical-align: top
            }

            th {
                color: var(--color-text-muted);
                font-size: var(--text-xs);
                text-transform: uppercase;
                letter-spacing: .08em
            }

            .badge {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: .38rem .7rem;
                border-radius: 999px;
                font-size: var(--text-xs);
                background: var(--color-primary-2);
                color: var(--color-primary)
            }

            .dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: currentColor
            }

            .metric {
                height: 8px;
                border-radius: 999px;
                background: var(--color-surface-2);
                overflow: hidden;
                min-width: 90px
            }

            .metric > i {
                display: block;
                height: 100%;
                background: linear-gradient(90deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 55%, white));
                width: 0
            }

            .metric.rx > i {
                background: linear-gradient(90deg, #7c3aed, #c4b5fd)
            }

            .mini-list {
                display: grid;
                gap: var(--space-3)
            }

            .mini {
                padding: var(--space-4);
                border-radius: var(--radius-md);
                background: var(--color-surface);
                border: 1px solid color-mix(in srgb, var(--color-text) 9%, transparent)
            }

            .login {
                max-width: 420px;
                margin: 8vh auto;
                padding: var(--space-6)
            }

            .field {
                display: grid;
                gap: 8px;
                margin-bottom: var(--space-4)
            }

            .field label {
                font-size: var(--text-sm);
                font-weight: 700
            }

            .field input {
                min-height: 48px;
                padding: 0 14px;
                border-radius: 14px;
                border: 1px solid color-mix(in srgb, var(--color-text) 12%, transparent);
                background: var(--color-surface)
            }

            .hidden {
                display: none !important
            }

            .empty {
                text-align: center;
                padding: var(--space-8);
                color: var(--color-text-muted)
            }

            .mono {
                font-variant-numeric: tabular-nums
            }

            .bars {
                display: grid;
                gap: 8px;
                min-width: 180px
            }

            .bars .row {
                display: grid;
                gap: 4px
            }

            .bars .label {
                font-size: var(--text-xs);
                color: var(--color-text-muted)
            }

            .bars .track {
                height: 8px;
                border-radius: 999px;
                background: var(--color-surface-2);
                overflow: hidden
            }

            .bars .fill {
                height: 100%;
                border-radius: 999px
            }

            .bars .fill.tx {
                background: linear-gradient(90deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 55%, white))
            }

            .bars .fill.rx {
                background: linear-gradient(90deg, #7c3aed, #c4b5fd)
            }

            @media (max-width: 1100px) {
                .kpis, .content {
                    grid-template-columns:1fr 1fr
                }
            }

            @media (max-width: 860px) {
                html, body {
                    overflow: auto
                }

                .shell {
                    grid-template-columns:1fr;
                    grid-template-rows:auto auto 1fr;
                    height: auto
                }

                .sidebar {
                    grid-row: auto;
                    border-right: 0;
                    border-bottom: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent)
                }

                .kpis, .content {
                    grid-template-columns:1fr
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
                border-radius: var(--radius-full);
                color: var(--color-text-muted);
                font-weight: 500;
                font-size: var(--text-sm);
                border: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                background: var(--color-surface);
                transition: all 0.2s ease;
            }

            .nav-link:hover {
                color: var(--color-text);
                border-color: color-mix(in srgb, var(--color-text) 20%, transparent);
            }

            .nav-link.active {
                background: var(--color-primary);
                color: #fff;
                border-color: transparent;
            }
        </style>
    </head>
    <body>
        <div id="loginView" class="login card hidden">
            <h1 style="font-size:var(--text-xl);margin-bottom:var(--space-3)">Dashboard log-in</h1>
            <p class="muted" style="margin-bottom:var(--space-5)">Plase fill in your details.</p>
            <div class="field"><label for="email">Email</label><input id="email" type="email"
                                                                      placeholder="your_email@polimi.it"></div>
            <div class="field"><label for="password">Password</label><input id="password" type="password"
                                                                            placeholder="••••••••"></div>
            <button class="btn primary" id="loginBtn">Log in</button>
            <p id="loginMsg" class="muted" style="margin-top:var(--space-4)"></p>
        </div>

        <div id="appView" class="shell hidden">
            <aside class="sidebar">
                <div class="logo">
                    <div class="logo-mark">
                        <?php
                        /*
                                                <svg viewBox="0 0 48 48" width="22" height="22" fill="none" stroke="currentColor"
                                                     stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M8 31V17l16-9 16 9v14l-16 9-16-9Z"></path>
                                                    <path d="M24 8v32"></path>
                                                    <path d="M8 17l16 9 16-9"></path>
                                                </svg>
                                                */
                        ?>
                        <svg xmlns="http://www.w3.org/2000/svg"
                             style="background: transparent; background-color: transparent; color-scheme: light dark;"
                             xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" width="40" height="40"
                             viewBox="0 0 820 820"
                             content="&lt;mxfile host=&quot;Electron&quot; scale=&quot;1&quot; border=&quot;0&quot;&gt;&#10;  &lt;diagram name=&quot;Pagina-1&quot; id=&quot;w5U8Qjg8zMQo727rfNdt&quot;&gt;&#10;    &lt;mxGraphModel dx=&quot;2054&quot; dy=&quot;1156&quot; grid=&quot;1&quot; gridSize=&quot;10&quot; guides=&quot;1&quot; tooltips=&quot;1&quot; connect=&quot;1&quot; arrows=&quot;1&quot; fold=&quot;1&quot; page=&quot;1&quot; pageScale=&quot;1&quot; pageWidth=&quot;827&quot; pageHeight=&quot;1169&quot; math=&quot;0&quot; shadow=&quot;0&quot;&gt;&#10;      &lt;root&gt;&#10;        &lt;mxCell id=&quot;0&quot; /&gt;&#10;        &lt;mxCell id=&quot;1&quot; parent=&quot;0&quot; /&gt;&#10;        &lt;mxCell id=&quot;1QW5-auicKBt-xdR9sWB-1&quot; parent=&quot;1&quot; style=&quot;image;aspect=fixed;perimeter=ellipsePerimeter;html=1;align=center;shadow=0;dashed=0;spacingTop=3;image=img/lib/active_directory/cluster_server.svg;&quot; value=&quot;&quot; vertex=&quot;1&quot;&gt;&#10;          &lt;mxGeometry height=&quot;820&quot; width=&quot;820&quot; x=&quot;4&quot; y=&quot;175&quot; as=&quot;geometry&quot; /&gt;&#10;        &lt;/mxCell&gt;&#10;      &lt;/root&gt;&#10;    &lt;/mxGraphModel&gt;&#10;  &lt;/diagram&gt;&#10;&lt;/mxfile&gt;&#10;">
                            <defs/>
                            <g>
                                <g data-cell-id="0">
                                    <g data-cell-id="1">
                                        <g data-cell-id="1QW5-auicKBt-xdR9sWB-1">
                                            <g>
                                                <g>
                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                         xmlns:xlink="http://www.w3.org/1999/xlink" width="820"
                                                         height="820" viewBox="0 0 207.086 207.088"
                                                         id="svg-image-Lx0MoxJBxtXet49uw5OZ" x="0" y="0"
                                                         style="font-family: initial;">
                                                        <style/>
                                                        <defs>
                                                            <linearGradient xlink:href="#B" id="A" x1="97.23"
                                                                            y1="235.365" x2="97.511" y2="119.946"/>
                                                            <linearGradient id="B" xlink:href="#N">
                                                                <stop offset="0" stop-color="#05239a"/>
                                                                <stop offset="1" stop-color="#91bcf8"/>
                                                            </linearGradient>
                                                            <linearGradient xlink:href="#D" id="C" x1="61.229"
                                                                            y1="227.607" x2="60.745" y2="131.658"/>
                                                            <linearGradient id="D" xlink:href="#N">
                                                                <stop offset="0" stop-color="#0677fc"/>
                                                                <stop offset="1" stop-color="#8fcafe"/>
                                                            </linearGradient>
                                                            <linearGradient xlink:href="#F" id="E" x1="86.488"
                                                                            y1="144.252" x2="86.636" y2="105.838"/>
                                                            <linearGradient id="F" xlink:href="#N">
                                                                <stop offset="0" stop-color="#a8defe"/>
                                                                <stop offset="1" stop-color="#12a7fc"/>
                                                            </linearGradient>
                                                            <linearGradient xlink:href="#B" id="G" x1="140.712"
                                                                            y1="246.944" x2="140.992" y2="131.525"/>
                                                            <linearGradient xlink:href="#D" id="H" x1="104.711"
                                                                            y1="239.186" x2="104.227" y2="143.237"/>
                                                            <linearGradient xlink:href="#F" id="I" x1="129.97"
                                                                            y1="155.831" x2="130.117" y2="117.416"/>
                                                            <linearGradient id="J" x1="108.796" y1="84.566" x2="108.501"
                                                                            y2="60.626" xlink:href="#N">
                                                                <stop offset="0" stop-color="#b0e1fe"/>
                                                                <stop offset="1" stop-color="#10a6fc"/>
                                                            </linearGradient>
                                                            <linearGradient id="K" x1="108.601" y1="106.522"
                                                                            x2="108.831" y2="77.978" xlink:href="#N">
                                                                <stop offset="0" stop-color="#0a279b"/>
                                                                <stop offset="1" stop-color="#9abfe9"/>
                                                            </linearGradient>
                                                            <linearGradient id="L" x1="107.487" y1="239.367"
                                                                            x2="108.804" y2="57.377" xlink:href="#N">
                                                                <stop offset="0" stop-color="#fec225"/>
                                                                <stop offset="1" stop-color="#e3611e"/>
                                                            </linearGradient>
                                                            <path id="M" d="M55.345 162.55v1.736l8.704 4.776v-1.736z"/>
                                                            <linearGradient id="N" gradientUnits="userSpaceOnUse"/>
                                                        </defs>
                                                        <g transform="translate(-5.551 -44.779)">
                                                            <path d="M109.094 44.78C51.932 44.78 5.55 91.16 5.55 148.322s46.38 103.545 103.543 103.545 103.543-46.383 103.543-103.545S166.255 44.78 109.094 44.78z"
                                                                  fill="#fff" paint-order="normal" class="B"/>
                                                            <circle cx="109.235" cy="148.52" r="92.472" fill="url(#L)"
                                                                    paint-order="normal"/>
                                                            <path d="M59.594 86.164v44.594h2.5V88.664h91.238v41.56h2.5v-44.06z"
                                                                  fill="#0073fc" class="B"/>
                                                            <path d="M121.19 210.642l-48.518 25.816v-91.784l48.518-26.17z"
                                                                  fill="url(#A)"/>
                                                            <path d="M72.673 236.458l-24.248-13.396v-91.66l24.248 13.272"
                                                                  fill="url(#C)"/>
                                                            <path d="M121.19 118.503l-48.518 26.17-24.248-13.272 48.367-25.8z"
                                                                  fill="url(#E)"/>
                                                            <g fill="#fff" class="B">
                                                                <use xlink:href="#M"/>
                                                                <use xlink:href="#M" y="8.75"/>
                                                            </g>
                                                            <path d="M72.673 144.674l48.518-26.17zm0 91.784v-91.784l-24.248-13.272m24.248 105.056l-24.248-13.396v-91.66l48.367-25.8 24.4 12.91v92.14z"
                                                                  fill="none" stroke="#fff" class="C"/>
                                                            <path d="M164.673 222.22l-48.518 25.816v-91.784l48.518-26.17z"
                                                                  fill="url(#G)"/>
                                                            <path d="M116.155 248.037L91.906 234.64v-91.66l24.248 13.272"
                                                                  fill="url(#H)"/>
                                                            <path d="M164.673 130.08l-48.518 26.17-24.248-13.272 48.367-25.8z"
                                                                  fill="url(#I)"/>
                                                            <g fill="#fff" class="B">
                                                                <use xlink:href="#M" x="43.481" y="11.578"/>
                                                                <use xlink:href="#M" x="43.481" y="20.34"/>
                                                            </g>
                                                            <path d="M116.155 156.252l48.518-26.17zm0 91.784v-91.784L91.906 142.98m24.248 105.056L91.906 234.64v-91.66l48.367-25.8 24.4 12.91v92.14z"
                                                                  fill="none" stroke="#fff" class="C"/>
                                                            <path d="M108.5 60.626h.002c7.498-.014 16.52 1.187 23.512 3.362 3.495 1.088 6.484 2.434 8.46 3.87s2.747 2.758 2.747 3.882c0 1.73-.84 3.33-2.74 4.982s-4.79 3.17-8.263 4.39c-6.946 2.44-16.192 3.73-24.708 3.73-9.53 0-18.527-1.606-24.99-4.134-3.23-1.264-5.822-2.77-7.495-4.297s-2.343-2.926-2.343-4.18c0-.68.395-1.78 1.683-3.09s3.398-2.74 6.342-4.016c5.89-2.55 15.096-4.5 27.792-4.5z"
                                                                  fill="url(#J)" class="B"/>
                                                            <path d="M142.85 76.17l.14 19.605c-5.168 6.298-18.605 10.653-34.91 10.653-6.17 0-13.557-.676-20.114-2.423-6.326-1.685-11.757-4.44-14.72-8.146L73.2 76.326c.045.04.08.087.124.127 2.123 1.872 4.996 3.426 8.436 4.726 6.88 2.6 16.046 4.15 25.792 4.15 8.71 0 18.064-1.234 25.41-3.728 3.674-1.247 6.852-2.798 9.234-4.8.233-.196.422-.425.64-.633z"
                                                                  fill="url(#K)" class="B"/>
                                                            <path d="M108.462 59.552c-12.175 0-21.134 1.64-27.183 3.98-3.025 1.17-5.327 2.508-6.954 3.987s-2.644 3.172-2.644 5h0l.044 24.29.355.444c3.504 4.405 9.512 7 16.1 8.62s13.79 2.22 19.858 2.22c16.318 0 30.058-3.575 36.014-10.905l.37-.455-.188-24.624c0-2.503-1.75-4.487-4.06-5.985s-5.353-2.678-8.82-3.642c-6.935-1.927-15.552-2.94-22.88-2.93zm.003 3.266h.002c7.016-.012 15.46.992 22.003 2.8 3.27.91 6.068 2.035 7.917 3.234s2.57 2.305 2.57 3.245c0 1.447-.786 2.784-2.563 4.165s-4.483 2.65-7.733 3.67c-6.5 2.04-15.153 3.118-23.122 3.118-8.92 0-17.338-1.342-23.385-3.456-3.023-1.057-5.448-2.315-7.014-3.592s-2.192-2.446-2.192-3.495c0-.568.37-1.488 1.575-2.584s3.18-2.29 5.935-3.357c5.51-2.13 14.127-3.76 26.008-3.76zm32.54 15.48l.132 17.188c-4.9 5.52-17.646 9.34-33.108 9.34-5.85 0-12.858-.593-19.077-2.124-6-1.477-11.15-3.893-13.96-7.142l-.032-17.125c.042.035.075.077.118.112 2.013 1.64 4.74 3.003 8 4.143 6.525 2.28 15.22 3.638 24.462 3.638 8.26 0 17.132-1.082 24.1-3.268 3.484-1.093 6.5-2.453 8.758-4.207.22-.172.4-.373.607-.555z"
                                                                  fill="#fff" class="B"/>
                                                            <path d="M109.094 48.78c-55 0-99.543 44.543-99.543 99.543s44.543 99.545 99.543 99.545 99.543-44.545 99.543-99.545-44.543-99.543-99.543-99.543zm0 7.072a92.47 92.47 0 0 1 92.472 92.472 92.47 92.47 0 0 1-92.472 92.472 92.47 92.47 0 0 1-92.472-92.472 92.47 92.47 0 0 1 92.472-92.472z"
                                                                  fill="#0073fc" paint-order="normal" class="B"/>
                                                        </g>
                                                    </svg>
                                                </g>
                                            </g>
                                        </g>
                                    </g>
                                </g>
                            </g>
                        </svg>
                    </div>
                    <div class="logo-text">
                        <strong>Tenant Live Overview</strong>
                        <span>Neutral Host Management Platform</span>
                    </div>
                </div>

                <section>
                    <h2 style="font-size:var(--text-sm);text-transform:uppercase;letter-spacing:.08em;margin-bottom:var(--space-4);color:var(--color-text-muted)">
                        Registered tenants
                    </h2>
                    <div id="tenantList"></div>
                </section>

                <section style="margin-top:var(--space-8)">
                    <h2 style="font-size:var(--text-sm);text-transform:uppercase;letter-spacing:.08em;margin-bottom:var(--space-4);color:var(--color-text-muted)">
                        Operational notes</h2>
                    <div class="mini-list">
                        <div class="mini"><strong>Current mode</strong>
                            <?php if (defined("DEMO") && DEMO): ?>
                                <p class="hint">Demo</p>
                            <?php else: ?>
                                <p class="hint">Real-time</p>
                            <?php endif; ?>
                        </div>
                        <div class="mini"><strong>For non-commercial use</strong>
                            <p class="hint">Developed by <a href="https://andreacaravano.net">Andrea Caravano</a> as
                                part of the Neutral Host Management Platform project</p></div>
                    </div>
                </section>
            </aside>

            <header class="header">
                <div>
                    <h1 style="font-size:var(--text-xl);line-height:1.05">Global view</h1>
                    <p class="muted" style="margin-top:var(--space-2)">Tenant-aware vision on live sessions</p>
                </div>
                <nav class="nav-menu">
                    <a href="index.php" class="nav-link active">Overview</a>
                    <a href="prb-map.php" class="nav-link">PRB Heatmap</a>
                    <a href="traffic-control.php" class="nav-link">Traffic Control</a>
                    <a href="resource-control.php" class="nav-link">Resource Control</a>
                </nav>
                <div class="split">
                    <span id="lastUpdate" class="muted">Updating…</span>
                    <button class="btn" id="themeBtn">◐</button>
                    <button class="btn" id="logoutBtn">Logout</button>
                    <button class="btn primary" id="refreshBtn">Update</button>
                </div>
            </header>

            <main class="main">
                <section class="grid kpis" id="kpiGrid"></section>

                <section class="grid content">
                    <article class="card panel">
                        <div class="split" style="margin-bottom:var(--space-4)">
                            <div>
                                <h2 style="font-size:var(--text-sm);text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted)">
                                    Users</h2>
                                <p class="hint">Performance, usage and radio metrics</p>
                            </div>
                            <span class="badge"><span id="viewerLabel">Viewer</span></span>
                        </div>

                        <div style="overflow:auto">
                            <table>
                                <thead>
                                    <tr>
                                        <th>IMSI</th>
                                        <th>Activity</th>
                                        <th>TX / RX</th>
                                        <th>Duration</th>
                                        <th>Radio</th>
                                        <th>PRB</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTbody"></tbody>
                            </table>
                        </div>
                    </article>

                    <aside class="grid">
                        <section class="card panel">
                            <h2 style="font-size:var(--text-sm);text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted);margin-bottom:var(--space-4)">
                                Active sessions</h2>
                            <div class="mini-list" id="sessionList"></div>
                        </section>
                    </aside>
                </section>
            </main>
        </div>

        <script>
            (() => {
                const qs = s => document.querySelector(s);
                const num = v => new Intl.NumberFormat('it-IT', {maximumFractionDigits: 2}).format(Number(v || 0));
                const dt = v => v ? new Date(v).toLocaleString('it-IT') : '—';
                const duration = s => {
                    s = Number(s || 0);
                    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
                    return h > 0 ? `${h}h ${m}m` : (m > 0 ? `${m}m ${sec}s` : `${sec}s`);
                };
                const esc = str => String(str ?? '').replace(/[&<>"']/g, m => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[m]));
                const scale = (v, m) => m > 0 ? (v / m) * 100 : 0;

                const trafficBars = (tx, rx, maxTx, maxRx) => {
                    tx = Number(tx || 0);
                    rx = Number(rx || 0);

                    return `
      <div class="bars">
        <div class="row">
          <div class="label">TX ${num(tx)}</div>
          <div class="track"><div class="fill tx" style="width:${scale(tx, maxTx)}%"></div></div>
        </div>
        <div class="row">
          <div class="label">RX ${num(rx)}</div>
          <div class="track"><div class="fill rx" style="width:${scale(rx, maxRx)}%"></div></div>
        </div>
      </div>
    `;
                };

                const prbBars = kpm => {
                    const ul = Math.max(0, Math.min(100, Number(kpm.PrbTotUl_pct || 0)));
                    const dl = Math.max(0, Math.min(100, Number(kpm.PrbTotDl_pct || 0)));
                    return `
      <div class="bars">
        <div class="row">
          <div class="label">UL PRB ${ul}%</div>
          <div class="track"><div class="fill tx" style="width:${ul}%"></div></div>
        </div>
        <div class="row">
          <div class="label">DL PRB ${dl}%</div>
          <div class="track"><div class="fill rx" style="width:${dl}%"></div></div>
        </div>
      </div>
    `;
                };

                const themeInitial = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', themeInitial);
                let theme = themeInitial;
                qs('#themeBtn').addEventListener('click', () => {
                    theme = theme === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', theme);
                });

                let stream = null;
                let poller = null;

                async function api(action, options = {}) {
                    const res = await fetch(`api.php?action=${action}`, {
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                        ...options
                    });
                    if (res.status === 401) throw new Error('unauthorized');
                    return res.json();
                }

                function renderKpis(data) {
                    const k = data.kpis || {};
                    qs('#kpiGrid').innerHTML = [
                        ['Active sessions', num(k.active_sessions), 'Users that completed authentication'],
                        ['TX MB', num(k.total_tx_mb), 'on active sessions'],
                        ['RX MB', num(k.total_rx_mb), 'on active sessions']
                    ].map(([label, value, hint]) => `
      <div class="card kpi">
        <label>${label}</label>
        <strong class="mono">${value}</strong>
        <span class="muted">${hint}</span>
      </div>
    `).join('');
                }

                function renderUsers(data) {
                    const users = data.users || [];
                    qs('#usersTbody').innerHTML = users.length ? users.map(u => {
                        const session = data.live_metrics?.sessions?.find(s => s.imsi === u.imsi) || {};
                        const live = session.imsi ? (data.live_metrics.metrics?.[String(session.imsi)] || {}) : {};
                        const kpm = session.amf_id ? (data.live_metrics.kpm?.[String(session.amf_id)] || {}) : {};

                        const users = data.users || [];
                        const maxTx = Math.max(...users.map(u => Number(u.total_tx_mb || 0)), 1);
                        const maxRx = Math.max(...users.map(u => Number(u.total_rx_mb || 0)), 1);

                        return `
        <tr>
          <td>
            <strong>${esc(u.imsi)}</strong>
            <div class="muted">Last seen ${dt(u.last_seen)}</div>
            <?php if (DEBUG): ?>
                <div class="muted">AMF ${esc(session.amf_id || '—')} · UE ${esc(session.ue_id || '—')}</div>
            <?php endif; ?>
          </td>
          <td>
            ${u.active_session_count >= 1 ? '<span class="badge"><i class="dot"></i></span>' : '<span></span>'}
            <?php if (DEBUG): ?>
                <div class="muted">Tot ${num(u.session_count)}</div>
            <?php endif; ?>
          </td>
          <td>${trafficBars(u.total_tx_mb, u.total_rx_mb, maxTx, maxRx)}</td>
          <td class="mono">
            ${duration(u.total_duration_seconds)}
            <div class="muted">Since ${dt(u.first_seen)}</div>
          </td>
          <td class="mono">
            RSRP ${esc(live.rsrp ?? '—')} · SNR ${esc(live.snr ?? '—')}
            <div class="muted">MCS UL ${esc(live.mcs_ul ?? '—')} · DL ${esc(live.mcs_dl ?? '—')}</div>
          </td>
          <td>${prbBars(kpm)}</td>
        </tr>
      `;
                    }).join('') : '<tr><td colspan="6" class="empty">Nessun IMSI visibile</td></tr>';
                }

                function renderSessions(data) {
                    const sessions = data.recent_sessions || [];
                    qs('#sessionList').innerHTML = sessions.length ? sessions.map(s => {
                        const live = data.live_metrics?.metrics?.[String(s.imsi)] || {};
                        const kpm = data.live_metrics?.kpm?.[String(s.amf_id)] || {};
                        return `
        <article class="mini">
          <div class="split">
            <strong>${esc(s.imsi)}</strong>
            <span style="color:${s.end === null ? 'var(--color-success)' : 'var(--color-warning)'}">${s.end === null ? 'Active' : 'Closed'}</span>
          </div>
          <?php if (DEBUG): ?>
            <p class="hint">UE ${esc(s.ue_id)} · AMF ${esc(s.amf_id)} · RNTI ${esc(s.rnti)}</p>
          <?php endif; ?>
          <p class="mono">TX ${num(s.tx_mb)} MB · RX ${num(s.rx_mb)} MB</p>
          <p class="mono">RSRP ${esc(live.rsrp ?? '—')} · SNR ${esc(live.snr ?? '—')}</p>
          <p class="hint">PRB UL ${esc(kpm.PrbTotUl_pct ?? '—')}% · DL ${esc(kpm.PrbTotDl_pct ?? '—')}%</p>
          <p class="hint">From ${dt(s.start)} · duration ${duration(s.duration_seconds)}</p>
        </article>
      `;
                    }).join('') : '<div class="empty">No currently active session</div>';
                }

                function render(data) {
                    qs('#viewerLabel').textContent = data.viewer ? `${data.viewer.name} ${data.viewer.surname}` : 'Viewer';
                    qs('#tenantList').innerHTML = (data.tenants || []).map(t => `
      <div class="pill"><span>PLMN ${esc(t)}</span><strong>${esc(t)}</strong></div>
    `).join('') || '<div class="empty">No tenant is assigned to the current user</div>';

                    renderKpis(data);
                    renderUsers(data);
                    renderSessions(data);

                    <?php if (DEBUG): ?>
                    qs('#lastUpdate').textContent = `Updated on ${new Date().toLocaleTimeString('it-IT')} · ${data._meta?.cache === 'redis' ? 'Redis' : 'Postgres'}`;
                    <?php else: ?>
                    qs('#lastUpdate').textContent = `Updated on ${new Date().toLocaleTimeString('it-IT')}`;
                    <?php endif; ?>
                }

                async function loadDashboard() {
                    try {
                        const data = await api('dashboard');
                        qs('#loginView').classList.add('hidden');
                        qs('#appView').classList.remove('hidden');
                        render(data);
                        return true;
                    } catch (e) {
                        qs('#appView').classList.add('hidden');
                        qs('#loginView').classList.remove('hidden');
                        return false;
                    }
                }

                const REFRESH_INTERVAL = 5000;

                function startPolling() {
                    clearInterval(poller);
                    poller = setInterval(loadDashboard, REFRESH_INTERVAL);
                }

                function startRealtime() {
                    if (window.EventSource) {
                        try {
                            stream = new EventSource('api.php?action=stream');
                            stream.addEventListener('dashboard', ev => render(JSON.parse(ev.data)));
                            stream.onerror = () => {
                                stream.close();
                                stream = null;
                                startPolling();
                            };
                            return;
                        } catch (_) {
                        }
                    }
                    startPolling();
                }

                qs('#refreshBtn').addEventListener('click', loadDashboard);
                qs('#logoutBtn').addEventListener('click', async () => {
                    <?php if (defined("DEMO") && DEMO): ?>
                    return;
                    <?php endif; ?>

                    await api('logout');
                    if (stream) stream.close();
                    clearInterval(poller);
                    await loadDashboard();
                });

                qs('#loginBtn').addEventListener('click', async () => {
                    const email = qs('#email').value.trim();
                    const password = qs('#password').value;
                    const res = await api('login', {
                        method: 'POST',
                        body: JSON.stringify({email, password})
                    });
                    qs('#loginMsg').textContent = res.ok ? 'Logging in...' : 'Invalid credentials';
                    if (res.ok) {
                        await loadDashboard();
                        startRealtime();
                    }
                });

                loadDashboard().then(ok => {
                    if (ok) startRealtime();
                });
            })();
        </script>
    </body>
</html>
