<?php
require_once __DIR__ . '/config.php';
$isLoggedIn = isAdminLoggedIn();
?>
<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MeshCore Administravimo Pultas</title>
    
    <!-- Šriftai ir stiliai -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-base: #141414;
            --bg-surface: #1e1e1e;
            --bg-header: #252525;
            --bg-card: #252525;
            --bg-card-hover: #2c2c2c;
            --border-color: #383838;
            --border-light: #444444;

            --accent-cyan: #00d2ff;
            --accent-blue: #007bff;
            --accent-green: #28a745;
            --accent-orange: #fd7e14;
            --accent-amber: #ffc107;
            --accent-red: #dc3545;

            --text-main: #ffffff;
            --text-secondary: #cccccc;
            --text-muted: #888888;
            --text-dim: #666666;

            --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            --font-mono: SFMono-Regular, Menlo, Monaco, Consolas, "JetBrains Mono", monospace;
            --radius-sm: 4px;
            --radius-md: 6px;
            --radius-lg: 8px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }

        /* Navigacijos viršutinė juosta */
        header.admin-navbar {
            height: 52px;
            background: #333333;
            border-bottom: 1px solid #222222;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .brand-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo {
            width: 30px;
            height: 30px;
            background: #252525;
            border: 1px solid var(--accent-cyan);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 10px rgba(0, 210, 255, 0.3);
        }

        .brand-logo svg {
            width: 18px;
            height: 18px;
            fill: var(--accent-cyan);
        }

        .brand-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.01em;
        }

        .brand-badge {
            font-size: 0.68rem;
            color: var(--accent-cyan);
            background: rgba(0, 210, 255, 0.1);
            border: 1px solid var(--accent-cyan);
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            font-family: var(--font-mono);
            font-weight: 600;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            background: rgba(0, 210, 255, 0.1);
            border: 1px solid var(--accent-cyan);
            color: var(--accent-cyan);
            padding: 6px 14px;
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn:hover {
            background: rgba(0, 210, 255, 0.25);
            color: #ffffff;
            box-shadow: 0 0 10px rgba(0, 210, 255, 0.3);
        }

        .btn-secondary {
            background: #252525;
            border-color: var(--border-light);
            color: var(--text-secondary);
        }

        .btn-secondary:hover {
            background: #333333;
            border-color: var(--text-muted);
            color: #ffffff;
            box-shadow: none;
        }

        .btn-danger {
            background: rgba(220, 53, 69, 0.15);
            border-color: var(--accent-red);
            color: #ff6b81;
        }

        .btn-danger:hover {
            background: rgba(220, 53, 69, 0.3);
            color: #ffffff;
            box-shadow: 0 0 10px rgba(220, 53, 69, 0.3);
        }

        .btn-success {
            background: rgba(40, 167, 69, 0.15);
            border-color: var(--accent-green);
            color: #2ed573;
        }

        .btn-success:hover {
            background: rgba(40, 167, 69, 0.3);
            color: #ffffff;
            box-shadow: 0 0 10px rgba(40, 167, 69, 0.3);
        }

        .btn-sm {
            padding: 3px 8px;
            font-size: 0.72rem;
            border-radius: var(--radius-sm);
        }

        /* Prisijungimo ekranas */
        .login-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 420px;
            padding: 32px 28px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6);
            text-align: center;
        }

        .login-card .logo-box {
            width: 52px;
            height: 52px;
            background: #1e1e1e;
            border: 1px solid var(--accent-cyan);
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            box-shadow: 0 0 16px rgba(0, 210, 255, 0.3);
        }

        .login-card .logo-box svg {
            width: 28px;
            height: 28px;
            fill: var(--accent-cyan);
        }

        .login-card h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
        }

        .login-card p {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 16px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            background: #1e1e1e;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 10px 12px;
            font-size: 0.85rem;
            color: #ffffff;
            font-family: inherit;
            outline: none;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 10px rgba(0, 210, 255, 0.25);
            background: #222222;
        }

        /* Administravimo pulto pagrindinis turinys */
        .admin-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            max-width: 1540px;
            width: 100%;
            margin: 0 auto;
            padding: 18px 20px;
            gap: 16px;
        }

        /* Suvestinės statistikos blokas */
        .stats-banner {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }

        .stat-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            position: relative;
            overflow: hidden;
        }

        .stat-box::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--accent-cyan);
        }

        .stat-box.green::before { background: var(--accent-green); }
        .stat-box.orange::before { background: var(--accent-orange); }
        .stat-box.red::before { background: var(--accent-red); }
        .stat-box.blue::before { background: var(--accent-blue); }

        .stat-box .val {
            font-size: 1.35rem;
            font-weight: 700;
            font-family: var(--font-mono);
            color: #ffffff;
            line-height: 1.2;
        }

        .stat-box .lbl {
            font-size: 0.70rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        /* Kortelių skirtukai (Tabs) */
        .tab-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 2px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-muted);
            padding: 10px 18px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .tab-btn:hover {
            color: #ffffff;
        }

        .tab-btn.active {
            color: var(--accent-cyan);
            border-bottom-color: var(--accent-cyan);
        }

        .tab-content {
            display: none;
            flex-direction: column;
            gap: 14px;
        }

        .tab-content.active {
            display: flex;
        }

        /* Filtravimo juosta */
        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 12px 16px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .filter-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .filter-chip {
            background: rgba(0, 210, 255, 0.12);
            border: 1px solid var(--accent-cyan);
            color: var(--accent-cyan);
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .filter-chip .close-chip {
            cursor: pointer;
            font-weight: bold;
        }

        /* Lentelės stilius */
        .table-wrapper {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow-x: auto;
            box-shadow: 0 4px 18px rgba(0,0,0,0.3);
        }

        table.admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.80rem;
            text-align: left;
        }

        table.admin-table th {
            background: #1e1e1e;
            color: var(--text-muted);
            padding: 10px 14px;
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
            text-transform: uppercase;
            font-size: 0.68rem;
            letter-spacing: 0.04em;
        }

        table.admin-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #303030;
            vertical-align: middle;
            color: var(--text-secondary);
        }

        table.admin-table tbody tr:hover {
            background: #2a2a2a;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            font-size: 0.68rem;
            font-weight: 700;
            font-family: var(--font-mono);
            letter-spacing: 0.02em;
            white-space: nowrap;
        }

        .badge-blue { background: var(--accent-blue); color: #ffffff; }
        .badge-cyan { background: rgba(0, 210, 255, 0.15); color: var(--accent-cyan); border: 1px solid var(--accent-cyan); }
        .badge-green { background: rgba(40, 167, 69, 0.15); color: #2ed573; border: 1px solid var(--accent-green); }
        .badge-red { background: rgba(220, 53, 69, 0.15); color: #ff6b81; border: 1px solid var(--accent-red); }
        .badge-amber { background: rgba(255, 193, 7, 0.15); color: var(--accent-amber); border: 1px solid var(--accent-amber); }
        .badge-purple { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid #a855f7; }
        .badge-gray { background: #333333; color: var(--text-muted); border: 1px solid var(--border-light); }

        .copy-btn {
            background: transparent;
            border: none;
            color: var(--text-dim);
            cursor: pointer;
            padding: 2px 4px;
            font-size: 0.72rem;
            transition: color 0.15s;
        }

        .copy-btn:hover {
            color: var(--accent-cyan);
        }

        /* Puslapiavimas */
        .pagination-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-top: 1px solid var(--border-color);
            background: #222222;
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        /* Modalas */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(6px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 650px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 14px 45px rgba(0, 0, 0, 0.8);
        }

        .modal-card.modal-lg {
            max-width: 850px;
        }

        .modal-header {
            padding: 14px 20px;
            background: #1e1e1e;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #ffffff;
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: 1.4rem;
            color: var(--text-muted);
            cursor: pointer;
            line-height: 1;
        }

        .modal-close:hover {
            color: #ffffff;
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .modal-footer {
            padding: 12px 20px;
            background: #1e1e1e;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* JSON Pre peržiūra */
        pre.json-viewer {
            background: #141414;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 12px;
            color: #00d2ff;
            font-family: var(--font-mono);
            font-size: 0.76rem;
            max-height: 380px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* Notifikacijos pranešimas */
        .toast-msg {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #252525;
            border: 1px solid var(--accent-cyan);
            color: #ffffff;
            padding: 10px 18px;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.6);
            z-index: 3000;
            display: none;
            align-items: center;
            gap: 10px;
        }

        .toast-msg.success { border-color: var(--accent-green); }
        .toast-msg.error { border-color: var(--accent-red); }

        /* Mobiliems įrenginiams */
        @media (max-width: 768px) {
            .admin-container { padding: 10px; }
            .tab-btn { padding: 8px 12px; font-size: 0.78rem; }
            .stats-banner { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

    <!-- Viršutinė antraštė -->
    <header class="admin-navbar">
        <div class="brand-container" style="cursor: pointer;" onclick="switchTab('nodes')" title="Eiti į pagrindinį puslapį">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                </svg>
            </div>
            <span class="brand-title">MeshCore Admin</span>
            <span class="brand-badge">Eterio Pultas</span>
        </div>

        <div class="nav-links">
            <a href="../" target="_blank" class="btn btn-secondary btn-sm" title="Atidaryti viešąjį žemėlapį">
                <span>🗺️ Žemėlapis</span>
            </a>
            <?php if ($isLoggedIn): ?>
                <button class="btn btn-sm" onclick="refreshCurrentTab()" title="Atnaujinti duomenis">
                    <span>🔄 Atnaujinti</span>
                </button>
                <button class="btn btn-danger btn-sm" onclick="handleLogout()">
                    <span>Atsijungti</span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$isLoggedIn): ?>
        <!-- 1. Prisijungimo forma -->
        <div class="login-wrapper">
            <div class="login-card">
                <div class="logo-box">
                    <svg viewBox="0 0 24 24">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                    </svg>
                </div>
                <h2>Administravimo Pultas</h2>
                <p>Prisijunkite norėdami valdyti kartotuvus, peržiūrėti telemetriją ir analizuoti RAW paketus.</p>
                
                <form id="loginForm" onsubmit="handleLogin(event)">
                    <div class="form-group">
                        <label for="adminPassword">Administratoriaus Slaptažodis</label>
                        <input type="password" id="adminPassword" class="form-control" placeholder="Įveskite slaptažodį..." required autofocus>
                    </div>
                    <div id="loginError" style="color: var(--accent-red); font-size: 0.8rem; margin-bottom: 12px; display: none;"></div>
                    <button type="submit" class="btn" style="width: 100%; justify-content: center; padding: 10px;">
                        <span>Prisijungti</span>
                    </button>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- 2. Pagrindinė administravimo erdvė -->
        <div class="admin-container">
            <!-- Suvestinės statistika -->
            <div class="stats-banner" id="statsBanner">
                <div class="stat-box">
                    <div class="val" id="statNodesCount">-</div>
                    <div class="lbl">Visi Mazgai</div>
                </div>
                <div class="stat-box green">
                    <div class="val" id="statMonitoredCount">-</div>
                    <div class="lbl">Stebimi (Update=1)</div>
                </div>
                <div class="stat-box blue">
                    <div class="val" id="statRawTotal">-</div>
                    <div class="lbl">RAW Paketai</div>
                </div>
                <div class="stat-box orange">
                    <div class="val" id="statQueuePending">-</div>
                    <div class="lbl">Eilėje Laukia</div>
                </div>
                <div class="stat-box red">
                    <div class="val" id="statErrorsCount">-</div>
                    <div class="lbl">Klaidos Eteryje</div>
                </div>
                <div class="stat-box">
                    <div class="val" id="statTelemetryCount">-</div>
                    <div class="lbl">Telemetrijos Įrašai</div>
                </div>
            </div>

            <!-- Skirtukų navigacija -->
            <div class="tab-nav">
                <button class="tab-btn active" id="tabBtn-nodes" onclick="switchTab('nodes')">
                    <span>🛰️ Mazgai & Nustatymai</span>
                </button>
                <button class="tab-btn" id="tabBtn-telemetry" onclick="switchTab('telemetry')">
                    <span>📊 Telemetrijos Įrašai</span>
                </button>
                <button class="tab-btn" id="tabBtn-raw" onclick="switchTab('raw')">
                    <span>📦 RAW Paketai & Debugger</span>
                </button>
                <button class="tab-btn" id="tabBtn-tools" onclick="switchTab('tools')">
                    <span>⚡ Eilė & Įrankiai</span>
                </button>
            </div>

            <!-- SKIRTUKAS 1: MAZGAI & NUSTATYMAI -->
            <div class="tab-content active" id="tab-nodes">
                <div class="filter-card">
                    <div class="filter-group">
                        <input type="text" id="nodesSearch" class="form-control" style="width: 260px;" placeholder="Ieškoti pagal vardą, ID ar raktą..." oninput="onNodesSearchInput()" onkeydown="if(event.key==='Enter') onNodesSearchEnter()">
                        
                        <select id="nodesTypeFilter" class="form-control" style="width: 170px;" onchange="onNodesFilterChange()">
                            <option value="">Visi tipai</option>
                            <option value="repeater">Tik Kartotuvai</option>
                            <option value="chat">Tik Pokalbiai</option>
                            <option value="room">Tik Kambariai</option>
                        </select>

                        <select id="nodesUpdateFilter" class="form-control" style="width: 190px;" onchange="onNodesFilterChange()">
                            <option value="">Visi (Stebimi ir kiti)</option>
                            <option value="1">Tik stebimi (update=1)</option>
                            <option value="0">Tik nestebimi (update=0)</option>
                        </select>
                    </div>

                    <div>
                        <span id="nodesCountBadge" class="badge badge-gray">0 mazgų</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Mazgas</th>
                                <th>Tipas</th>
                                <th>Šaltinis (Botas)</th>
                                <th>Stebimas (Update)</th>
                                <th>Slaptažodis</th>
                                <th>Koordinatės</th>
                                <th>Paskutinį kartą matytas</th>
                                <th style="text-align: right;">Veiksmai</th>
                            </tr>
                        </thead>
                        <tbody id="nodesTableBody">
                            <tr><td colspan="8" style="text-align: center; padding: 24px;">Kraunami mazgai...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SKIRTUKAS 2: TELEMETRIJOS ĮRAŠAI -->
            <div class="tab-content" id="tab-telemetry">
                <div class="filter-card">
                    <div class="filter-group">
                        <div id="telemetryNodeFilterTag" style="display: none;" class="filter-chip">
                            <span>Mazgas: <b id="telemetryActiveNodeText"></b></span>
                            <span class="close-chip" onclick="clearTelemetryNodeFilter()" title="Išvalyti mazgo filtrą">&times;</span>
                        </div>

                        <input type="text" id="telemetryNodeInput" class="form-control" style="width: 180px;" placeholder="Mazgo ID (pvz. b9dd)..." onchange="onTelemetryFilterChange()" onkeydown="if(event.key==='Enter') onTelemetryFilterChange()">
                        
                        <select id="telemetryTypeFilter" class="form-control" style="width: 160px;" onchange="onTelemetryFilterChange()">
                            <option value="">Visi tipai</option>
                            <option value="STATUS">STATUS</option>
                            <option value="TELEMETRY">TELEMETRY</option>
                            <option value="ADVERT">ADVERT</option>
                        </select>

                        <button class="btn btn-secondary btn-sm" onclick="onTelemetryFilterChange()">Filtruoti</button>
                    </div>

                    <div>
                        <span id="telemetryCountBadge" class="badge badge-gray">0 įrašų</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Mazgas</th>
                                <th>Tipas</th>
                                <th>Baterija</th>
                                <th>Temperatūra</th>
                                <th>Signalo Kokybė</th>
                                <th>Veikimo Laikas (Uptime)</th>
                                <th>Paketai RX/TX</th>
                                <th>Užregistruota</th>
                                <th style="text-align: right;">Veiksmai</th>
                            </tr>
                        </thead>
                        <tbody id="telemetryTableBody">
                            <tr><td colspan="10" style="text-align: center; padding: 24px;">Kraunami telemetrijos įrašai...</td></tr>
                        </tbody>
                    </table>
                    
                    <div class="pagination-row">
                        <span id="telemetryPageInfo">Puslapis 1</span>
                        <div style="display: flex; gap: 6px;">
                            <button class="btn btn-secondary btn-sm" id="btnPrevTel" onclick="changeTelemetryPage(-1)">← Ankstesnis</button>
                            <button class="btn btn-secondary btn-sm" id="btnNextTel" onclick="changeTelemetryPage(1)">Kitas →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SKIRTUKAS 3: RAW PAKETAI & DEBUGGER -->
            <div class="tab-content" id="tab-raw">
                <div class="filter-card">
                    <div class="filter-group">
                        <div id="rawNodeFilterTag" style="display: none;" class="filter-chip">
                            <span>Mazgas: <b id="rawActiveNodeText"></b></span>
                            <span class="close-chip" onclick="clearRawNodeFilter()" title="Išvalyti mazgo filtrą">&times;</span>
                        </div>

                        <input type="text" id="rawSearchInput" class="form-control" style="width: 220px;" placeholder="Ieškoti hex, varde ar klaidoje..." oninput="onRawSearchInput()" onkeydown="if(event.key==='Enter') onRawSearchEnter()">
                        
                        <select id="rawStatusFilter" class="form-control" style="width: 170px;" onchange="onRawFilterChange()">
                            <option value="">Visos būsenos</option>
                            <option value="errors">⚠️ Tik KLAIDOS</option>
                            <option value="pending">⏳ Eilėje (neapdoroti)</option>
                            <option value="processed">✅ Apdoroti</option>
                        </select>

                        <select id="rawDirectionFilter" class="form-control" style="width: 140px;" onchange="onRawFilterChange()">
                            <option value="">Visos kryptys</option>
                            <option value="RX">Tik RX (gauti)</option>
                            <option value="TX">Tik TX (išsiųsti)</option>
                        </select>
                    </div>

                    <div>
                        <span id="rawCountBadge" class="badge badge-gray">0 paketų</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Botas</th>
                                <th>Kryptis</th>
                                <th>Siuntėjas</th>
                                <th>Taikinys</th>
                                <th>Ilgis / Radijas</th>
                                <th>Būsena</th>
                                <th>Klaida (jei yra)</th>
                                <th>Gauta Data</th>
                                <th style="text-align: right;">Veiksmai</th>
                            </tr>
                        </thead>
                        <tbody id="rawTableBody">
                            <tr><td colspan="10" style="text-align: center; padding: 24px;">Kraunami RAW paketai...</td></tr>
                        </tbody>
                    </table>

                    <div class="pagination-row">
                        <span id="rawPageInfo">Puslapis 1</span>
                        <div style="display: flex; gap: 6px;">
                            <button class="btn btn-secondary btn-sm" id="btnPrevRaw" onclick="changeRawPage(-1)">← Ankstesnis</button>
                            <button class="btn btn-secondary btn-sm" id="btnNextRaw" onclick="changeRawPage(1)">Kitas →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SKIRTUKAS 4: EILĖ & ĮRANKIAI -->
            <div class="tab-content" id="tab-tools">
                <div class="filter-card">
                    <div>
                        <h4 style="color: #ffffff; font-size: 1rem; margin-bottom: 4px;">⚡ Eilės Apdorojimas Realiu Laiku</h4>
                        <p style="color: var(--text-muted); font-size: 0.8rem;">Rankinis workerio paleidimas: apdoroja neapdorotus RAW paketus tiesiai per naršyklę.</p>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn" onclick="runQueueBatch(20)">Paleisti 20 paketų</button>
                        <button class="btn btn-secondary" onclick="runQueueBatch(50)">Paleisti 50 paketų</button>
                    </div>
                </div>

                <div class="table-wrapper" style="padding: 16px;">
                    <div style="font-size: 0.85rem; font-weight: 600; color: #ffffff; margin-bottom: 8px;">Apdorojimo rezultatas:</div>
                    <pre id="queueOutput" class="json-viewer" style="height: 180px;">Paspauskite mygtuką aukščiau, kad paleistumėte eilės apdorojimą...</pre>
                </div>

                <div class="filter-card" style="display: block;">
                    <h4 style="color: #ffffff; font-size: 1rem; margin-bottom: 8px;">🔗 Sistemos API Nuorodos & ESP32 Integracija</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.82rem;">
                        <div>• <b>/api/nodes</b>: <a href="../api/nodes" target="_blank" style="color: var(--accent-cyan);">Atidaryti viešą mazgų sąrašą (JSON)</a></div>
                        <div>• <b>/api/repyters</b>: <a href="../api/repyters" target="_blank" style="color: var(--accent-cyan);">Atidaryti ESP32 stebimų kartotuvų sąrašą (JSON)</a> (Grąžina tik tuos, kur update=true)</div>
                        <div>• <b>/process_queue.php</b>: <a href="../process_queue.php?limit=20" target="_blank" style="color: var(--accent-cyan);">Atidaryti HTTP Cron Workerį</a> (Galima įsidėti į cPanel Cron kas minutę)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODALAS: MAZGO NUSTATYMAI -->
        <div class="modal-overlay" id="editNodeModal">
            <div class="modal-card">
                <div class="modal-header">
                    <h3 id="editNodeTitle">Mazgo Nustatymai</h3>
                    <button class="modal-close" onclick="closeModal('editNodeModal')">&times;</button>
                </div>
                <form id="editNodeForm" onsubmit="handleSaveNode(event)">
                    <div class="modal-body">
                        <input type="hidden" id="editNodePublicKey">

                        <div class="form-group">
                            <label>Viešasis Raktas (Public Key)</label>
                            <input type="text" id="editNodeKeyDisplay" class="form-control" style="font-family: var(--font-mono); font-size: 0.75rem;" readonly>
                        </div>

                        <div class="form-group">
                            <label for="editNodeName">Mazgo Pavadinimas (Name)</label>
                            <input type="text" id="editNodeName" class="form-control" placeholder="pvz. LT-LM Tilžė B9DD" required>
                        </div>

                        <div class="form-group">
                            <label for="editNodePassword">Valdymo Slaptažodis (ESP32 Botui)</label>
                            <input type="text" id="editNodePassword" class="form-control" placeholder="Slaptažodis kartotuvo telemetrijai siųsti (jei tuščias, bus &quot;&quot;)">
                            <div style="font-size: 0.70rem; color: var(--text-dim); margin-top: 4px;">Šis slaptažodis perduodamas į /api/repyters JSON failą botui.</div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div class="form-group">
                                <label for="editNodeLat">GPS Platuma (Latitude)</label>
                                <input type="number" step="0.0000001" id="editNodeLat" class="form-control" placeholder="pvz. 55.7052">
                            </div>
                            <div class="form-group">
                                <label for="editNodeLon">GPS Ilguma (Longitude)</label>
                                <input type="number" step="0.0000001" id="editNodeLon" class="form-control" placeholder="pvz. 21.1458">
                            </div>
                        </div>

                        <div style="display: flex; gap: 20px; padding: 10px 0;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="editNodeUpdate" style="width: 16px; height: 16px;">
                                <span style="font-weight: 600; color: #2ed573;">Aktyviai stebėti / atnaujinti per botą (update=1)</span>
                            </label>

                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="editNodeIsRepeater" style="width: 16px; height: 16px;">
                                <span>Mazgas yra kartotuvas</span>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editNodeModal')">Atšaukti</button>
                        <button type="submit" class="btn">Išsaugoti Pakeitimus</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODALAS: RAW PAKETO PERŽIŪRA IR DEBUGERIS -->
        <div class="modal-overlay" id="packetModal">
            <div class="modal-card modal-lg">
                <div class="modal-header">
                    <h3 id="packetModalTitle">RAW Paketo Peržiūra #0</h3>
                    <button class="modal-close" onclick="closeModal('packetModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div id="packetMetaRow" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;"></div>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn btn-sm btn-success" id="btnCopyDebugInModal" onclick="copyDebugInfo(activePacketId)" title="Nukopijuoti visą paketo procesą ir duomenis derinimui su AI / Dev">📋 Nukopijuoti derinimui (AI / Dev)</button>
                            <button class="btn btn-sm" id="btnReprocessInModal" onclick="triggerReprocessFromModal()">🔄 Pakartoti Iškodavimą (Reprocess)</button>
                        </div>
                    </div>

                    <div id="packetErrorBox" style="display: none; background: rgba(220, 53, 69, 0.15); border: 1px solid var(--accent-red); color: #ff6b81; padding: 10px 14px; border-radius: var(--radius-sm); font-size: 0.8rem;"></div>

                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">RAW HEX:</span>
                            <button class="copy-btn" onclick="copyText(document.getElementById('modalRawHex').innerText)">📋 Kopijuoti HEX</button>
                        </div>
                        <pre id="modalRawHex" class="json-viewer" style="max-height: 80px;"></pre>
                    </div>

                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">Iškoduotas Turinys (decoded_json):</span>
                            <button class="copy-btn" onclick="copyText(document.getElementById('modalDecodedJson').innerText)">📋 Kopijuoti JSON</button>
                        </div>
                        <pre id="modalDecodedJson" class="json-viewer"></pre>
                    </div>

                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">Originalus HTTP POST (payload_json):</span>
                        </div>
                        <pre id="modalPayloadJson" class="json-viewer" style="max-height: 120px;"></pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('packetModal')">Uždaryti</button>
                </div>
            </div>
        </div>

        <!-- Pranešimų kortelė (Toast) -->
        <div class="toast-msg" id="toastMsg"></div>
    <?php endif; ?>

    <script>
        let currentTab = 'nodes';
        let nodesData = [];
        let activePacketId = null;
        let activePacketData = null;
        
        let telPage = 0;
        const telLimit = 50;
        let telNodeFilter = '';

        let rawPage = 0;
        const rawLimit = 50;
        let rawNodeFilter = '';

        // Istorijos ir modalų būsenos valdymas
        let activePacketModalId = null;
        let activeEditNodeKey = null;
        let pendingEditNodeKey = null;
        let modalOpenedInSession = false;
        let isApplyingUrlState = false;

        // Prisijungimo apdorojimas
        async function handleLogin(e) {
            e.preventDefault();
            const password = document.getElementById('adminPassword').value;
            const errBox = document.getElementById('loginError');
            errBox.style.display = 'none';

            try {
                const res = await fetch('api.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password })
                });
                const data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    errBox.innerText = data.error || 'Neteisingas slaptažodis!';
                    errBox.style.display = 'block';
                }
            } catch (err) {
                errBox.innerText = 'Serverio ryšio klaida!';
                errBox.style.display = 'block';
            }
        }

        // Atsijungimas
        async function handleLogout() {
            await fetch('api.php?action=logout', { method: 'POST' });
            location.reload();
        }

        // --- URL & ISTORIJOS (HISTORY) SINCHRONIZAVIMAS ---
        function getAppUrl(overrides = {}) {
            const params = new URLSearchParams();
            const tab = overrides.tab !== undefined ? overrides.tab : currentTab;
            params.set('tab', tab || 'nodes');

            if (tab === 'nodes') {
                const search = overrides.search !== undefined ? overrides.search : (document.getElementById('nodesSearch')?.value || '').trim();
                const type = overrides.type !== undefined ? overrides.type : (document.getElementById('nodesTypeFilter')?.value || '');
                const update = overrides.update !== undefined ? overrides.update : (document.getElementById('nodesUpdateFilter')?.value || '');
                const editNode = overrides.edit_node !== undefined ? overrides.edit_node : activeEditNodeKey;

                if (search) params.set('search', search);
                if (type) params.set('type', type);
                if (update) params.set('update', update);
                if (editNode) params.set('edit_node', editNode);
            } else if (tab === 'telemetry') {
                const node = overrides.node !== undefined ? overrides.node : (document.getElementById('telemetryNodeInput')?.value || '').trim();
                const type = overrides.type !== undefined ? overrides.type : (document.getElementById('telemetryTypeFilter')?.value || '');
                const page = overrides.page !== undefined ? overrides.page : telPage;
                const packet = overrides.packet !== undefined ? overrides.packet : activePacketModalId;

                if (node) params.set('node', node);
                if (type) params.set('type', type);
                if (page > 0) params.set('page', page + 1);
                if (packet) params.set('packet', packet);
            } else if (tab === 'raw') {
                const search = overrides.search !== undefined ? overrides.search : (document.getElementById('rawSearchInput')?.value || '').trim();
                const status = overrides.status !== undefined ? overrides.status : (document.getElementById('rawStatusFilter')?.value || '');
                const dir = overrides.dir !== undefined ? overrides.dir : (document.getElementById('rawDirectionFilter')?.value || '');
                const page = overrides.page !== undefined ? overrides.page : rawPage;
                const packet = overrides.packet !== undefined ? overrides.packet : activePacketModalId;

                if (search) params.set('search', search);
                if (status) params.set('status', status);
                if (dir) params.set('dir', dir);
                if (page > 0) params.set('page', page + 1);
                if (packet) params.set('packet', packet);
            } else if (tab === 'tools') {
                // Eilė ir įrankiai papildomų parametrų neturi
            }

            const qs = params.toString();
            return window.location.pathname + (qs ? '?' + qs : '');
        }

        function updateHistory(overrides = {}, mode = 'push') {
            if (isApplyingUrlState) return;
            const newUrl = getAppUrl(overrides);
            const currentUrl = window.location.pathname + window.location.search;
            if (newUrl === currentUrl && mode === 'push') return;

            const state = {
                tab: overrides.tab !== undefined ? overrides.tab : currentTab,
                activePacketModalId: overrides.packet !== undefined ? overrides.packet : activePacketModalId,
                activeEditNodeKey: overrides.edit_node !== undefined ? overrides.edit_node : activeEditNodeKey,
                hasModal: !!(overrides.packet || overrides.edit_node || activePacketModalId || activeEditNodeKey)
            };

            if (mode === 'push') {
                history.pushState(state, '', newUrl);
            } else if (mode === 'replace') {
                history.replaceState(state, '', newUrl);
            }
            updateDocumentTitle();
        }

        function updateDocumentTitle() {
            const titles = {
                nodes: '🛰️ Mazgai',
                telemetry: '📊 Telemetrija',
                raw: '📦 RAW Paketai',
                tools: '⚡ Eilė ir Įrankiai'
            };
            let title = titles[currentTab] || 'MeshCore Admin';
            if (activePacketModalId) {
                title += ` (Paketas #${activePacketModalId})`;
            } else if (activeEditNodeKey) {
                title += ` (Mazgo nustatymai)`;
            }
            document.title = `${title} — MeshCore Admin`;
        }

        function applyUrlState(isInitialLoad = false) {
            isApplyingUrlState = true;
            try {
                const params = new URLSearchParams(window.location.search);
                let tab = params.get('tab') || 'nodes';
                if (!['nodes', 'telemetry', 'raw', 'tools'].includes(tab)) {
                    tab = 'nodes';
                }

                const packetId = params.get('packet');
                const editKey = params.get('edit_node') || params.get('edit');

                // 1. Modalo būsenos atstatymas
                if (packetId) {
                    if (activePacketModalId != packetId) {
                        inspectPacket(packetId, false);
                    }
                } else {
                    const pModal = document.getElementById('packetModal');
                    if (pModal && pModal.classList.contains('active')) {
                        closeModal('packetModal', false);
                    }
                }

                if (editKey) {
                    if (activeEditNodeKey != editKey) {
                        if (nodesData && nodesData.length > 0) {
                            openEditNode(editKey, false);
                        } else {
                            pendingEditNodeKey = editKey;
                        }
                    }
                } else {
                    const eModal = document.getElementById('editNodeModal');
                    if (eModal && eModal.classList.contains('active')) {
                        closeModal('editNodeModal', false);
                    }
                }

                // 2. Aktyvus skirtukas UI
                currentTab = tab;
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

                const btn = document.getElementById(`tabBtn-${tab}`);
                const content = document.getElementById(`tab-${tab}`);
                if (btn) btn.classList.add('active');
                if (content) content.classList.add('active');

                // 3. Skirtuko duomenų atstatymas ir užkrovimas
                if (tab === 'nodes') {
                    const search = params.get('search') || params.get('q') || '';
                    const type = params.get('type') || '';
                    const update = params.get('update') || '';

                    if (document.getElementById('nodesSearch')) document.getElementById('nodesSearch').value = search;
                    if (document.getElementById('nodesTypeFilter')) document.getElementById('nodesTypeFilter').value = type;
                    if (document.getElementById('nodesUpdateFilter')) document.getElementById('nodesUpdateFilter').value = update;

                    loadNodes();
                } else if (tab === 'telemetry') {
                    const node = params.get('node') || '';
                    const type = params.get('type') || '';
                    const pageParam = parseInt(params.get('page'), 10);
                    telPage = (!isNaN(pageParam) && pageParam > 0) ? (pageParam - 1) : 0;
                    telNodeFilter = node;

                    if (document.getElementById('telemetryNodeInput')) document.getElementById('telemetryNodeInput').value = node;
                    if (document.getElementById('telemetryTypeFilter')) document.getElementById('telemetryTypeFilter').value = type;
                    const chip = document.getElementById('telemetryNodeFilterTag');
                    if (chip) {
                        if (node) {
                            document.getElementById('telemetryActiveNodeText').innerText = node;
                            chip.style.display = 'inline-flex';
                        } else {
                            chip.style.display = 'none';
                        }
                    }

                    loadTelemetry(telPage);
                } else if (tab === 'raw') {
                    const search = params.get('search') || '';
                    const status = params.get('status') || '';
                    const dir = params.get('dir') || '';
                    const pageParam = parseInt(params.get('page'), 10);
                    rawPage = (!isNaN(pageParam) && pageParam > 0) ? (pageParam - 1) : 0;
                    rawNodeFilter = search;

                    if (document.getElementById('rawSearchInput')) document.getElementById('rawSearchInput').value = search;
                    if (document.getElementById('rawStatusFilter')) document.getElementById('rawStatusFilter').value = status;
                    if (document.getElementById('rawDirectionFilter')) document.getElementById('rawDirectionFilter').value = dir;
                    const chip = document.getElementById('rawNodeFilterTag');
                    if (chip) {
                        if (search) {
                            document.getElementById('rawActiveNodeText').innerText = search;
                            chip.style.display = 'inline-flex';
                        } else {
                            chip.style.display = 'none';
                        }
                    }

                    loadRawPackets(rawPage);
                } else if (tab === 'tools') {
                    // Eilės ir įrankių skirtukas
                }

                if (isInitialLoad && !params.get('tab')) {
                    updateHistory({ tab: currentTab }, 'replace');
                }
                updateDocumentTitle();
            } finally {
                isApplyingUrlState = false;
            }
        }

        // Skirtukų perjungimas
        function switchTab(tabName, pushState = true) {
            if (!['nodes', 'telemetry', 'raw', 'tools'].includes(tabName)) {
                tabName = 'nodes';
            }

            if (activePacketModalId) closeModal('packetModal', false);
            if (activeEditNodeKey) closeModal('editNodeModal', false);

            currentTab = tabName;
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            const btn = document.getElementById(`tabBtn-${tabName}`);
            const content = document.getElementById(`tab-${tabName}`);
            if (btn) btn.classList.add('active');
            if (content) content.classList.add('active');

            if (pushState && !isApplyingUrlState) {
                updateHistory({ tab: tabName, packet: null, edit_node: null }, 'push');
            }
            updateDocumentTitle();

            if (tabName === 'nodes') loadNodes();
            else if (tabName === 'telemetry') loadTelemetry(telPage);
            else if (tabName === 'raw') loadRawPackets(rawPage);
        }

        // Filtravimo ir paieškos apdorotojai
        const debouncedLoadNodes = debounce(() => {
            updateHistory({ tab: 'nodes' }, 'replace');
            loadNodes();
        }, 300);

        function onNodesSearchInput() {
            debouncedLoadNodes();
        }

        function onNodesSearchEnter() {
            updateHistory({ tab: 'nodes' }, 'push');
            loadNodes();
        }

        function onNodesFilterChange() {
            updateHistory({ tab: 'nodes' }, 'push');
            loadNodes();
        }

        function onTelemetryFilterChange() {
            telPage = 0;
            const nodeVal = document.getElementById('telemetryNodeInput').value.trim();
            telNodeFilter = nodeVal;
            const chip = document.getElementById('telemetryNodeFilterTag');
            if (chip) {
                if (nodeVal) {
                    document.getElementById('telemetryActiveNodeText').innerText = nodeVal;
                    chip.style.display = 'inline-flex';
                } else {
                    chip.style.display = 'none';
                }
            }
            updateHistory({ tab: 'telemetry', node: nodeVal, page: 0 }, 'push');
            loadTelemetry(0);
        }

        const debouncedLoadRaw = debounce(() => {
            rawPage = 0;
            updateHistory({ tab: 'raw', page: 0 }, 'replace');
            loadRawPackets(0);
        }, 350);

        function onRawSearchInput() {
            debouncedLoadRaw();
        }

        function onRawSearchEnter() {
            rawPage = 0;
            updateHistory({ tab: 'raw', page: 0 }, 'push');
            loadRawPackets(0);
        }

        function onRawFilterChange() {
            rawPage = 0;
            updateHistory({ tab: 'raw', page: 0 }, 'push');
            loadRawPackets(0);
        }

        function refreshCurrentTab() {
            loadStats();
            if (currentTab === 'nodes') loadNodes();
            else if (currentTab === 'telemetry') loadTelemetry(telPage);
            else if (currentTab === 'raw') loadRawPackets(rawPage);
            showToast('Duomenys atnaujinti!');
        }

        // Statistikos užkrovimas
        async function loadStats() {
            try {
                const res = await fetch('api.php?action=stats');
                const data = await res.json();
                if (data.success) {
                    const n = data.nodes || {};
                    const r = data.raw || {};
                    const t = data.telemetry || {};

                    document.getElementById('statNodesCount').innerText = n.total_nodes || 0;
                    document.getElementById('statMonitoredCount').innerText = n.monitored_repeaters_count || 0;
                    document.getElementById('statRawTotal').innerText = r.total || 0;
                    document.getElementById('statQueuePending').innerText = r.pending || 0;
                    document.getElementById('statErrorsCount').innerText = r.errors || 0;
                    document.getElementById('statTelemetryCount').innerText = t.total_records || 0;
                }
            } catch (e) {
                console.error('Klaida gaunant statistiką:', e);
            }
        }

        // 1. MAZGŲ SĄRAŠAS
        async function loadNodes() {
            const search = document.getElementById('nodesSearch').value;
            const type = document.getElementById('nodesTypeFilter').value;
            const update = document.getElementById('nodesUpdateFilter').value;

            const url = `api.php?action=nodes&search=${encodeURIComponent(search)}&type=${encodeURIComponent(type)}&update=${encodeURIComponent(update)}`;
            try {
                const res = await fetch(url);
                const data = await res.json();
                if (data.success) {
                    nodesData = data.nodes || [];
                    document.getElementById('nodesCountBadge').innerText = `${data.total} mazgų`;
                    renderNodesTable(nodesData);

                    if (pendingEditNodeKey) {
                        openEditNode(pendingEditNodeKey, false);
                        pendingEditNodeKey = null;
                    }
                }
            } catch (err) {
                console.error(err);
            }
        }

        function renderNodesTable(nodes) {
            const tbody = document.getElementById('nodesTableBody');
            if (!nodes.length) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding: 24px; color: var(--text-dim);">Mazgų nerasta.</td></tr>';
                return;
            }

            tbody.innerHTML = nodes.map(n => {
                const name = n.node_name || n.node_id || 'Bevardis';
                const id = n.node_id || 'N/A';
                const key = n.public_key || '';
                const isRep = n.is_repeater == 1 || n.is_repeater === true;
                const isUp = n.update == 1 || n.update === true;
                const hasLoc = (n.latitude && n.longitude && Math.abs(n.latitude) > 0.001);
                const pass = n.password !== null && n.password !== undefined ? n.password : '';
                const botId = n.bot_id || 'KLP';

                return `
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="badge badge-blue" title="${escapeHtml(key)}">${id.toUpperCase()}</span>
                                <span style="font-weight: 600; color: #ffffff;">${escapeHtml(name)}</span>
                            </div>
                        </td>
                        <td>
                            ${isRep ? '<span class="badge badge-cyan">KARTOTUVAS</span>' : '<span class="badge badge-gray">KLIENTAS</span>'}
                        </td>
                        <td>
                            <span class="badge badge-purple" title="ESP32 Šaltinis (BOT_ID)">🤖 ${escapeHtml(botId)}</span>
                        </td>
                        <td>
                            ${isUp ? '<span class="badge badge-green">TAIP</span>' : '<span class="badge badge-gray">NE</span>'}
                        </td>
                        <td>
                            ${pass !== '' ? `<span class="badge badge-amber" title="${escapeHtml(pass)}">🔑 Nustatytas</span>` : '<span style="color:var(--text-dim);font-size:0.75rem;">Tuščias ("")</span>'}
                        </td>
                        <td>
                            ${hasLoc ? `<span style="color:var(--accent-cyan); font-family:var(--font-mono);">${parseFloat(n.latitude).toFixed(3)}, ${parseFloat(n.longitude).toFixed(3)}</span>` : '<span style="color:var(--text-dim);">Nėra</span>'}
                        </td>
                        <td>
                            <div style="font-size: 0.75rem;" title="Matytas tinkle: ${n.last_seen_at}">📡 ${formatRelativeTime(n.last_seen_at)}</div>
                            <div style="font-size: 0.70rem; color: #a78bfa; margin-top: 2px;" title="Telemetrija atnaujinta: ${n.telemetry_updated_at || 'Nėra'}">
                                ${n.telemetry_updated_at ? '⏱️ ' + formatRelativeTime(n.telemetry_updated_at) : '<span style="color:var(--text-dim);">⏱️ Nėra</span>'}
                            </div>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="btn btn-secondary btn-sm" onclick="openEditNode('${key}')" title="Keisti nustatymus">⚙️ Nustatymai</button>
                            <button class="btn btn-secondary btn-sm" onclick="filterTelemetryByNode('${id}')" title="Atidaryti telemetrijos įrašus">📊 Telemetrija</button>
                            <button class="btn btn-secondary btn-sm" onclick="filterRawByNode('${id}')" title="Atidaryti RAW paketus">📦 RAW</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Mazgo redagavimo langas
        function openEditNode(publicKey, pushHistory = true) {
            if (!nodesData || nodesData.length === 0) {
                pendingEditNodeKey = publicKey;
                return;
            }
            const node = nodesData.find(n => n.public_key === publicKey || n.node_id === publicKey);
            if (!node) return;

            activeEditNodeKey = node.public_key;
            if (pushHistory && !isApplyingUrlState) {
                modalOpenedInSession = true;
                updateHistory({ tab: 'nodes', edit_node: node.public_key }, 'push');
            }

            document.getElementById('editNodePublicKey').value = node.public_key;
            document.getElementById('editNodeKeyDisplay').value = node.public_key;
            document.getElementById('editNodeName').value = node.node_name || '';
            document.getElementById('editNodePassword').value = node.password !== null && node.password !== undefined ? node.password : '';
            document.getElementById('editNodeLat').value = node.latitude || '';
            document.getElementById('editNodeLon').value = node.longitude || '';
            document.getElementById('editNodeUpdate').checked = (node.update == 1 || node.update === true);
            document.getElementById('editNodeIsRepeater').checked = (node.is_repeater == 1 || node.is_repeater === true);

            document.getElementById('editNodeTitle').innerText = `Mazgo Nustatymai: ${node.node_name || node.node_id || ''}`;
            openModal('editNodeModal');
            updateDocumentTitle();
        }

        async function handleSaveNode(e) {
            e.preventDefault();
            const publicKey = document.getElementById('editNodePublicKey').value;
            const payload = {
                public_key: publicKey,
                node_name: document.getElementById('editNodeName').value,
                password: document.getElementById('editNodePassword').value,
                update: document.getElementById('editNodeUpdate').checked ? 1 : 0,
                is_repeater: document.getElementById('editNodeIsRepeater').checked ? 1 : 0,
                latitude: document.getElementById('editNodeLat').value,
                longitude: document.getElementById('editNodeLon').value,
            };

            try {
                const res = await fetch('api.php?action=update_node', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Mazgo nustatymai išsaugoti sėkmingai!', 'success');
                    closeModal('editNodeModal');
                    loadNodes();
                    loadStats();
                } else {
                    alert('Klaida: ' + (data.error || 'Nepavyko išsaugoti'));
                }
            } catch (err) {
                alert('Serverio ryšio klaida');
            }
        }

        // 2. TELEMETRIJOS SĄRAŠAS
        function filterTelemetryByNode(nodeId) {
            telNodeFilter = nodeId;
            telPage = 0;
            document.getElementById('telemetryNodeInput').value = nodeId;
            document.getElementById('telemetryActiveNodeText').innerText = nodeId;
            document.getElementById('telemetryNodeFilterTag').style.display = 'inline-flex';
            
            currentTab = 'telemetry';
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById('tabBtn-telemetry')?.classList.add('active');
            document.getElementById('tab-telemetry')?.classList.add('active');

            updateHistory({ tab: 'telemetry', node: nodeId, page: 0, packet: null }, 'push');
            loadTelemetry(0);
        }

        function clearTelemetryNodeFilter() {
            telNodeFilter = '';
            telPage = 0;
            document.getElementById('telemetryNodeInput').value = '';
            document.getElementById('telemetryNodeFilterTag').style.display = 'none';
            updateHistory({ tab: 'telemetry', node: '', page: 0 }, 'push');
            loadTelemetry(0);
        }

        async function loadTelemetry(page = 0) {
            telPage = page;
            const node = document.getElementById('telemetryNodeInput').value.trim();
            const type = document.getElementById('telemetryTypeFilter').value;
            const offset = page * telLimit;

            const url = `api.php?action=telemetry&node_id=${encodeURIComponent(node)}&type=${encodeURIComponent(type)}&limit=${telLimit}&offset=${offset}`;
            try {
                const res = await fetch(url);
                const data = await res.json();
                if (data.success) {
                    document.getElementById('telemetryCountBadge').innerText = `${data.total} įrašų`;
                    document.getElementById('telemetryPageInfo').innerText = `Puslapis ${telPage + 1} iš ${Math.max(1, Math.ceil(data.total / telLimit))}`;
                    document.getElementById('btnPrevTel').disabled = (telPage <= 0);
                    document.getElementById('btnNextTel').disabled = (offset + telLimit >= data.total);
                    renderTelemetryTable(data.records || []);
                }
            } catch (err) {
                console.error(err);
            }
        }

        function changeTelemetryPage(delta) {
            const newPage = Math.max(0, telPage + delta);
            if (newPage !== telPage) {
                telPage = newPage;
                updateHistory({ tab: 'telemetry', page: telPage }, 'push');
                loadTelemetry(telPage);
            }
        }

        function renderTelemetryTable(records) {
            const tbody = document.getElementById('telemetryTableBody');
            if (!records.length) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding: 24px; color: var(--text-dim);">Telemetrijos įrašų nerasta.</td></tr>';
                return;
            }

            tbody.innerHTML = records.map(r => {
                const batt = r.battery_v !== null ? `${parseFloat(r.battery_v).toFixed(2)} V` : '-';
                const temp = r.temperature_c !== null ? `${parseFloat(r.temperature_c).toFixed(1)} °C` : '-';
                const uptime = r.uptime_seconds ? formatUptime(r.uptime_seconds) : '-';

                return `
                    <tr>
                        <td style="font-family: var(--font-mono); font-size: 0.72rem;">#${r.id}</td>
                        <td>
                            <b style="color: #ffffff;">${escapeHtml(r.node_id)}</b>
                        </td>
                        <td><span class="badge badge-gray">${escapeHtml(r.type)}</span></td>
                        <td><span style="color:#2ed573; font-family:var(--font-mono); font-weight:600;">${batt}</span></td>
                        <td><span style="color:var(--accent-orange); font-family:var(--font-mono);">${temp}</span></td>
                        <td style="font-family: var(--font-mono); font-size: 0.74rem;">
                            ${r.rssi ? `RSSI: ${r.rssi} dBm ` : ''}
                            ${r.snr ? `SNR: ${r.snr} dB` : ''}
                        </td>
                        <td>${uptime}</td>
                        <td style="font-family: var(--font-mono); font-size: 0.74rem;">
                            RX: ${r.packets_recv ?? '-'} / TX: ${r.packets_sent ?? '-'}
                        </td>
                        <td style="font-size: 0.74rem;">
                            <span title="${r.recorded_at}">${r.recorded_at}</span>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            ${r.raw_packet_id ? `
                                <button class="btn btn-success btn-sm" onclick="copyDebugInfo(${r.raw_packet_id})" title="Nukopijuoti RAW paketo derinimo duomenis į iškarpinę">📋 Debug</button>
                                <button class="btn btn-secondary btn-sm" onclick="inspectPacket(${r.raw_packet_id})">RAW #${r.raw_packet_id}</button>
                            ` : '-'}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // 3. RAW PAKETAI & DEBUGGER
        function filterRawByNode(nodeId) {
            rawNodeFilter = nodeId;
            rawPage = 0;
            document.getElementById('rawSearchInput').value = nodeId;
            document.getElementById('rawActiveNodeText').innerText = nodeId;
            document.getElementById('rawNodeFilterTag').style.display = 'inline-flex';
            
            currentTab = 'raw';
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById('tabBtn-raw')?.classList.add('active');
            document.getElementById('tab-raw')?.classList.add('active');

            updateHistory({ tab: 'raw', search: nodeId, page: 0, packet: null }, 'push');
            loadRawPackets(0);
        }

        function clearRawNodeFilter() {
            rawNodeFilter = '';
            rawPage = 0;
            document.getElementById('rawSearchInput').value = '';
            document.getElementById('rawNodeFilterTag').style.display = 'none';
            updateHistory({ tab: 'raw', search: '', page: 0 }, 'push');
            loadRawPackets(0);
        }

        async function loadRawPackets(page = 0) {
            rawPage = page;
            const search = document.getElementById('rawSearchInput').value.trim();
            const status = document.getElementById('rawStatusFilter').value;
            const direction = document.getElementById('rawDirectionFilter').value;
            const offset = page * rawLimit;

            const url = `api.php?action=raw_packets&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&direction=${encodeURIComponent(direction)}&limit=${rawLimit}&offset=${offset}`;
            try {
                const res = await fetch(url);
                const data = await res.json();
                if (data.success) {
                    document.getElementById('rawCountBadge').innerText = `${data.total} paketų`;
                    document.getElementById('rawPageInfo').innerText = `Puslapis ${rawPage + 1} iš ${Math.max(1, Math.ceil(data.total / rawLimit))}`;
                    document.getElementById('btnPrevRaw').disabled = (rawPage <= 0);
                    document.getElementById('btnNextRaw').disabled = (offset + rawLimit >= data.total);
                    renderRawTable(data.packets || []);
                }
            } catch (err) {
                console.error(err);
            }
        }

        function changeRawPage(delta) {
            const newPage = Math.max(0, rawPage + delta);
            if (newPage !== rawPage) {
                rawPage = newPage;
                updateHistory({ tab: 'raw', page: rawPage }, 'push');
                loadRawPackets(rawPage);
            }
        }

        function renderRawTable(packets) {
            const tbody = document.getElementById('rawTableBody');
            if (!packets.length) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding: 24px; color: var(--text-dim);">RAW paketų nerasta.</td></tr>';
                return;
            }

            tbody.innerHTML = packets.map(p => {
                const dirBadge = (p.direction === 'TX') 
                    ? '<span class="badge badge-green">TX (Išsiųsta)</span>' 
                    : '<span class="badge badge-cyan">RX (Gauta)</span>';

                let statusBadge = '<span class="badge badge-green">Apdorota</span>';
                if (p.error_message) {
                    statusBadge = '<span class="badge badge-red">KLAIDA</span>';
                } else if (p.processed == 0 || p.processed === false) {
                    statusBadge = '<span class="badge badge-amber">Eilėje</span>';
                }

                const sender = p.sender_name || (p.sender_public_key ? p.sender_public_key.substring(0, 6) : '-');
                const target = p.target_name || (p.target_public_key ? p.target_public_key.substring(0, 6) : '-');

                return `
                    <tr>
                        <td style="font-family: var(--font-mono); font-size: 0.72rem;">#${p.id}</td>
                        <td><span class="badge badge-purple" style="font-size:0.7rem;">${escapeHtml(p.bot_id || 'KLP')}</span></td>
                        <td>${dirBadge}</td>
                        <td><b>${escapeHtml(sender)}</b></td>
                        <td><b>${escapeHtml(target)}</b></td>
                        <td style="font-family: var(--font-mono); font-size: 0.72rem;">
                            ${p.length ? p.length + ' B' : '-'} 
                            ${p.rssi ? `| ${p.rssi} dBm` : ''}
                        </td>
                        <td>${statusBadge}</td>
                        <td>
                            ${p.error_message ? `<span style="color:#ff6b81; font-size:0.75rem;" title="${escapeHtml(p.error_message)}">${escapeHtml(p.error_message.substring(0, 45))}...</span>` : '<span style="color:var(--text-dim);">-</span>'}
                        </td>
                        <td style="font-size: 0.74rem;">${p.created_at}</td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="btn btn-success btn-sm" onclick="copyDebugInfo(${p.id})" title="Nukopijuoti visą paketo procesą ir duomenis derinimui su AI / Dev">📋 Kopijuoti derinimui</button>
                            <button class="btn btn-secondary btn-sm" onclick="inspectPacket(${p.id})">🔍 Peržiūrėti</button>
                            <button class="btn btn-secondary btn-sm" onclick="reprocessPacket(${p.id})">🔄 Reprocess</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // RAW Paketo išsamus langas
        async function inspectPacket(id, pushHistory = true) {
            activePacketId = id;
            activePacketModalId = id;
            if (pushHistory && !isApplyingUrlState) {
                modalOpenedInSession = true;
                updateHistory({ packet: id }, 'push');
            }
            updateDocumentTitle();

            try {
                const res = await fetch(`api.php?action=raw_packet_detail&id=${id}`);
                const data = await res.json();
                if (data.success && data.packet) {
                    const p = data.packet;
                    activePacketData = p;
                    document.getElementById('packetModalTitle').innerText = `RAW Paketas #${p.id} (${p.direction || 'RX'})`;
                    
                    const metaRow = document.getElementById('packetMetaRow');
                    metaRow.innerHTML = `
                        <span class="badge badge-purple">🤖 Botas: ${escapeHtml(p.bot_id || 'KLP')}</span>
                        <span class="badge badge-blue">Ilgis: ${p.length || 0} B</span>
                        <span class="badge badge-gray">RSSI: ${p.rssi !== null && p.rssi !== undefined ? p.rssi : '-'} dBm</span>
                        <span class="badge badge-gray">SNR: ${p.snr !== null && p.snr !== undefined ? p.snr : '-'} dB</span>
                        <span class="badge badge-${p.is_encrypted ? 'amber' : 'green'}">${p.is_encrypted ? 'Šifruotas' : 'Viešas'}</span>
                    `;

                    const errBox = document.getElementById('packetErrorBox');
                    if (p.error_message) {
                        errBox.innerText = 'Klaidos pranešimas: ' + p.error_message;
                        errBox.style.display = 'block';
                    } else {
                        errBox.style.display = 'none';
                    }

                    document.getElementById('modalRawHex').innerText = p.raw_hex || '';
                    document.getElementById('modalDecodedJson').innerText = p.decoded_json ? (typeof p.decoded_json === 'object' ? JSON.stringify(p.decoded_json, null, 2) : p.decoded_json) : '(tuščia)';
                    document.getElementById('modalPayloadJson').innerText = p.payload_json ? (typeof p.payload_json === 'object' ? JSON.stringify(p.payload_json, null, 2) : p.payload_json) : '(tuščia)';

                    openModal('packetModal');
                } else {
                    alert('Nepavyko gauti paketo duomenų: ' + (data.error || 'Paketas nerastas'));
                    closeModal('packetModal', false);
                }
            } catch (err) {
                alert('Nepavyko gauti paketo duomenų: ' + (err.message || err));
                closeModal('packetModal', false);
            }
        }

        // Generuoja pilną, struktūrizuotą Markdown tekstą derinimui (AI / Dev modeliui)
        function generateDebugBundle(p) {
            if (!p) return '';

            const lines = [];
            lines.push(`### 🐞 MeshCore RAW Paketo Derinimo Duomenys (Paketas #${p.id})`);
            lines.push('');
            lines.push(`- **Paketo ID:** ${p.id}`);
            lines.push(`- **Bot ID (Šaltinis):** ${p.bot_id || 'KLP'}`);
            lines.push(`- **Kryptis:** ${p.direction || 'N/A'}`);
            lines.push(`- **Ilgis:** ${p.length ? p.length + ' B' : 'N/A'}`);
            lines.push(`- **RSSI / SNR:** ${p.rssi !== null && p.rssi !== undefined ? p.rssi + ' dBm' : 'N/A'} / ${p.snr !== null && p.snr !== undefined ? p.snr + ' dB' : 'N/A'}`);
            lines.push(`- **Šifruotas (is_encrypted):** ${p.is_encrypted ? 'TAIP (1)' : 'NE (0)'}`);
            lines.push(`- **Siuntėjas:** ${p.sender_name || 'N/A'} (Raktas: \`${p.sender_public_key || 'N/A'}\`)`);
            lines.push(`- **Taikinys:** ${p.target_name || 'N/A'} (Raktas: \`${p.target_public_key || 'N/A'}\`)`);
            if (p.my_public_key) {
                lines.push(`- **Mūsų mazgo raktas (my_public_key):** \`${p.my_public_key}\``);
            }
            if (p.shared_secret_hex) {
                lines.push(`- **Shared Secret Hex:** \`${p.shared_secret_hex}\``);
            }
            lines.push(`- **Gavimo data:** ${p.created_at || 'N/A'}`);
            lines.push(`- **Apdorojimo data:** ${p.processed_at || 'N/A'} (baigta: ${p.completed_at || 'N/A'})`);
            lines.push(`- **Apdorojimo statusas (processed):** ${p.processed}`);

            if (p.error_message) {
                lines.push('');
                lines.push(`> ⚠️ **KLAIDOS PRANEŠIMAS (error_message):**`);
                lines.push(`> \`\`\``);
                lines.push(`> ${p.error_message}`);
                lines.push(`> \`\`\``);
            }

            lines.push('');
            lines.push('#### 1. RAW HEX:');
            lines.push('```hex');
            lines.push(p.raw_hex || '');
            lines.push('```');

            lines.push('');
            lines.push('#### 2. Dekoduotas JSON (decoded_json):');
            lines.push('```json');
            lines.push(typeof p.decoded_json === 'object' ? JSON.stringify(p.decoded_json, null, 2) : (p.decoded_json || '{}'));
            lines.push('```');

            if (p.payload_json) {
                lines.push('');
                lines.push('#### 3. Originalus HTTP POST Payload (payload_json):');
                lines.push('```json');
                lines.push(typeof p.payload_json === 'object' ? JSON.stringify(p.payload_json, null, 2) : p.payload_json);
                lines.push('```');
            }

            if (p.telemetry_records && p.telemetry_records.length > 0) {
                lines.push('');
                lines.push('#### 4. Susiję telemetrijos įrašai:');
                lines.push('```json');
                lines.push(JSON.stringify(p.telemetry_records, null, 2));
                lines.push('```');
            }

            return lines.join('\n');
        }

        // Pagalbinė funkcija saugiam teksto nukopijavimui
        async function copyTextToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return await navigator.clipboard.writeText(text);
            }
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.left = "-999999px";
            textArea.style.top = "-999999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            return new Promise((resolve, reject) => {
                try {
                    const successful = document.execCommand('copy');
                    successful ? resolve() : reject(new Error('Iškarpinės prieiga negalima'));
                } catch (e) {
                    reject(e);
                } finally {
                    textArea.remove();
                }
            });
        }

        // Nukopijuoja visą paketo derinimo informaciją į kompiuterio iškarpinę (Clipboard)
        async function copyDebugInfo(id) {
            if (!id) return;
            try {
                let packet = (activePacketData && activePacketData.id == id) ? activePacketData : null;
                if (!packet) {
                    showToast(`Gaunami paketo #${id} duomenys...`);
                    const res = await fetch(`api.php?action=raw_packet_detail&id=${id}`);
                    const data = await res.json();
                    if (data.success && data.packet) {
                        packet = data.packet;
                    } else {
                        alert('Nepavyko gauti paketo duomenų: ' + (data.error || 'Nežinoma klaida'));
                        return;
                    }
                }

                const debugText = generateDebugBundle(packet);
                await copyTextToClipboard(debugText);
                showToast(`📋 Paketo #${id} derinimo duomenys nukopijuoti į iškarpinę! Galite tiesiogiai įklijuoti (Paste) į pokalbį.`, 'success');
            } catch (err) {
                alert('Klaida kopijuojant į iškarpinę: ' + (err.message || err));
            }
        }

        async function reprocessPacket(id) {
            try {
                showToast(`Apdorojamas paketas #${id}...`);
                const res = await fetch('api.php?action=reprocess_packet', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`Paketas #${id} sėkmingai apdorotas!`, 'success');
                    loadRawPackets(rawPage);
                    loadStats();
                } else {
                    alert('Klaida apdorojant: ' + (data.error || 'Nežinoma'));
                }
            } catch (err) {
                alert('Klaida siunčiant užklausą');
            }
        }

        function triggerReprocessFromModal() {
            if (activePacketId) {
                reprocessPacket(activePacketId).then(() => {
                    inspectPacket(activePacketId, false);
                });
            }
        }

        // 4. EILĖS APDOROJIMAS
        async function runQueueBatch(limit = 20) {
            const out = document.getElementById('queueOutput');
            out.innerText = `Paleidžiamas ${limit} paketų apdorojimas...`;

            try {
                const res = await fetch('api.php?action=process_queue_batch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ limit })
                });
                const data = await res.json();
                out.innerText = JSON.stringify(data, null, 2);
                loadStats();
                showToast(`Apdorota: ${data.processed} paketų`, 'success');
            } catch (err) {
                out.innerText = 'Klaida vykdant eilės apdorojimą: ' + err.message;
            }
        }

        // Pagalbinės funkcijos
        function openModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.add('active');
        }

        function closeModal(id, fromUserAction = true) {
            const el = document.getElementById(id);
            if (!el) return;

            if (fromUserAction) {
                if (modalOpenedInSession) {
                    modalOpenedInSession = false;
                    history.back();
                    return;
                } else {
                    if (id === 'packetModal') activePacketModalId = null;
                    if (id === 'editNodeModal') activeEditNodeKey = null;
                    updateHistory({ packet: null, edit_node: null }, 'push');
                }
            }

            el.classList.remove('active');
            if (id === 'packetModal') {
                activePacketModalId = null;
            }
            if (id === 'editNodeModal') {
                activeEditNodeKey = null;
                pendingEditNodeKey = null;
            }
            updateDocumentTitle();
        }

        function showToast(msg, type = '') {
            const t = document.getElementById('toastMsg');
            if (!t) return;
            t.className = 'toast-msg ' + type;
            t.innerText = msg;
            t.style.display = 'flex';
            setTimeout(() => { t.style.display = 'none'; }, 3000);
        }

        function copyText(str) {
            if (!str) return;
            navigator.clipboard.writeText(str).then(() => {
                showToast('Nukopijuota į iškarpinę!', 'success');
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
        }

        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        function formatRelativeTime(dateStr) {
            if (!dateStr) return 'Nematytas';
            const date = new Date(dateStr.replace(' ', 'T'));
            const now = new Date();
            const diffSec = Math.max(0, Math.floor((now - date) / 1000));
            if (diffSec < 60) return `prieš ${diffSec}s`;
            if (diffSec < 3600) return `prieš ${Math.floor(diffSec / 60)} min.`;
            if (diffSec < 86400) return `prieš ${Math.floor(diffSec / 3600)} val.`;
            return `prieš ${Math.floor(diffSec / 86400)} d.`;
        }

        function formatUptime(seconds) {
            const days = Math.floor(seconds / 86400);
            const hours = Math.floor((seconds % 86400) / 3600);
            const mins = Math.floor((seconds % 3600) / 60);
            let res = '';
            if (days > 0) res += `${days}d `;
            if (hours > 0 || days > 0) res += `${hours}val. `;
            res += `${mins}min.`;
            return res;
        }

        // Uždarymas paspaudus už modal ribų arba paspaudus Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const openPacketModal = document.getElementById('packetModal')?.classList.contains('active');
                const openEditModal = document.getElementById('editNodeModal')?.classList.contains('active');
                if (openPacketModal) closeModal('packetModal', true);
                else if (openEditModal) closeModal('editNodeModal', true);
            }
        });

        ['packetModal', 'editNodeModal'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', (e) => {
                    if (e.target === el) {
                        closeModal(id, true);
                    }
                });
            }
        });

        // Naršyklės Atgal / Pirmyn (Back / Forward) palaikymas
        window.addEventListener('popstate', (e) => {
            <?php if ($isLoggedIn): ?>
                modalOpenedInSession = false;
                applyUrlState(false);
            <?php endif; ?>
        });

        // Paleidimas užsikrovus / atnaujinus puslapį (Refresh)
        document.addEventListener('DOMContentLoaded', () => {
            <?php if ($isLoggedIn): ?>
                loadStats();
                applyUrlState(true);
            <?php endif; ?>
        });
    </script>
</body>
</html>
