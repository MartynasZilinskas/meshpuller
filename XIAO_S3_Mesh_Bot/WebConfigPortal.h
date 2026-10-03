#ifndef WEB_CONFIG_PORTAL_H
#define WEB_CONFIG_PORTAL_H

#include <Arduino.h>
#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <ESPmDNS.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include "config.h"
#include "MeshCrypto.h"

// ================================================================================
// 🚀 AKTYVIŲ NUSTATYMŲ KINTAMIEJI (APIBRĖŽIMAS)
// ================================================================================
String WIFI_SSID = DEFAULT_WIFI_SSID;
String WIFI_PASSWORD = DEFAULT_WIFI_PASSWORD;
String BOT_ID = DEFAULT_BOT_ID;
unsigned long REPEATER_REPLY_TIMEOUT_MS = DEFAULT_REPEATER_REPLY_TIMEOUT_MS;
unsigned long REPEATER_REMOTE_REPLY_TIMEOUT_MS = DEFAULT_REPEATER_REMOTE_REPLY_TIMEOUT_MS;
unsigned long INTER_REQUEST_DELAY_MS = DEFAULT_INTER_REQUEST_DELAY_MS;
unsigned long REPEATER_COOLDOWN_MS = DEFAULT_REPEATER_COOLDOWN_MS;
String MY_SEED_HEX = DEFAULT_MY_SEED_HEX;
String MY_PUBKEY_HEX = DEFAULT_MY_PUBKEY_HEX;
String MY_PRVKEY_HEX = DEFAULT_MY_PRVKEY_HEX;
unsigned long TELEMETRY_REQUEST_INTERVAL_MS = DEFAULT_TELEMETRY_REQUEST_INTERVAL_MS;
uint8_t PATH_HASH_BITS = DEFAULT_PATH_HASH_BITS;
bool ENABLE_SCOPE = DEFAULT_ENABLE_SCOPE;
String SCOPE_NAME = DEFAULT_SCOPE_NAME;

// Web serverio ir DNS serverio objektai (Portas 80 ir Captive Portal DNS 53)
WebServer webServer(80);
DNSServer dnsServer;

// AP būsenos ir automatinio išjungimo kintamieji (10 min. limitas)
static bool apActive = false;
static unsigned long apStartTime = 0;
static unsigned long lastApActivityTime = 0;

inline bool isWebConfigAPActive() {
  return apActive;
}

inline void stopWebConfigAP() {
  if (!apActive) return;
  apActive = false;
  dnsServer.stop();
  WiFi.softAPdisconnect(true);
  WiFi.enableAP(false);
  WiFi.mode(WIFI_STA);
  Serial.println("[WebPortal] Wi-Fi AP ('MeshPuller') išjungtas.");
}

// Pirmyn deklaruojame pagrindinio kodo funkcijas
void generateSelfIdentity();

// ================================================================================
// 🛠️ PAGALBINĖS HEKSADECIMALIOS FUNKCIJOS
// ================================================================================
inline bool hexStringToBytes(const char* hexStr, uint8_t* outBytes, size_t maxLen, size_t& outLen) {
  if (hexStr == nullptr) return false;
  if (strncmp(hexStr, "0x", 2) == 0 || strncmp(hexStr, "0X", 2) == 0) {
    hexStr += 2;
  }
  size_t hexLen = strlen(hexStr);
  if (hexLen % 2 != 0 || (hexLen / 2) > maxLen) return false;
  outLen = hexLen / 2;
  for (size_t i = 0; i < outLen; i++) {
    char byteStr[3] = { hexStr[i * 2], hexStr[i * 2 + 1], '\0' };
    outBytes[i] = (uint8_t)strtoul(byteStr, nullptr, 16);
  }
  return true;
}

inline String bytesToHexString(const uint8_t* buf, size_t len) {
  if (buf == nullptr || len == 0) return "";
  String hex = "";
  hex.reserve(len * 2);
  for (size_t i = 0; i < len; i++) {
    char b[3];
    sprintf(b, "%02x", buf[i]);
    hex += b;
  }
  return hex;
}

// ================================================================================
// 💾 NVS (VIDINĖS FLASH ATMINTIES) VALDYMAS PER PREFERENCES
// ================================================================================
inline void initConfigNVS() {
  Preferences prefs;
  if (!prefs.begin("meshbot", true)) { // Read-only bandymas
    Serial.println("[NVS] NVS inicializavimas... Pirmasis paleidimas arba tuščia atmintis.");
  } else {
    WIFI_SSID = prefs.getString("wifi_ssid", DEFAULT_WIFI_SSID);
    WIFI_PASSWORD = prefs.getString("wifi_pass", DEFAULT_WIFI_PASSWORD);
    BOT_ID = prefs.getString("bot_id", DEFAULT_BOT_ID);
    REPEATER_REPLY_TIMEOUT_MS = prefs.getULong("rep_timeout", DEFAULT_REPEATER_REPLY_TIMEOUT_MS);
    REPEATER_REMOTE_REPLY_TIMEOUT_MS = prefs.getULong("rem_timeout", DEFAULT_REPEATER_REMOTE_REPLY_TIMEOUT_MS);
    INTER_REQUEST_DELAY_MS = prefs.getULong("inter_delay", DEFAULT_INTER_REQUEST_DELAY_MS);
    REPEATER_COOLDOWN_MS = prefs.getULong("rep_cooldown", DEFAULT_REPEATER_COOLDOWN_MS);
    MY_SEED_HEX = prefs.getString("seed_hex", DEFAULT_MY_SEED_HEX);
    MY_PUBKEY_HEX = prefs.getString("pubkey_hex", DEFAULT_MY_PUBKEY_HEX);
    MY_PRVKEY_HEX = prefs.getString("prvkey_hex", DEFAULT_MY_PRVKEY_HEX);
    TELEMETRY_REQUEST_INTERVAL_MS = prefs.getULong("telem_intvl", DEFAULT_TELEMETRY_REQUEST_INTERVAL_MS);
    PATH_HASH_BITS = prefs.getUChar("path_bits", DEFAULT_PATH_HASH_BITS);
    ENABLE_SCOPE = prefs.getBool("en_scope", DEFAULT_ENABLE_SCOPE);
    SCOPE_NAME = prefs.getString("scope_name", DEFAULT_SCOPE_NAME);
    prefs.end();
    Serial.println("[NVS] Nustatymai sėkmingai užkrauti iš vidinės flash atminties.");
    return;
  }

  Serial.println("[NVS] Naudojami numatytieji nustatymai iš config.h.");
}

inline bool saveConfigToNVS() {
  Preferences prefs;
  if (!prefs.begin("meshbot", false)) { // Read-write
    Serial.println("[NVS Klaida] Nepavyko atidaryti NVS įrašymui!");
    return false;
  }
  prefs.putString("wifi_ssid", WIFI_SSID);
  prefs.putString("wifi_pass", WIFI_PASSWORD);
  prefs.putString("bot_id", BOT_ID);
  prefs.putULong("rep_timeout", REPEATER_REPLY_TIMEOUT_MS);
  prefs.putULong("rem_timeout", REPEATER_REMOTE_REPLY_TIMEOUT_MS);
  prefs.putULong("inter_delay", INTER_REQUEST_DELAY_MS);
  prefs.putULong("rep_cooldown", REPEATER_COOLDOWN_MS);
  prefs.putString("seed_hex", MY_SEED_HEX);
  prefs.putString("pubkey_hex", MY_PUBKEY_HEX);
  prefs.putString("prvkey_hex", MY_PRVKEY_HEX);
  prefs.putULong("telem_intvl", TELEMETRY_REQUEST_INTERVAL_MS);
  prefs.putUChar("path_bits", PATH_HASH_BITS);
  prefs.putBool("en_scope", ENABLE_SCOPE);
  prefs.putString("scope_name", SCOPE_NAME);
  prefs.end();
  Serial.println("[NVS] Visi nustatymai sėkmingai išsaugoti į NVS.");
  return true;
}

inline void resetConfigNVS() {
  Preferences prefs;
  prefs.begin("meshbot", false);
  prefs.clear();
  prefs.end();

  WIFI_SSID = DEFAULT_WIFI_SSID;
  WIFI_PASSWORD = DEFAULT_WIFI_PASSWORD;
  BOT_ID = DEFAULT_BOT_ID;
  REPEATER_REPLY_TIMEOUT_MS = DEFAULT_REPEATER_REPLY_TIMEOUT_MS;
  REPEATER_REMOTE_REPLY_TIMEOUT_MS = DEFAULT_REPEATER_REMOTE_REPLY_TIMEOUT_MS;
  INTER_REQUEST_DELAY_MS = DEFAULT_INTER_REQUEST_DELAY_MS;
  REPEATER_COOLDOWN_MS = DEFAULT_REPEATER_COOLDOWN_MS;
  MY_SEED_HEX = DEFAULT_MY_SEED_HEX;
  MY_PUBKEY_HEX = DEFAULT_MY_PUBKEY_HEX;
  MY_PRVKEY_HEX = DEFAULT_MY_PRVKEY_HEX;
  TELEMETRY_REQUEST_INTERVAL_MS = DEFAULT_TELEMETRY_REQUEST_INTERVAL_MS;
  PATH_HASH_BITS = DEFAULT_PATH_HASH_BITS;
  ENABLE_SCOPE = DEFAULT_ENABLE_SCOPE;
  SCOPE_NAME = DEFAULT_SCOPE_NAME;
  Serial.println("[NVS] Nustatymai atstatyti į pradines config.h reikšmes.");
}

// ================================================================================
// 🌐 WWW INTERFEISO HTML/CSS/JS (PILNAI AUTONOMIŠKAS, BE IŠORINIŲ PRIKLAUSOMYBIŲ)
// ================================================================================
static const char INDEX_HTML[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html lang="lt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MeshCore Bot | Valdymas</title>
  <style>
    :root {
      --bg: #090d16;
      --card-bg: #111827;
      --card-border: #1f293d;
      --card-inner: #162032;
      --text: #e2e8f0;
      --text-muted: #94a3b8;
      --text-dim: #64748b;
      --cyan: #38bdf8;
      --cyan-glow: rgba(56, 189, 248, 0.25);
      --green: #22c55e;
      --green-glow: rgba(34, 197, 94, 0.2);
      --red: #ef4444;
      --amber: #f59e0b;
      --radius: 12px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background-color: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      padding: 16px;
      line-height: 1.5;
      font-size: 14px;
    }
    .container {
      max-width: 860px;
      margin: 0 auto;
    }
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
      padding: 16px 20px;
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: var(--radius);
      margin-bottom: 20px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.4);
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .brand svg {
      width: 32px;
      height: 32px;
      fill: var(--cyan);
      filter: drop-shadow(0 0 8px var(--cyan-glow));
    }
    .brand h1 {
      font-size: 1.25rem;
      font-weight: 700;
      color: #fff;
      letter-spacing: -0.02em;
    }
    .brand p {
      font-size: 0.78rem;
      color: var(--text-muted);
    }
    .status-chips {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 600;
      background: rgba(255,255,255,0.05);
      border: 1px solid var(--card-border);
    }
    .chip.online {
      background: rgba(34, 197, 94, 0.12);
      border-color: rgba(34, 197, 94, 0.35);
      color: var(--green);
    }
    .chip.offline {
      background: rgba(239, 68, 68, 0.12);
      border-color: rgba(239, 68, 68, 0.35);
      color: var(--red);
    }
    .dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: currentColor;
    }
    .card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: var(--radius);
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.25);
    }
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--card-border);
      flex-wrap: wrap;
      gap: 8px;
    }
    .card-title {
      font-size: 1rem;
      font-weight: 700;
      color: #fff;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .wifi-status-box {
      background: var(--card-inner);
      border: 1px solid var(--card-border);
      border-radius: var(--radius);
      padding: 16px;
      margin-bottom: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
    }
    .signal-meter {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .signal-bars {
      display: flex;
      align-items: flex-end;
      gap: 3px;
      height: 22px;
    }
    .bar {
      width: 5px;
      background: #334155;
      border-radius: 2px;
    }
    .b1 { height: 6px; }
    .b2 { height: 11px; }
    .b3 { height: 16px; }
    .b4 { height: 22px; }
    .signal-bars.s1 .b1 { background: var(--red); }
    .signal-bars.s2 .b1, .signal-bars.s2 .b2 { background: var(--amber); }
    .signal-bars.s3 .b1, .signal-bars.s3 .b2, .signal-bars.s3 .b3 { background: #a3e635; }
    .signal-bars.s4 .bar { background: var(--green); }
    .signal-bars.s0 .bar { background: #334155; }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: var(--cyan);
      color: #000;
      font-weight: 700;
      font-size: 0.85rem;
      padding: 9px 18px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn:hover {
      background: #7dd3fc;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px var(--cyan-glow);
    }
    .btn:active { transform: translateY(0); }
    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
      transform: none;
    }
    .btn-secondary {
      background: rgba(255,255,255,0.08);
      color: var(--text);
      border: 1px solid var(--card-border);
    }
    .btn-secondary:hover {
      background: rgba(255,255,255,0.14);
      color: #fff;
      box-shadow: none;
    }
    .btn-success {
      background: var(--green);
      color: #fff;
    }
    .btn-success:hover {
      background: #4ade80;
      box-shadow: 0 4px 12px var(--green-glow);
    }
    .btn-danger {
      background: rgba(239,68,68,0.15);
      color: var(--red);
      border: 1px solid rgba(239,68,68,0.3);
    }
    .btn-danger:hover {
      background: var(--red);
      color: #fff;
    }
    .btn-sm {
      padding: 6px 12px;
      font-size: 0.78rem;
    }

    .network-list {
      max-height: 220px;
      overflow-y: auto;
      border: 1px solid var(--card-border);
      border-radius: 8px;
      background: #0d131f;
      margin-bottom: 16px;
    }
    .net-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 14px;
      border-bottom: 1px solid rgba(255,255,255,0.04);
      cursor: pointer;
      transition: background 0.15s;
    }
    .net-item:last-child { border-bottom: none; }
    .net-item:hover {
      background: rgba(56, 189, 248, 0.08);
    }
    .net-item.selected {
      background: rgba(56, 189, 248, 0.15);
      border-left: 3px solid var(--cyan);
    }
    .net-name {
      font-weight: 600;
      color: #fff;
    }
    .net-meta {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.78rem;
      color: var(--text-muted);
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 16px;
    }
    .form-group {
      margin-bottom: 14px;
    }
    .form-group.full {
      grid-column: 1 / -1;
    }
    label {
      display: block;
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 6px;
    }
    .label-hint {
      font-weight: 400;
      font-size: 0.74rem;
      color: var(--text-dim);
      margin-left: 4px;
    }
    .input-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }
    input, select {
      width: 100%;
      background: #0a0f1d;
      border: 1px solid var(--card-border);
      border-radius: 8px;
      padding: 9px 12px;
      color: #fff;
      font-size: 0.88rem;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    input:focus, select:focus {
      border-color: var(--cyan);
      box-shadow: 0 0 0 3px var(--cyan-glow);
    }
    input.mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 0.82rem;
    }
    .toggle-pass {
      position: absolute;
      right: 10px;
      background: none;
      border: none;
      color: var(--text-dim);
      cursor: pointer;
      font-size: 1rem;
      padding: 4px;
    }
    .toggle-pass:hover { color: var(--cyan); }

    .switch-wrap {
      display: flex;
      align-items: center;
      gap: 12px;
      cursor: pointer;
      margin-top: 6px;
    }
    .switch {
      position: relative;
      width: 44px;
      height: 24px;
      background: #334155;
      border-radius: 999px;
      transition: background 0.2s;
    }
    .switch::after {
      content: '';
      position: absolute;
      top: 3px;
      left: 3px;
      width: 18px;
      height: 18px;
      background: #fff;
      border-radius: 50%;
      transition: transform 0.2s;
    }
    input[type="checkbox"]:checked + .switch {
      background: var(--cyan);
    }
    input[type="checkbox"]:checked + .switch::after {
      transform: translateX(20px);
    }
    input[type="checkbox"] { display: none; }

    .actions-bar {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 24px;
      padding: 16px;
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: var(--radius);
    }

    .alert {
      padding: 10px 14px;
      border-radius: 8px;
      margin-top: 12px;
      font-size: 0.82rem;
      display: none;
    }
    .alert.success {
      background: rgba(34, 197, 94, 0.15);
      border: 1px solid rgba(34, 197, 94, 0.4);
      color: #4ade80;
      display: block;
    }
    .alert.error {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #f87171;
      display: block;
    }
    .alert.info {
      background: rgba(56, 189, 248, 0.15);
      border: 1px solid rgba(56, 189, 248, 0.4);
      color: #38bdf8;
      display: block;
    }

    .toast {
      position: fixed;
      bottom: 24px;
      right: 24px;
      padding: 12px 20px;
      background: #1e293b;
      color: #fff;
      border-radius: 8px;
      border-left: 4px solid var(--cyan);
      box-shadow: 0 10px 25px rgba(0,0,0,0.5);
      font-size: 0.85rem;
      font-weight: 600;
      z-index: 999;
      opacity: 0;
      transform: translateY(20px);
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      pointer-events: none;
    }
    .toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .spinner {
      display: inline-block;
      width: 14px;
      height: 14px;
      border: 2px solid rgba(255,255,255,0.3);
      border-radius: 50%;
      border-top-color: currentColor;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <div class="container">
    <header>
      <div class="brand">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.4z"/></svg>
        <div>
          <h1 id="hdrTitle">Seeed Xiao S3 MeshCore Bot</h1>
          <p>Boto konfigūracijos valdymo pultas (AP & STA)</p>
        </div>
      </div>
      <div class="status-chips">
        <div class="chip" id="chipWifi"><span class="dot"></span> <span id="chipWifiText">Tikrinama...</span></div>
        <div class="chip"><span style="color:var(--cyan)">AP:</span> <span id="chipApIp">192.168.4.1</span></div>
        <div class="chip"><span style="color:var(--text-muted)">STA IP:</span> <span id="chipStaIp">—</span></div>
      </div>
    </header>

    <!-- KORTELĖ 1: WI-FI VALDYMAS IR SKENAVIMAS -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span>📶 Wi-Fi Ryšys & Prieigos Taškai</span>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" id="btnScan" onclick="scanWifi()">
          <span>📡 Skenuoti tinklus</span>
        </button>
      </div>

      <!-- Esamo ryšio būsenos juosta -->
      <div class="wifi-status-box">
        <div>
          <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Dabartinė Wi-Fi Būsena</div>
          <div style="font-size:1.05rem; font-weight:700; color:#fff; margin-top:2px;" id="stWifiName">Tikrinama...</div>
          <div style="font-size:0.8rem; color:var(--text-dim);" id="stWifiDetails">—</div>
        </div>
        <div class="signal-meter">
          <div class="signal-bars s0" id="stSignalBars">
            <div class="bar b1"></div>
            <div class="bar b2"></div>
            <div class="bar b3"></div>
            <div class="bar b4"></div>
          </div>
          <div>
            <div style="font-size:0.95rem; font-weight:700;" id="stSignalDbm">— dBm</div>
            <div style="font-size:0.72rem; color:var(--text-muted);" id="stSignalQuality">Nėra ryšio</div>
          </div>
        </div>
      </div>

      <!-- Nuskenuotų tinklų sąrašas -->
      <div id="scanWrap" style="display:none;">
        <label>Aptikti Wi-Fi tinklai <span class="label-hint">(paspauskite ant tinklo, kad užpildytumėte SSID)</span></label>
        <div class="network-list" id="networkList"></div>
      </div>

      <!-- Prisijungimo forma -->
      <div class="form-grid">
        <div class="form-group">
          <label for="WIFI_SSID">Wi-Fi Tinklo Pavadinimas (SSID)</label>
          <input type="text" id="WIFI_SSID" placeholder="Pvz. 2.4GHz arba ManoTinklas">
        </div>
        <div class="form-group">
          <label for="WIFI_PASSWORD">Wi-Fi Slaptažodis</label>
          <div class="input-wrap">
            <input type="password" id="WIFI_PASSWORD" placeholder="Įveskite tinklo slaptažodį">
            <button type="button" class="toggle-pass" onclick="togglePass('WIFI_PASSWORD', this)">👁️</button>
          </div>
        </div>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-top:8px;">
        <div style="font-size:0.78rem; color:var(--text-dim);">
          Spauskite „Prisijungti“, jei norite išbandyti ryšį dabar. Sėkmingai prisijungus, duomenys automatiškai išsaugomi į atmintį.
        </div>
        <button type="button" class="btn btn-success" id="btnConnect" onclick="connectWifi()">
          <span>⚡ Prisijungti dabar</span>
        </button>
      </div>

      <div id="connectAlert" class="alert"></div>
    </div>

    <!-- KORTELĖ 2: BOTO IR PERIODIŠKUMO NUSTATYMAI -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span>⚙️ Boto & Protokolo Nustatymai</span>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label for="BOT_ID">BOT_ID <span class="label-hint">(3-16 simbolių boto ID)</span></label>
          <input type="text" id="BOT_ID" maxlength="16" placeholder="KLP">
        </div>
        <div class="form-group">
          <label for="TELEMETRY_REQUEST_INTERVAL_MS">TELEMETRY_REQUEST_INTERVAL_MS <span class="label-hint">(Ciklo intervalas, ms)</span></label>
          <input type="number" id="TELEMETRY_REQUEST_INTERVAL_MS" step="1000" min="5000">
        </div>
        <div class="form-group">
          <label for="REPEATER_REPLY_TIMEOUT_MS">REPEATER_REPLY_TIMEOUT_MS <span class="label-hint">(Tiesioginio kartotuvo laukimas, ms)</span></label>
          <input type="number" id="REPEATER_REPLY_TIMEOUT_MS" step="500" min="1000">
        </div>
        <div class="form-group">
          <label for="REPEATER_REMOTE_REPLY_TIMEOUT_MS">REPEATER_REMOTE_REPLY_TIMEOUT_MS <span class="label-hint">(Multi-hop kartotuvų laukimas, ms)</span></label>
          <input type="number" id="REPEATER_REMOTE_REPLY_TIMEOUT_MS" step="500" min="1000">
        </div>
        <div class="form-group">
          <label for="INTER_REQUEST_DELAY_MS">INTER_REQUEST_DELAY_MS <span class="label-hint">(Pauzė tarp užklausų, ms)</span></label>
          <input type="number" id="INTER_REQUEST_DELAY_MS" step="500" min="0">
        </div>
        <div class="form-group">
          <label for="REPEATER_COOLDOWN_MS">REPEATER_COOLDOWN_MS <span class="label-hint">(Pauzė tarp kartotuvų, ms)</span></label>
          <input type="number" id="REPEATER_COOLDOWN_MS" step="500" min="0">
        </div>
      </div>
    </div>

    <!-- KORTELĖ 3: KRIPTOGRAFIJA IR RAKTAI -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span>🔑 Kriptografija & Ed25519 Raktai</span>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" id="btnGenKeys" onclick="generateAllKeys()">
          <span>🎲 Generuoti visus raktus</span>
        </button>
      </div>
      <div class="form-group">
        <label for="MY_SEED_HEX">MY_SEED_HEX <span class="label-hint">(32 baitai / 64 hex simboliai – mūsų tapatybės sėkla)</span></label>
        <input type="text" id="MY_SEED_HEX" class="mono" placeholder="4a9232cb...">
      </div>
      <div class="form-group">
        <label for="MY_PUBKEY_HEX">MY_PUBKEY_HEX <span class="label-hint">(32 baitai / 64 hex simboliai – Ed25519 viešasis raktas tinkle)</span></label>
        <input type="text" id="MY_PUBKEY_HEX" class="mono" placeholder="0bbd84b0...">
      </div>
      <div class="form-group">
        <label for="MY_PRVKEY_HEX">MY_PRVKEY_HEX <span class="label-hint">(64 baitai / 128 hex simboliai – privatus raktas)</span></label>
        <div class="input-wrap">
          <input type="password" id="MY_PRVKEY_HEX" class="mono" placeholder="4a9232cb...">
          <button type="button" class="toggle-pass" onclick="togglePass('MY_PRVKEY_HEX', this)">👁️</button>
        </div>
      </div>
    </div>

    <!-- KORTELĖ 4: MESHCORE MARŠRUTIZAVIMAS IR SCOPE -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span>🌐 MeshCore Maršrutizavimas & Scope</span>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label for="PATH_HASH_BITS">PATH_HASH_BITS <span class="label-hint">(Kelio hash ilgis bitais)</span></label>
          <select id="PATH_HASH_BITS">
            <option value="8">8 bitai (1 baitas – iki 254 mazgų)</option>
            <option value="16">16 bitų (2 baitai – rekomenduojama Lietuvoje)</option>
            <option value="24">24 bitai (3 baitai – dideliems tinklams)</option>
          </select>
        </div>
        <div class="form-group">
          <label for="SCOPE_NAME">SCOPE_NAME <span class="label-hint">(Regiono/potinklio pavadinimas)</span></label>
          <input type="text" id="SCOPE_NAME" placeholder="lt">
        </div>
        <div class="form-group full">
          <label>ENABLE_SCOPE</label>
          <label class="switch-wrap">
            <input type="checkbox" id="ENABLE_SCOPE">
            <span class="switch"></span>
            <span style="font-size:0.85rem; color:#fff; font-weight:600;">Įjungti regioninį potinklio Scoped Flood (TRANSPORT_FLOOD)</span>
          </label>
        </div>
      </div>
    </div>

    <!-- VEIKSMŲ MYGTUKŲ JUOSTA -->
    <div class="actions-bar">
      <button type="button" class="btn btn-danger btn-sm" onclick="resetDefaults()">
        <span>↺ Atstatyti numatytuosius (iš config.h)</span>
      </button>
      <button type="button" class="btn btn-secondary" onclick="restartDevice()">
        <span>⚡ Perkrauti ESP32</span>
      </button>
      <button type="button" class="btn btn-secondary" onclick="saveSettings(false)">
        <span>💾 Išsaugoti nustatymus</span>
      </button>
      <button type="button" class="btn" onclick="saveSettings(true)">
        <span>🔄 Išsaugoti ir perkrauti</span>
      </button>
    </div>
  </div>

  <div id="toast" class="toast">Nustatymai išsaugoti</div>

  <script>
    function showToast(msg, type='info') {
      const t = document.getElementById('toast');
      t.innerText = msg;
      t.style.borderLeftColor = (type === 'success') ? 'var(--green)' : (type === 'error' ? 'var(--red)' : 'var(--cyan)');
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3500);
    }

    function togglePass(id, btn) {
      const el = document.getElementById(id);
      if (el.type === 'password') {
        el.type = 'text';
        btn.innerText = '🔒';
      } else {
        el.type = 'password';
        btn.innerText = '👁️';
      }
    }

    async function generateAllKeys() {
      const btn = document.getElementById('btnGenKeys');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> <span>Generuojama...</span>';
      }
      try {
        const res = await fetch('/api/generate_keys', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({})
        });
        const data = await res.json();
        if (data.success) {
          document.getElementById('MY_SEED_HEX').value = data.seed;
          document.getElementById('MY_PUBKEY_HEX').value = data.pubkey;
          document.getElementById('MY_PRVKEY_HEX').value = data.prvkey;
          showToast('Sugeneruoti nauji raktai: Seed, Viešasis ir Privatus!', 'success');
        } else {
          showToast('Nepavyko sugeneruoti raktų', 'error');
        }
      } catch (err) {
        showToast('Klaida: ' + err.message, 'error');
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<span>🎲 Generuoti visus raktus</span>';
        }
      }
    }

    async function fetchStatus() {
      try {
        const res = await fetch('/api/status');
        const data = await res.json();
        
        const isConn = data.wifi_connected;
        const chip = document.getElementById('chipWifi');
        const chipTxt = document.getElementById('chipWifiText');
        const staIp = document.getElementById('chipStaIp');
        const apIp = document.getElementById('chipApIp');

        apIp.innerText = data.ap_ip || '192.168.4.1';
        staIp.innerText = isConn ? data.wifi_ip : '—';

        if (isConn) {
          chip.className = 'chip online';
          chipTxt.innerText = `Prisijungta: ${data.wifi_ssid}`;
          document.getElementById('stWifiName').innerText = data.wifi_ssid;
          let apStatusStr = data.ap_active ? `AP: ${data.ap_ssid} (Aktyvus)` : 'AP: Išjungtas';
          document.getElementById('stWifiDetails').innerText = `IP: ${data.wifi_ip} | ${apStatusStr}`;
          
          const rssi = parseInt(data.wifi_rssi, 10);
          document.getElementById('stSignalDbm').innerText = `${rssi} dBm`;
          
          const bars = document.getElementById('stSignalBars');
          const qText = document.getElementById('stSignalQuality');
          bars.className = 'signal-bars';
          if (rssi >= -60) {
            bars.classList.add('s4');
            qText.innerText = 'Puikus signalas';
          } else if (rssi >= -70) {
            bars.classList.add('s3');
            qText.innerText = 'Geras signalas';
          } else if (rssi >= -82) {
            bars.classList.add('s2');
            qText.innerText = 'Vidutinis signalas';
          } else {
            bars.classList.add('s1');
            qText.innerText = 'Silpnas signalas';
          }
        } else {
          chip.className = 'chip offline';
          chipTxt.innerText = 'Neprisijungta prie Wi-Fi';
          document.getElementById('stWifiName').innerText = 'Neprisijungta prie Wi-Fi';
          if (data.ap_active) {
            document.getElementById('stWifiDetails').innerText = `Prieigos taškas (AP): ${data.ap_ssid} (${data.ap_ip}) • Aktyvus`;
          } else {
            document.getElementById('stWifiDetails').innerText = `Prieigos taškas (AP): Išjungtas`;
          }
          document.getElementById('stSignalBars').className = 'signal-bars s0';
          document.getElementById('stSignalDbm').innerText = '— dBm';
          document.getElementById('stSignalQuality').innerText = 'Nėra ryšio';
        }
      } catch (err) {
        console.warn('Status klaida:', err);
      }
    }

    async function scanWifi() {
      const btn = document.getElementById('btnScan');
      const list = document.getElementById('networkList');
      const wrap = document.getElementById('scanWrap');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> <span>Skenuojama...</span>';

      try {
        wrap.style.display = 'block';
        list.innerHTML = '<div style="padding:14px; text-align:center; color:var(--text-muted);"><span class="spinner"></span> Ieškoma aplinkinių Wi-Fi tinklų...</div>';
        
        const res = await fetch('/api/scan');
        const data = await res.json();
        const networks = data.networks || [];

        if (networks.length === 0) {
          list.innerHTML = '<div style="padding:14px; text-align:center; color:var(--text-muted);">Tinklų neaptikta. Pabandykite dar kartą.</div>';
        } else {
          list.innerHTML = '';
          networks.forEach(net => {
            const item = document.createElement('div');
            item.className = 'net-item';
            
            let sigClass = 's1';
            if (net.rssi >= -60) sigClass = 's4';
            else if (net.rssi >= -70) sigClass = 's3';
            else if (net.rssi >= -82) sigClass = 's2';

            item.innerHTML = `
              <div class="net-name">${net.ssid || '<i>(Paslėptas SSID)</i>'}</div>
              <div class="net-meta">
                <span>${net.auth}</span>
                <span style="font-weight:700;">${net.rssi} dBm</span>
                <div class="signal-bars ${sigClass}" style="height:14px;">
                  <div class="bar b1"></div>
                  <div class="bar b2"></div>
                  <div class="bar b3"></div>
                  <div class="bar b4"></div>
                </div>
              </div>
            `;
            item.onclick = () => {
              document.querySelectorAll('.net-item').forEach(el => el.classList.remove('selected'));
              item.classList.add('selected');
              document.getElementById('WIFI_SSID').value = net.ssid;
              document.getElementById('WIFI_PASSWORD').focus();
              showToast(`Pasirinktas tinklas: ${net.ssid}`);
            };
            list.appendChild(item);
          });
        }
      } catch (err) {
        list.innerHTML = `<div style="padding:14px; text-align:center; color:var(--red);">Klaida skenuojant: ${err.message}</div>`;
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<span>📡 Skenuoti tinklus</span>';
      }
    }

    async function connectWifi() {
      const btn = document.getElementById('btnConnect');
      const alert = document.getElementById('connectAlert');
      const ssid = document.getElementById('WIFI_SSID').value.trim();
      const password = document.getElementById('WIFI_PASSWORD').value;

      if (!ssid) {
        alert.className = 'alert error';
        alert.innerText = 'Prašome nurodyti Wi-Fi tinklo SSID!';
        return;
      }

      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> <span>Jungiamasi...</span>';
      alert.className = 'alert info';
      alert.innerText = `Jungiamasi prie '${ssid}'... Tai gali užtrukti iki 15 sekundžių.`;

      try {
        const res = await fetch('/api/connect', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ ssid, password })
        });
        const data = await res.json();
        
        if (data.success) {
          alert.className = 'alert success';
          alert.innerText = `✅ ${data.message} IP: ${data.ip} | Signalas: ${data.rssi} dBm`;
          showToast('Prisijungta sėkmingai!', 'success');
          fetchStatus();
        } else {
          alert.className = 'alert error';
          alert.innerText = `❌ ${data.error || 'Nepavyko prisijungti prie nurodyto Wi-Fi tinklo.'}`;
        }
      } catch (err) {
        alert.className = 'alert error';
        alert.innerText = `Klaida: ${err.message}`;
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<span>⚡ Prisijungti dabar</span>';
      }
    }

    async function loadSettings() {
      try {
        const res = await fetch('/api/settings');
        const data = await res.json();
        
        document.getElementById('WIFI_SSID').value = data.WIFI_SSID || '';
        document.getElementById('WIFI_PASSWORD').value = data.WIFI_PASSWORD || '';
        document.getElementById('BOT_ID').value = data.BOT_ID || 'KLP';
        document.getElementById('TELEMETRY_REQUEST_INTERVAL_MS').value = data.TELEMETRY_REQUEST_INTERVAL_MS || 60000;
        document.getElementById('REPEATER_REPLY_TIMEOUT_MS').value = data.REPEATER_REPLY_TIMEOUT_MS || 12000;
        document.getElementById('REPEATER_REMOTE_REPLY_TIMEOUT_MS').value = data.REPEATER_REMOTE_REPLY_TIMEOUT_MS || 30000;
        document.getElementById('INTER_REQUEST_DELAY_MS').value = data.INTER_REQUEST_DELAY_MS || 10000;
        document.getElementById('REPEATER_COOLDOWN_MS').value = data.REPEATER_COOLDOWN_MS || 10000;
        document.getElementById('MY_SEED_HEX').value = data.MY_SEED_HEX || '';
        document.getElementById('MY_PUBKEY_HEX').value = data.MY_PUBKEY_HEX || '';
        document.getElementById('MY_PRVKEY_HEX').value = data.MY_PRVKEY_HEX || '';
        document.getElementById('PATH_HASH_BITS').value = data.PATH_HASH_BITS || 16;
        document.getElementById('ENABLE_SCOPE').checked = !!data.ENABLE_SCOPE;
        document.getElementById('SCOPE_NAME').value = data.SCOPE_NAME || 'lt';
      } catch (err) {
        console.error('Klaida kraunant nustatymus:', err);
      }
    }

    async function saveSettings(restartAfter) {
      const payload = {
        WIFI_SSID: document.getElementById('WIFI_SSID').value.trim(),
        WIFI_PASSWORD: document.getElementById('WIFI_PASSWORD').value,
        BOT_ID: document.getElementById('BOT_ID').value.trim(),
        TELEMETRY_REQUEST_INTERVAL_MS: parseInt(document.getElementById('TELEMETRY_REQUEST_INTERVAL_MS').value, 10),
        REPEATER_REPLY_TIMEOUT_MS: parseInt(document.getElementById('REPEATER_REPLY_TIMEOUT_MS').value, 10),
        REPEATER_REMOTE_REPLY_TIMEOUT_MS: parseInt(document.getElementById('REPEATER_REMOTE_REPLY_TIMEOUT_MS').value, 10),
        INTER_REQUEST_DELAY_MS: parseInt(document.getElementById('INTER_REQUEST_DELAY_MS').value, 10),
        REPEATER_COOLDOWN_MS: parseInt(document.getElementById('REPEATER_COOLDOWN_MS').value, 10),
        MY_SEED_HEX: document.getElementById('MY_SEED_HEX').value.trim(),
        MY_PUBKEY_HEX: document.getElementById('MY_PUBKEY_HEX').value.trim(),
        MY_PRVKEY_HEX: document.getElementById('MY_PRVKEY_HEX').value.trim(),
        PATH_HASH_BITS: parseInt(document.getElementById('PATH_HASH_BITS').value, 10),
        ENABLE_SCOPE: document.getElementById('ENABLE_SCOPE').checked,
        SCOPE_NAME: document.getElementById('SCOPE_NAME').value.trim()
      };

      try {
        const res = await fetch('/api/settings', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        
        if (data.success) {
          showToast('Nustatymai sėkmingai išsaugoti į vidinę atmintį!', 'success');
          if (restartAfter) {
            setTimeout(() => restartDevice(), 600);
          } else {
            loadSettings();
            fetchStatus();
          }
        } else {
          showToast(data.error || 'Klaida išsaugant', 'error');
        }
      } catch (err) {
        showToast('Tinklo klaida išsaugant: ' + err.message, 'error');
      }
    }

    async function resetDefaults() {
      if (!confirm('Ar tikrai norite atstatyti visus nustatymus į numatytuosius iš config.h?')) return;
      try {
        const res = await fetch('/api/reset', { method: 'POST' });
        const data = await res.json();
        showToast(data.message || 'Atstatyta!', 'info');
        loadSettings();
        fetchStatus();
      } catch (err) {
        showToast('Klaida atstatant: ' + err.message, 'error');
      }
    }

    async function restartDevice() {
      if (!confirm('Perkrauti ESP32 modulį?')) return;
      showToast('ESP32 persikrauna... Palaukite kelias sekundes.', 'info');
      try {
        await fetch('/api/restart', { method: 'POST' });
      } catch (e) {}
      setTimeout(() => { location.reload(); }, 6000);
    }

    // Inicializacija
    window.onload = () => {
      fetchStatus();
      loadSettings();
      setInterval(fetchStatus, 4000);

      const seedEl = document.getElementById('MY_SEED_HEX');
      if (seedEl) {
        seedEl.addEventListener('change', async function() {
          const val = this.value.trim();
          if (val.length === 64) {
            try {
              const res = await fetch('/api/generate_keys', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ seed: val })
              });
              const data = await res.json();
              if (data.success) {
                document.getElementById('MY_PUBKEY_HEX').value = data.pubkey;
                document.getElementById('MY_PRVKEY_HEX').value = data.prvkey;
                showToast('Pagal įvestą Seed atnaujinti viešasis ir privatus raktai!', 'info');
              }
            } catch (e) {}
          }
        });
      }
    };
  </script>
</body>
</html>
)rawliteral";

// ================================================================================
// 📡 API ENDPOINT'Ų APDOROJIMAS
// ================================================================================

inline void handleRoot() {
  lastApActivityTime = millis();
  webServer.send_P(200, "text/html", INDEX_HTML);
}

inline void handleApiStatus() {
  lastApActivityTime = millis();
  bool isConn = (WiFi.status() == WL_CONNECTED);
  String ip = isConn ? WiFi.localIP().toString() : "0.0.0.0";
  int rssi = isConn ? WiFi.RSSI() : 0;

#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
#else
  DynamicJsonDocument doc(512);
#endif
  doc["wifi_connected"] = isConn;
  doc["wifi_status"] = isConn ? "connected" : "disconnected";
  doc["wifi_ssid"] = isConn ? WiFi.SSID() : WIFI_SSID;
  doc["wifi_ip"] = ip;
  doc["wifi_rssi"] = rssi;
  doc["ap_active"] = apActive;
  doc["ap_ssid"] = DEFAULT_AP_SSID;
  doc["ap_ip"] = apActive ? WiFi.softAPIP().toString() : "0.0.0.0";
  doc["ap_clients"] = apActive ? WiFi.softAPgetStationNum() : 0;
  doc["bot_id"] = BOT_ID;
  doc["uptime_sec"] = millis() / 1000;
  doc["free_heap"] = ESP.getFreeHeap();

  String out;
  serializeJson(doc, out);
  webServer.send(200, "application/json", out);
}

inline void handleApiScan() {
  Serial.println("[WebPortal] Vykdomas Wi-Fi tinklų skenavimas...");
  int n = WiFi.scanNetworks(false, true);

#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
  JsonArray arr = doc["networks"].to<JsonArray>();
#else
  DynamicJsonDocument doc(4096);
  JsonArray arr = doc.createNestedArray("networks");
#endif

  for (int i = 0; i < n; ++i) {
#if ARDUINOJSON_VERSION_MAJOR >= 7
    JsonObject net = arr.add<JsonObject>();
#else
    JsonObject net = arr.createNestedObject();
#endif
    net["ssid"] = WiFi.SSID(i);
    net["rssi"] = WiFi.RSSI(i);
    net["channel"] = WiFi.channel(i);
    
    const char* secType = "Atviras";
    bool isSec = true;
    switch (WiFi.encryptionType(i)) {
      case WIFI_AUTH_OPEN:            secType = "Atviras"; isSec = false; break;
      case WIFI_AUTH_WEP:             secType = "WEP"; break;
      case WIFI_AUTH_WPA_PSK:         secType = "WPA"; break;
      case WIFI_AUTH_WPA2_PSK:        secType = "WPA2"; break;
      case WIFI_AUTH_WPA_WPA2_PSK:    secType = "WPA/WPA2"; break;
      case WIFI_AUTH_WPA2_ENTERPRISE: secType = "Enterprise"; break;
      case WIFI_AUTH_WPA3_PSK:        secType = "WPA3"; break;
      case WIFI_AUTH_WPA2_WPA3_PSK:   secType = "WPA2/WPA3"; break;
      default:                        secType = "Apsaugotas"; break;
    }
    net["auth"] = secType;
    net["secure"] = isSec;
  }
  WiFi.scanDelete();

  String out;
  serializeJson(doc, out);
  webServer.send(200, "application/json", out);
}

inline void handleApiConnect() {
  if (!webServer.hasArg("plain")) {
    webServer.send(400, "application/json", "{\"success\":false,\"error\":\"Tuščia užklausa\"}");
    return;
  }

#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
#else
  DynamicJsonDocument doc(512);
#endif
  DeserializationError err = deserializeJson(doc, webServer.arg("plain"));
  if (err) {
    webServer.send(400, "application/json", "{\"success\":false,\"error\":\"Neteisingas JSON formatas\"}");
    return;
  }

  String newSsid = doc["ssid"].as<String>();
  String newPass = doc["password"].as<String>();

  if (newSsid.length() == 0) {
    webServer.send(400, "application/json", "{\"success\":false,\"error\":\"SSID negali būti tuščias\"}");
    return;
  }

  Serial.printf("\n[WebPortal] Bandymas jungtis prie Wi-Fi '%s'...\n", newSsid.c_str());

  WiFi.disconnect(false);
  delay(100);
  WiFi.begin(newSsid.c_str(), newPass.c_str());

  unsigned long start = millis();
  bool connected = false;
  while (millis() - start < 14000) {
    if (WiFi.status() == WL_CONNECTED) {
      connected = true;
      break;
    }
    delay(200);
    dnsServer.processNextRequest();
    webServer.handleClient();
  }

  if (connected) {
    WIFI_SSID = newSsid;
    WIFI_PASSWORD = newPass;
    saveConfigToNVS();

    String ipStr = WiFi.localIP().toString();
    int rssi = WiFi.RSSI();
    Serial.printf("[WebPortal] Sėkmingai prisijungta prie '%s'! IP: %s, RSSI: %d dBm\n",
                  newSsid.c_str(), ipStr.c_str(), rssi);

    configTime(0, 0, "pool.ntp.org", "time.google.com");

    String resp = "{\"success\":true,\"message\":\"Sėkmingai prisijungta!\",\"ssid\":\"" + newSsid +
                  "\",\"ip\":\"" + ipStr + "\",\"rssi\":" + String(rssi) + "}";
    webServer.send(200, "application/json", resp);
  } else {
    Serial.printf("[WebPortal] Nepavyko prisijungti prie '%s'\n", newSsid.c_str());
    String resp = "{\"success\":false,\"error\":\"Nepavyko prisijungti. Patikrinkite slaptažodį arba tinklo atstumą.\",\"ssid\":\"" + newSsid + "\"}";
    webServer.send(200, "application/json", resp);
  }
}

inline void handleApiGetSettings() {
#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
#else
  DynamicJsonDocument doc(1536);
#endif
  doc["WIFI_SSID"] = WIFI_SSID;
  doc["WIFI_PASSWORD"] = WIFI_PASSWORD;
  doc["BOT_ID"] = BOT_ID;
  doc["REPEATER_REPLY_TIMEOUT_MS"] = REPEATER_REPLY_TIMEOUT_MS;
  doc["REPEATER_REMOTE_REPLY_TIMEOUT_MS"] = REPEATER_REMOTE_REPLY_TIMEOUT_MS;
  doc["INTER_REQUEST_DELAY_MS"] = INTER_REQUEST_DELAY_MS;
  doc["REPEATER_COOLDOWN_MS"] = REPEATER_COOLDOWN_MS;
  doc["MY_SEED_HEX"] = MY_SEED_HEX;
  doc["MY_PUBKEY_HEX"] = MY_PUBKEY_HEX;
  doc["MY_PRVKEY_HEX"] = MY_PRVKEY_HEX;
  doc["TELEMETRY_REQUEST_INTERVAL_MS"] = TELEMETRY_REQUEST_INTERVAL_MS;
  doc["PATH_HASH_BITS"] = PATH_HASH_BITS;
  doc["ENABLE_SCOPE"] = ENABLE_SCOPE;
  doc["SCOPE_NAME"] = SCOPE_NAME;

  String out;
  serializeJson(doc, out);
  webServer.send(200, "application/json", out);
}

inline void handleApiSaveSettings() {
  if (!webServer.hasArg("plain")) {
    webServer.send(400, "application/json", "{\"success\":false,\"error\":\"Tuščia užklausa\"}");
    return;
  }

#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
#else
  DynamicJsonDocument doc(1536);
#endif
  DeserializationError err = deserializeJson(doc, webServer.arg("plain"));
  if (err) {
    webServer.send(400, "application/json", "{\"success\":false,\"error\":\"Neteisingas JSON formatas\"}");
    return;
  }

  JsonObject obj = doc.as<JsonObject>();
  if (obj.containsKey("WIFI_SSID")) WIFI_SSID = obj["WIFI_SSID"].as<String>();
  if (obj.containsKey("WIFI_PASSWORD")) WIFI_PASSWORD = obj["WIFI_PASSWORD"].as<String>();
  if (obj.containsKey("BOT_ID")) BOT_ID = obj["BOT_ID"].as<String>();
  if (obj.containsKey("REPEATER_REPLY_TIMEOUT_MS")) REPEATER_REPLY_TIMEOUT_MS = obj["REPEATER_REPLY_TIMEOUT_MS"].as<unsigned long>();
  if (obj.containsKey("REPEATER_REMOTE_REPLY_TIMEOUT_MS")) REPEATER_REMOTE_REPLY_TIMEOUT_MS = obj["REPEATER_REMOTE_REPLY_TIMEOUT_MS"].as<unsigned long>();
  if (obj.containsKey("INTER_REQUEST_DELAY_MS")) INTER_REQUEST_DELAY_MS = obj["INTER_REQUEST_DELAY_MS"].as<unsigned long>();
  if (obj.containsKey("REPEATER_COOLDOWN_MS")) REPEATER_COOLDOWN_MS = obj["REPEATER_COOLDOWN_MS"].as<unsigned long>();
  
  bool seedChanged = false;
  if (obj.containsKey("MY_SEED_HEX")) {
    String newSeed = obj["MY_SEED_HEX"].as<String>();
    if (newSeed.length() > 0 && newSeed != MY_SEED_HEX) {
      MY_SEED_HEX = newSeed;
      seedChanged = true;
    }
  }
  if (obj.containsKey("MY_PUBKEY_HEX")) MY_PUBKEY_HEX = obj["MY_PUBKEY_HEX"].as<String>();
  if (obj.containsKey("MY_PRVKEY_HEX")) MY_PRVKEY_HEX = obj["MY_PRVKEY_HEX"].as<String>();
  if (obj.containsKey("TELEMETRY_REQUEST_INTERVAL_MS")) TELEMETRY_REQUEST_INTERVAL_MS = obj["TELEMETRY_REQUEST_INTERVAL_MS"].as<unsigned long>();
  if (obj.containsKey("PATH_HASH_BITS")) PATH_HASH_BITS = obj["PATH_HASH_BITS"].as<uint8_t>();
  if (obj.containsKey("ENABLE_SCOPE")) ENABLE_SCOPE = obj["ENABLE_SCOPE"].as<bool>();
  if (obj.containsKey("SCOPE_NAME")) SCOPE_NAME = obj["SCOPE_NAME"].as<String>();

  // Jei seed pasikeitė, perskaičiuojame Ed25519 raktus
  if (seedChanged) {
    generateSelfIdentity();
  }

  saveConfigToNVS();
  webServer.send(200, "application/json", "{\"success\":true,\"message\":\"Nustatymai sėkmingai išsaugoti į vidinę atmintį!\"}");
}

inline void handleApiResetSettings() {
  resetConfigNVS();
  generateSelfIdentity();
  webServer.send(200, "application/json", "{\"success\":true,\"message\":\"Atstatyti numatytieji nustatymai iš config.h\"}");
}

inline void handleApiRestart() {
  webServer.send(200, "application/json", "{\"success\":true,\"message\":\"ESP32 persikrauna...\"}");
  delay(600);
  ESP.restart();
}

inline void handleApiGenerateKeys() {
  uint8_t seed[SEED_SIZE];
  bool haveSeed = false;

  if (webServer.hasArg("plain")) {
#if ARDUINOJSON_VERSION_MAJOR >= 7
    JsonDocument inDoc;
#else
    DynamicJsonDocument inDoc(512);
#endif
    if (!deserializeJson(inDoc, webServer.arg("plain"))) {
      if (inDoc.containsKey("seed")) {
        String customSeed = inDoc["seed"].as<String>();
        size_t sLen = 0;
        if (customSeed.length() == 64 && hexStringToBytes(customSeed.c_str(), seed, SEED_SIZE, sLen) && sLen == SEED_SIZE) {
          haveSeed = true;
        }
      }
    }
  }

  if (!haveSeed) {
    esp_fill_random(seed, SEED_SIZE);
  }

  uint8_t pubKey[PUB_KEY_SIZE];
  uint8_t prvKey[PRV_KEY_SIZE];
  MeshCrypto::generateKeypair(pubKey, prvKey, seed);

  String seedHex = bytesToHexString(seed, SEED_SIZE);
  String pubHex = bytesToHexString(pubKey, PUB_KEY_SIZE);
  String prvHex = bytesToHexString(prvKey, PRV_KEY_SIZE);

#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
#else
  DynamicJsonDocument doc(512);
#endif
  doc["success"] = true;
  doc["seed"] = seedHex;
  doc["pubkey"] = pubHex;
  doc["prvkey"] = prvHex;

  String out;
  serializeJson(doc, out);
  webServer.send(200, "application/json", out);
}

inline void handleNotFound() {
  String host = webServer.hostHeader();
  if (host != "192.168.4.1" && host != "meshbot.local" && host != "meshpuller.local") {
    webServer.sendHeader("Location", "http://192.168.4.1/", true);
    webServer.send(302, "text/plain", "");
    return;
  }
  handleRoot();
}

// ================================================================================
// 🚀 INICIALIZAVIMAS IR APTARNAVIMAS
// ================================================================================

inline void initWebConfigPortal() {
  // 1. Konfigūruojame Wi-Fi AP + STA režimą
  WiFi.mode(WIFI_AP_STA);

  IPAddress apIP(192, 168, 4, 1);
  IPAddress netMsk(255, 255, 255, 0);
  WiFi.softAPConfig(apIP, apIP, netMsk);

  String apName = DEFAULT_AP_SSID;

  if (strlen(DEFAULT_AP_PASSWORD) > 0) {
    WiFi.softAP(apName.c_str(), DEFAULT_AP_PASSWORD);
  } else {
    WiFi.softAP(apName.c_str());
  }

  apStartTime = millis();
  lastApActivityTime = millis();
  apActive = true;

  Serial.printf("[WebPortal] Wi-Fi Prieigos Taškas (AP) paleistas: SSID: '%s', IP: %s (veiks 10 min.)\n",
                apName.c_str(), WiFi.softAPIP().toString().c_str());

  // 2. Paleidžiame Captive Portal DNS serverį
  dnsServer.start(53, "*", apIP);

  // 3. Paleidžiame mDNS (meshpuller.local)
  if (MDNS.begin("meshpuller")) {
    Serial.println("[WebPortal] mDNS paleistas: http://meshpuller.local");
  }

  // 4. Užregistruojame maršrutus
  webServer.on("/", HTTP_GET, handleRoot);
  webServer.on("/api/status", HTTP_GET, handleApiStatus);
  webServer.on("/api/scan", HTTP_GET, handleApiScan);
  webServer.on("/api/connect", HTTP_POST, handleApiConnect);
  webServer.on("/api/settings", HTTP_GET, handleApiGetSettings);
  webServer.on("/api/settings", HTTP_POST, handleApiSaveSettings);
  webServer.on("/api/reset", HTTP_POST, handleApiResetSettings);
  webServer.on("/api/restart", HTTP_POST, handleApiRestart);
  webServer.on("/api/generate_keys", HTTP_POST, handleApiGenerateKeys);

  // Captive Portal automatinis atidarymas (iOS, Android, Windows)
  webServer.on("/hotspot-detect.html", HTTP_GET, handleRoot);
  webServer.on("/generate_204", HTTP_GET, handleRoot);
  webServer.on("/gen_204", HTTP_GET, handleRoot);
  webServer.on("/canonical.html", HTTP_GET, handleRoot);
  webServer.onNotFound(handleNotFound);

  webServer.begin();
  Serial.println("[WebPortal] HTTP Serveris paruoštas darbui (Portas 80).");
}

inline void handleWebConfigPortal() {
  if (apActive) {
    dnsServer.processNextRequest();

    // Jei klientas buvo pasijungęs, bet atsijungė ir 10 min. nėra jokio veiksmo - išjungiame AP
    int clients = WiFi.softAPgetStationNum();
    if (clients == 0 && (millis() - lastApActivityTime >= 10 * 60 * 1000UL)) {
      stopWebConfigAP();
    }
  }
  webServer.handleClient();
}

// Paleidus ESP32 paleidžiamas AP ir laukiama 1 minutę, ar kas nors prisijungs prie AP 'MeshPuller'
inline bool waitForApClients(unsigned long waitMs = AP_WAIT_WINDOW_MS) {
  Serial.println("\n========================================================");
  Serial.printf("[AP] Paleistas prieigos taškas 'MeshPuller' (IP: %s)\n", WiFi.softAPIP().toString().c_str());
  Serial.printf("[AP] Laukiama 1 min. (%lu s.), kol kas nors prisijungs prie AP...\n", waitMs / 1000);
  Serial.println("========================================================");

  unsigned long startWait = millis();
  unsigned long lastPrint = 0;

  while (millis() - startWait < waitMs) {
    handleWebConfigPortal();

    // Tikriname, ar kas nors prisijungė prie AP
    if (WiFi.softAPgetStationNum() > 0) {
      Serial.println("\n[AP] ✅ Klientas prisijungė prie 'MeshPuller'!");
      Serial.println("[AP] Web konfigūravimo portalas veiks lygiagrečiai kartu su MeshPuller.");
      lastApActivityTime = millis();
      return true;
    }

    if (millis() - lastPrint >= 5000) {
      lastPrint = millis();
      unsigned long remainingSec = (waitMs - (millis() - startWait)) / 1000;
      Serial.printf("[AP] Laukiama prisijungimų prie 'MeshPuller'... Liko: %lu s.\n", remainingSec);
    }

    delay(20);
  }

  Serial.println("\n[AP] ⏱️ Per 1 min. niekas neprisijungė.");
  Serial.println("[AP] Išjungiamas AP ('MeshPuller'). Jungiamasi prie Wi-Fi ir dirbama kaip MeshPuller...");
  stopWebConfigAP();
  return false;
}

#endif // WEB_CONFIG_PORTAL_H
