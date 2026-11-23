#ifndef WEB_CONFIG_PORTAL_H
#define WEB_CONFIG_PORTAL_H

#include <Arduino.h>
#include <WiFi.h>
#include <WebServer.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <atomic>
#include <ctype.h>
#include <esp_random.h>
#include <nvs.h>
#include <type_traits>
#include "config.h"
#include "MeshCorePacket.h"
#include "MeshCrypto.h"

// ================================================================================
// 🚀 AKTYVIŲ NUSTATYMŲ KINTAMIEJI (APIBRĖŽIMAS)
// ================================================================================
struct BotConfig {
  String wifiSsid = DEFAULT_WIFI_SSID;
  String wifiPassword = DEFAULT_WIFI_PASSWORD;
  String botId = DEFAULT_BOT_ID;
  String seed = DEFAULT_MY_SEED_HEX;
  String scope = DEFAULT_SCOPE_NAME;
  unsigned long replyTimeout = DEFAULT_REPEATER_REPLY_TIMEOUT_MS;
  unsigned long remoteTimeout = DEFAULT_REPEATER_REMOTE_REPLY_TIMEOUT_MS;
  unsigned long interDelay = DEFAULT_INTER_REQUEST_DELAY_MS;
  unsigned long cooldown = DEFAULT_REPEATER_COOLDOWN_MS;
  unsigned long interval = DEFAULT_TELEMETRY_REQUEST_INTERVAL_MS;
  uint8_t pathBits = DEFAULT_PATH_HASH_BITS;
  bool scopeEnabled = DEFAULT_ENABLE_SCOPE;
};

static BotConfig activeConfig;
static BotConfig savedConfig;
static BotConfig legacyRecoveryConfig;
static bool recoveringLegacyConfig = false;
static std::atomic<bool> telemetryPaused{false};
extern SemaphoreHandle_t wifiHttpMutex;
String MY_PUBKEY_HEX;

// The setup page is accessed directly over the temporary AP.
const IPAddress webConfigIP(192, 168, 4, 1);
const IPAddress webConfigMask(255, 255, 255, 0);
WebServer webServer(webConfigIP, 80);

// AP būsenos ir automatinio išjungimo kintamieji (10 min. limitas)
static bool apActive = false;
static unsigned long lastApActivityTime = 0;

inline bool isWebConfigAPActive() {
  return apActive;
}

inline bool stationConflictsWithSetupAP() {
  if (!WiFi.AP.started() || WiFi.status() != WL_CONNECTED) return false;
  const uint32_t apMask = webConfigMask;
  const uint32_t sharedMask = apMask & static_cast<uint32_t>(WiFi.subnetMask());
  const uint32_t apIP = webConfigIP;
  return (static_cast<uint32_t>(WiFi.localIP()) & sharedMask) == (apIP & sharedMask) ||
         (static_cast<uint32_t>(WiFi.gatewayIP()) & apMask) == (apIP & apMask);
}

inline bool activeStationConnected() {
  return !telemetryPaused && activeConfig.wifiSsid.length() && WiFi.status() == WL_CONNECTED &&
         WiFi.SSID() == activeConfig.wifiSsid && !stationConflictsWithSetupAP();
}

inline const BotConfig& editableConfig() {
  return recoveringLegacyConfig ? legacyRecoveryConfig : savedConfig;
}

inline void stopWebConfigAP() {
  if (!apActive) return;
  apActive = false;
  webServer.stop();
  WiFi.softAPdisconnect(true);
  WiFi.enableAP(false);
  WiFi.mode(WIFI_STA);
  Serial.println("[WebPortal] Wi-Fi AP ('MeshPuller') pilnai išjungtas ir paslėptas.");
}

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
    if (!isxdigit(static_cast<unsigned char>(byteStr[0])) ||
        !isxdigit(static_cast<unsigned char>(byteStr[1]))) return false;
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
inline bool validBotId(const String& botId) {
  if (botId.length() > 16) return false;
  for (size_t i = 0; i < botId.length(); ++i) {
    char c = botId[i];
    if (!(c >= 'A' && c <= 'Z') && !(c >= 'a' && c <= 'z') &&
        !(c >= '0' && c <= '9') && c != '-' && c != '_') return false;
  }
  return true; // Empty means the device has not been provisioned.
}

inline bool decodeSeed(const String& hex, uint8_t (&seed)[SEED_SIZE]) {
  size_t len = 0;
  return strlen(hex.c_str()) == hex.length() &&
         hexStringToBytes(hex.c_str(), seed, sizeof(seed), len) && len == sizeof(seed);
}

inline bool validConfig(const BotConfig& cfg, bool allowMissingSeed = false) {
  uint8_t seed[SEED_SIZE];
  return validBotId(cfg.botId) && cfg.wifiSsid.length() <= 32 && cfg.wifiPassword.length() <= 64 &&
         (cfg.pathBits == 8 || cfg.pathBits == 16 || cfg.pathBits == 24) &&
         ((allowMissingSeed && !cfg.seed.length()) || decodeSeed(cfg.seed, seed));
}

inline void writeConfigFields(JsonDocument& doc, const BotConfig& cfg) {
  doc["WIFI_SSID"] = cfg.wifiSsid;
  doc["BOT_ID"] = cfg.botId;
  doc["REPEATER_REPLY_TIMEOUT_MS"] = cfg.replyTimeout;
  doc["REPEATER_REMOTE_REPLY_TIMEOUT_MS"] = cfg.remoteTimeout;
  doc["INTER_REQUEST_DELAY_MS"] = cfg.interDelay;
  doc["REPEATER_COOLDOWN_MS"] = cfg.cooldown;
  doc["TELEMETRY_REQUEST_INTERVAL_MS"] = cfg.interval;
  doc["PATH_HASH_BITS"] = cfg.pathBits;
  doc["ENABLE_SCOPE"] = cfg.scopeEnabled;
  doc["SCOPE_NAME"] = cfg.scope;
}

inline bool readConfigFields(JsonObjectConst obj, BotConfig& cfg, bool required) {
  if (obj.isNull()) return false;
  auto read = [obj, required](const char* key, auto& value) {
    using T = typename std::decay<decltype(value)>::type;
    if (!required && !obj.containsKey(key)) return true;
    if (!obj[key].is<T>()) return false;
    value = obj[key].as<T>();
    return true;
  };
  return read("WIFI_SSID", cfg.wifiSsid) && read("BOT_ID", cfg.botId) &&
         read("REPEATER_REPLY_TIMEOUT_MS", cfg.replyTimeout) &&
         read("REPEATER_REMOTE_REPLY_TIMEOUT_MS", cfg.remoteTimeout) &&
         read("INTER_REQUEST_DELAY_MS", cfg.interDelay) && read("REPEATER_COOLDOWN_MS", cfg.cooldown) &&
         read("TELEMETRY_REQUEST_INTERVAL_MS", cfg.interval) && read("PATH_HASH_BITS", cfg.pathBits) &&
         read("ENABLE_SCOPE", cfg.scopeEnabled) && read("SCOPE_NAME", cfg.scope);
}

// One NVS value keeps settings and identity together across a failed or interrupted save.
constexpr char configKey[] = "provision_v1";
constexpr size_t configMaxBytes = 3072; // Includes terminator; below NVS's 4000-byte limit.

inline bool saveConfigToNVS(BotConfig& candidate) {
  if (!candidate.seed.length()) {
    if (!isWebConfigAPActive()) return false;
    uint8_t seed[SEED_SIZE];
    esp_fill_random(seed, sizeof(seed));
    candidate.seed = bytesToHexString(seed, sizeof(seed));
  }
  if (!validConfig(candidate)) return false;
  JsonDocument doc;
  writeConfigFields(doc, candidate);
  doc["WIFI_PASSWORD"] = candidate.wifiPassword;
  doc["MY_SEED_HEX"] = candidate.seed;
  size_t size = measureJson(doc);
  if (doc.overflowed() || size >= configMaxBytes) return false;
  String json;
  if (serializeJson(doc, json) != size || json.length() != size) return false;

  Preferences prefs;
  if (!prefs.begin("meshbot", false) || prefs.putString(configKey, json) != size) {
    telemetryPaused = true;
    return false;
  }
  savedConfig = candidate;
  recoveringLegacyConfig = false;
  return true;
}

inline bool initConfigNVS() {
  BotConfig candidate;
  nvs_handle_t handle;
  esp_err_t err = nvs_open("meshbot", NVS_READONLY, &handle);
  if (err == ESP_ERR_NVS_NOT_FOUND) return saveConfigToNVS(candidate);
  if (err != ESP_OK) return false;

  size_t len = 0;
  err = nvs_get_str(handle, configKey, nullptr, &len);
  if (err == ESP_OK && len > 0 && len <= configMaxBytes) {
    char json[configMaxBytes];
    err = nvs_get_str(handle, configKey, json, &len);
    nvs_close(handle);
    JsonDocument doc;
    if (err != ESP_OK || deserializeJson(doc, json, len - 1) ||
        !readConfigFields(doc.as<JsonObjectConst>(), candidate, true) ||
        !doc["WIFI_PASSWORD"].is<const char*>() || !doc["MY_SEED_HEX"].is<const char*>()) return false;
    candidate.wifiPassword = doc["WIFI_PASSWORD"].as<String>();
    candidate.seed = doc["MY_SEED_HEX"].as<String>();
    if (!validConfig(candidate)) return false;
    savedConfig = candidate;
    return true;
  }
  if (err != ESP_ERR_NVS_NOT_FOUND) {
    nvs_close(handle);
    return false;
  }

  // An unreadable legacy seed is not a missing seed; never silently rotate it.
  size_t legacySeedLen = 0;
  err = nvs_get_str(handle, "seed_hex", nullptr, &legacySeedLen);
  if (err == ESP_OK) {
    char legacySeed[2 * SEED_SIZE + 3]; // Also accepts the historical optional 0x prefix.
    if (legacySeedLen <= sizeof(legacySeed)) {
      err = nvs_get_str(handle, "seed_hex", legacySeed, &legacySeedLen);
      if (err == ESP_OK) candidate.seed = legacySeed;
    }
    if (err != ESP_OK || candidate.seed.length() + 1 != legacySeedLen) {
      nvs_close(handle);
      return false;
    }
  } else if (err != ESP_ERR_NVS_NOT_FOUND) {
    nvs_close(handle);
    return false;
  }
  nvs_close(handle);

  Preferences prefs;
  if (!prefs.begin("meshbot", true)) return false;
  candidate.wifiSsid = prefs.getString("wifi_ssid", DEFAULT_WIFI_SSID);
  candidate.wifiPassword = prefs.getString("wifi_pass", DEFAULT_WIFI_PASSWORD);
  candidate.botId = prefs.getString("bot_id", DEFAULT_BOT_ID);
  candidate.replyTimeout = prefs.getULong("rep_timeout", DEFAULT_REPEATER_REPLY_TIMEOUT_MS);
  candidate.remoteTimeout = prefs.getULong("rem_timeout", DEFAULT_REPEATER_REMOTE_REPLY_TIMEOUT_MS);
  candidate.interDelay = prefs.getULong("inter_delay", DEFAULT_INTER_REQUEST_DELAY_MS);
  candidate.cooldown = prefs.getULong("rep_cooldown", DEFAULT_REPEATER_COOLDOWN_MS);
  candidate.interval = prefs.getULong("telem_intvl", DEFAULT_TELEMETRY_REQUEST_INTERVAL_MS);
  candidate.pathBits = prefs.getUChar("path_bits", DEFAULT_PATH_HASH_BITS);
  candidate.scopeEnabled = prefs.getBool("en_scope", DEFAULT_ENABLE_SCOPE);
  candidate.scope = prefs.getString("scope_name", DEFAULT_SCOPE_NAME);
  prefs.end();
  if (saveConfigToNVS(candidate)) return true;
  if (validConfig(candidate, true)) {
    legacyRecoveryConfig = candidate;
    recoveringLegacyConfig = true;
  }
  return false;
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
      overflow-wrap: anywhere;
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
      overflow-wrap: anywhere;
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
      gap: 10px;
      width: 100%;
      padding: 10px 14px;
      background: transparent;
      border: 0;
      border-bottom: 1px solid rgba(255,255,255,0.04);
      font: inherit;
      text-align: left;
      cursor: pointer;
      transition: background 0.15s;
    }
    .net-item:last-child { border-bottom: none; }
    .net-item:focus-visible { outline: 2px solid var(--cyan); outline-offset: -2px; }
    .net-item:hover {
      background: rgba(56, 189, 248, 0.08);
    }
    .net-item.selected {
      background: rgba(56, 189, 248, 0.15);
      box-shadow: inset 3px 0 var(--cyan);
    }
    .net-name {
      font-weight: 600;
      color: #fff;
      overflow-wrap: anywhere;
    }
    .net-meta {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.78rem;
      color: var(--text-muted);
    }
    .scan-message { padding: 14px; text-align: center; color: var(--text-muted); overflow-wrap: anywhere; }
    .scan-message.error { color: #f87171; }

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
    .field-help, .field-error {
      margin-top: 6px;
      font-size: 0.8rem;
      overflow-wrap: anywhere;
    }
    .field-help { color: var(--text-muted); }
    .field-error { color: #f87171; }
    .input-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }
    .input-wrap > input { padding-right: 42px; }
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
      position: relative;
      display: flex;
      align-items: center;
      gap: 12px;
      cursor: pointer;
      margin-top: 6px;
    }
    .switch {
      position: relative;
      flex: 0 0 44px;
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
    .switch-wrap input[type="checkbox"] {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      opacity: 0;
    }
    #SCOPE_NAME:focus-visible, .switch-wrap input:focus-visible + .switch {
      outline: 2px solid var(--cyan);
      outline-offset: 2px;
    }

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
      overflow-wrap: anywhere;
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
      max-width: calc(100% - 48px);
      overflow-wrap: anywhere;
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
        <label id="scanLabel">Aptikti Wi-Fi tinklai <span class="label-hint">(pasirinkite tinklą, kad užpildytumėte SSID)</span></label>
        <div class="network-list" id="networkList" role="group" aria-labelledby="scanLabel"></div>
        <template id="networkRow">
          <button type="button" class="net-item" aria-pressed="false">
            <span class="net-name"></span>
            <span class="net-meta">
              <span class="net-auth"></span>
              <span class="net-rssi" style="font-weight:700;"></span>
              <span class="signal-bars" style="height:14px;" aria-hidden="true">
                <span class="bar b1"></span>
                <span class="bar b2"></span>
                <span class="bar b3"></span>
                <span class="bar b4"></span>
              </span>
            </span>
          </button>
        </template>
      </div>

      <!-- Prisijungimo forma -->
      <div class="form-grid">
        <div class="form-group">
          <label for="WIFI_SSID">Wi-Fi Tinklo Pavadinimas (SSID)</label>
          <input type="text" id="WIFI_SSID" placeholder="Pvz. 2.4GHz arba ManoTinklas">
        </div>
        <div class="form-group">
          <label for="WIFI_PASSWORD">Wi-Fi Slaptažodis <span class="label-hint">(jei SSID nekeičiate, tuščias laukas palieka išsaugotą slaptažodį; pakeitus SSID, jis reiškia atvirą tinklą)</span></label>
          <div class="input-wrap">
            <input type="password" id="WIFI_PASSWORD" autocomplete="new-password" spellcheck="false" placeholder="Įveskite tinklo slaptažodį">
            <button type="button" class="toggle-pass" aria-label="Rodyti Wi-Fi slaptažodį" aria-pressed="false" onclick="togglePass('WIFI_PASSWORD', this)">👁️</button>
          </div>
        </div>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-top:8px;">
        <div style="font-size:0.78rem; color:var(--text-dim);">
          „Prisijungti dabar“ iškart išbando ryšį. Jei pavyksta, išsaugomi tik Wi-Fi nustatymai.
        </div>
        <button type="button" class="btn btn-success" id="btnConnect" onclick="connectWifi()">
          <span>⚡ Prisijungti dabar</span>
        </button>
      </div>

      <div id="connectAlert" class="alert" role="status"></div>
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
          <input type="text" id="BOT_ID" maxlength="16">
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
        <div class="status-chips">
          <button type="button" class="btn btn-secondary btn-sm" id="btnPreviewKeys" onclick="generateKeys(true)">
            <span>Peržiūrėti sėklos raktus</span>
          </button>
          <button type="button" class="btn btn-secondary btn-sm" id="btnGenKeys" onclick="generateKeys(false)">
            <span>🎲 Generuoti visus raktus</span>
          </button>
        </div>
      </div>
      <div class="form-group">
        <label for="MY_SEED_HEX">MY_SEED_HEX <span class="label-hint">(64 hex simboliai; palikite tuščią, jei tapatybės nekeičiate)</span></label>
        <div class="input-wrap">
          <input type="password" id="MY_SEED_HEX" class="mono" autocomplete="new-password" spellcheck="false">
          <button type="button" class="toggle-pass" aria-label="Rodyti tapatybės sėklą" aria-pressed="false" onclick="togglePass('MY_SEED_HEX', this)">👁️</button>
        </div>
      </div>
      <div class="form-group">
        <label for="MY_PUBKEY_HEX">MY_PUBKEY_HEX <span class="label-hint">(išsaugotos arba peržiūrimos Ed25519 tapatybės viešasis raktas)</span></label>
        <input type="text" id="MY_PUBKEY_HEX" class="mono" readonly>
      </div>
      <div class="form-group">
        <label for="MY_PRVKEY_HEX">MY_PRVKEY_HEX <span class="label-hint">(privataus rakto peržiūra; išsaugotas raktas nerodomas)</span></label>
        <div class="input-wrap">
          <input type="password" id="MY_PRVKEY_HEX" class="mono" readonly autocomplete="off" spellcheck="false">
          <button type="button" class="toggle-pass" aria-label="Rodyti privatų raktą" aria-pressed="false" onclick="togglePass('MY_PRVKEY_HEX', this)">👁️</button>
        </div>
      </div>
    </div>

    <!-- KORTELĖ 4: MESHCORE MARŠRUTIZAVIMAS IR SCOPE -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span>🌐 MeshCore maršrutizavimas</span>
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
          <label for="SCOPE_NAME">Numatytasis FLOOD regionas</label>
          <input type="text" id="SCOPE_NAME" placeholder="kun" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="scopeNameHelp">
          <p id="scopeNameError" class="field-error" hidden></p>
          <p id="scopeNameHelp" class="field-help">„kun“ ir „#kun“ reiškia tą patį regioną. Didžiosios ir mažosios raidės skiriamos. Maršruto kartotuvai turi leisti persiųsti FLOOD paketus tame pačiame regione.</p>
        </div>
        <div class="form-group full">
          <label class="switch-wrap">
            <input type="checkbox" id="ENABLE_SCOPE" aria-describedby="scopeModeHelp">
            <span class="switch" aria-hidden="true"></span>
            <span style="font-size:0.85rem; color:#fff; font-weight:600;">Naudoti numatytąjį FLOOD regioną</span>
          </label>
          <p id="scopeModeHelp" class="field-help">Išjungus naudojamas įprastas FLOOD. Žinomi DIRECT maršrutai, įskaitant maršrutus be tarpinių kartotuvų, nesikeičia.</p>
        </div>
      </div>
    </div>

    <!-- VEIKSMŲ MYGTUKŲ JUOSTA -->
    <p>Išsaugoti arba atstatyti nustatymai įsigalios tik perkrovus ESP32.</p>
    <div class="actions-bar">
      <button type="button" class="btn btn-danger btn-sm" id="btnReset" onclick="resetDefaults()">
        <span>↺ Atstatyti numatytuosius (iš config.h)</span>
      </button>
      <button type="button" class="btn btn-secondary" id="btnRestart" onclick="restartDevice()">
        <span>⚡ Perkrauti ESP32</span>
      </button>
      <button type="button" class="btn btn-secondary" id="btnSave" onclick="saveSettings(false)" disabled>
        <span>💾 Išsaugoti nustatymus</span>
      </button>
      <button type="button" class="btn" id="btnSaveRestart" onclick="saveSettings(true)" disabled>
        <span>🔄 Išsaugoti ir perkrauti</span>
      </button>
    </div>
    <div id="settingsAlert" class="alert" role="status"></div>
  </div>

  <div id="toast" class="toast" role="status"></div>

  <script>
    const numericSettings = [
      'TELEMETRY_REQUEST_INTERVAL_MS', 'REPEATER_REPLY_TIMEOUT_MS',
      'REPEATER_REMOTE_REPLY_TIMEOUT_MS', 'INTER_REQUEST_DELAY_MS',
      'REPEATER_COOLDOWN_MS', 'PATH_HASH_BITS'
    ];
    const reconnectInstructions = 'Po perkrovimo vėl prisijunkite prie atviro konfigūravimo Wi-Fi tinklo ir atverkite http://192.168.4.1/.';
    let savedPublicKey = '';
    let settingsReady = false;
    let portalWritePending = false;
    let restartAccepted = false;
    let statusPoll;

    function setSettingsReady(ready) {
      settingsReady = ready;
      updateActions();
    }

    function updateActions() {
      for (const id of ['btnSave', 'btnSaveRestart']) {
        document.getElementById(id).disabled = !settingsReady || portalWritePending;
      }
      for (const id of ['btnConnect', 'btnReset', 'btnRestart']) {
        document.getElementById(id).disabled = portalWritePending;
      }
    }

    function beginPortalWrite() {
      portalWritePending = true;
      updateActions();
      return () => {
        portalWritePending = false;
        updateActions();
      };
    }

    async function requestJson(path, fallback, payload) {
      const options = {mode: 'same-origin', cache: 'no-store'};
      if (payload !== undefined) {
        options.method = 'POST';
        options.headers = {'Content-Type': 'application/json'};
        options.body = JSON.stringify(payload);
      }
      const res = await fetch(path, options);
      const contentType = res.headers.get('Content-Type') || '';
      const data = contentType.includes('application/json') ? await res.json().catch(() => null) : null;
      if (!res.ok || data?.success === false || (payload !== undefined && data?.success !== true)) {
        const message = [data?.message, data?.error].find(text => typeof text === 'string' && text);
        const detail = message || (!res.ok && contentType.startsWith('text/plain') ? await res.text() : '');
        const err = new Error(detail || (res.ok ? fallback : `${fallback} (HTTP ${res.status})`));
        if (res.status === 400 && data?.success === false) err.field = data.field;
        throw err;
      }
      if (!data) throw new Error(fallback);
      return data;
    }

    function startBusy(btn, message) {
      const label = btn.firstElementChild;
      const previous = label.textContent;
      const spinner = document.createElement('span');
      spinner.className = 'spinner';
      spinner.setAttribute('aria-hidden', 'true');
      btn.prepend(spinner);
      label.textContent = message;
      btn.disabled = true;
      return () => {
        spinner.remove();
        label.textContent = previous;
        btn.disabled = restartAccepted;
      };
    }

    let toastTimer;
    function showToast(msg, type='info') {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.style.borderLeftColor = (type === 'success') ? 'var(--green)' : (type === 'error' ? 'var(--red)' : 'var(--cyan)');
      t.classList.add('show');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
    }

    function togglePass(id, btn) {
      const el = document.getElementById(id);
      const show = el.type === 'password';
      el.type = show ? 'text' : 'password';
      btn.textContent = show ? '🔒' : '👁️';
      btn.setAttribute('aria-pressed', String(show));
    }

    function clearSecret(id) {
      const el = document.getElementById(id);
      el.value = '';
      if (el.type !== 'password') togglePass(id, el.nextElementSibling);
    }

    function clearKeyPreview() {
      document.getElementById('MY_PUBKEY_HEX').value = savedPublicKey;
      clearSecret('MY_PRVKEY_HEX');
    }

    async function generateKeys(preview) {
      const seedField = document.getElementById('MY_SEED_HEX');
      const original = seedField.value;
      const seed = original.trim();
      if (preview && !/^[a-f0-9]{64}$/i.test(seed)) {
        showToast('Įveskite 64 šešioliktainius sėklos simbolius.', 'error');
        seedField.focus();
        return;
      }
      const btn = document.getElementById(preview ? 'btnPreviewKeys' : 'btnGenKeys');
      const other = document.getElementById(preview ? 'btnGenKeys' : 'btnPreviewKeys');
      const finish = startBusy(btn, 'Generuojama...');
      other.disabled = true;
      try {
        const data = await requestJson('/api/generate_keys', 'Nepavyko sugeneruoti raktų', preview ? { seed } : {});
        if (restartAccepted || seedField.value !== original) return;
        clearSecret('MY_SEED_HEX');
        clearSecret('MY_PRVKEY_HEX');
        seedField.value = data.seed;
        document.getElementById('MY_PUBKEY_HEX').value = data.pubkey;
        document.getElementById('MY_PRVKEY_HEX').value = data.prvkey;
        showToast('Raktai paruošti peržiūrai. Norėdami pakeisti tapatybę, išsaugokite nustatymus.', 'success');
      } catch (err) {
        if (!restartAccepted) showToast(`Raktų generavimo klaida: ${err.message}`, 'error');
      } finally {
        finish();
        other.disabled = restartAccepted;
      }
    }

    async function fetchStatus() {
      try {
        const data = await requestJson('/api/status', 'Nepavyko gauti Wi-Fi būsenos');
        if (restartAccepted) return;
        
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
        if (restartAccepted) return;
        document.getElementById('chipWifi').className = 'chip';
        document.getElementById('chipWifiText').textContent = 'Būsena nepasiekiama';
        document.getElementById('chipStaIp').textContent = '—';
        document.getElementById('stWifiName').textContent = 'Nepavyko gauti Wi-Fi būsenos';
        document.getElementById('stWifiDetails').textContent = err.message;
        document.getElementById('stSignalBars').className = 'signal-bars s0';
        document.getElementById('stSignalDbm').textContent = '— dBm';
        document.getElementById('stSignalQuality').textContent = 'Būsena nežinoma';
      }
    }

    function scanMessage(list, message, type) {
      const note = document.createElement('div');
      note.className = type === 'error' ? 'scan-message error' : 'scan-message';
      note.setAttribute('role', 'status');
      list.replaceChildren(note);
      note.textContent = message;
    }

    async function scanWifi() {
      const btn = document.getElementById('btnScan');
      const list = document.getElementById('networkList');
      const wrap = document.getElementById('scanWrap');
      const finish = startBusy(btn, 'Skenuojama...');

      try {
        wrap.style.display = 'block';
        scanMessage(list, 'Ieškoma aplinkinių Wi-Fi tinklų...');

        const { networks } = await requestJson('/api/scan', 'Nepavyko nuskenuoti tinklų');
        if (restartAccepted) return;
        if (!Array.isArray(networks)) throw new Error('Negautas Wi-Fi tinklų sąrašas.');

        if (networks.length === 0) {
          scanMessage(list, 'Tinklų neaptikta. Pabandykite dar kartą.');
          return;
        }

        const template = document.getElementById('networkRow');
        const rows = document.createDocumentFragment();
        networks.forEach(net => {
          const item = template.content.firstElementChild.cloneNode(true);
          item.querySelector('.net-name').textContent = net.ssid || '(Paslėptas SSID)';
          item.querySelector('.net-auth').textContent = net.auth;
          item.querySelector('.net-rssi').textContent = `${net.rssi} dBm`;
          const signal = net.rssi >= -60 ? 's4' : net.rssi >= -70 ? 's3' : net.rssi >= -82 ? 's2' : 's1';
          item.querySelector('.signal-bars').classList.add(signal);
          item.onclick = () => {
            const previous = list.querySelector('.selected');
            if (previous) {
              previous.classList.remove('selected');
              previous.setAttribute('aria-pressed', 'false');
            }
            item.classList.add('selected');
            item.setAttribute('aria-pressed', 'true');
            const ssidField = document.getElementById('WIFI_SSID');
            if (ssidField.value !== net.ssid) clearSecret('WIFI_PASSWORD');
            ssidField.value = net.ssid;
            if (net.ssid) {
              document.getElementById('WIFI_PASSWORD').focus();
              showToast(`Pasirinktas tinklas: ${net.ssid}`);
            } else {
              ssidField.focus();
              showToast('Paslėptas tinklas: įveskite jo SSID.');
            }
          };
          rows.appendChild(item);
        });
        list.replaceChildren(rows);
        showToast(`Aptikta tinklų: ${networks.length}`);
      } catch (err) {
        if (!restartAccepted) scanMessage(list, `Klaida skenuojant: ${err.message}`, 'error');
      } finally {
        finish();
      }
    }

    async function connectWifi() {
      if (portalWritePending) return;
      const btn = document.getElementById('btnConnect');
      const alert = document.getElementById('connectAlert');
      const ssid = document.getElementById('WIFI_SSID').value;
      const password = document.getElementById('WIFI_PASSWORD').value;

      if (!ssid) {
        alert.className = 'alert error';
        alert.textContent = 'Prašome nurodyti Wi-Fi tinklo SSID!';
        document.getElementById('WIFI_SSID').focus();
        return;
      }

      const unlock = beginPortalWrite();
      const finish = startBusy(btn, 'Jungiamasi...');
      alert.className = 'alert info';
      alert.textContent = `Jungiamasi prie '${ssid}'... Tai gali užtrukti iki 15 sekundžių.`;

      try {
        const data = await requestJson('/api/connect', 'Nepavyko prisijungti prie nurodyto Wi-Fi tinklo.', { ssid, password });
        if (document.getElementById('WIFI_PASSWORD').value === password) clearSecret('WIFI_PASSWORD');
        alert.className = 'alert success';
        alert.textContent = `✅ ${data.message || 'Prisijungta sėkmingai!'} IP: ${data.ip} | Signalas: ${data.rssi} dBm`;
        fetchStatus();
      } catch (err) {
        alert.className = 'alert error';
        alert.textContent = `Prisijungimo klaida: ${err.message}`;
      } finally {
        finish();
        unlock();
      }
    }

    function showSettingsStatus(message, type = '') {
      const alert = document.getElementById('settingsAlert');
      alert.className = type ? `alert ${type}` : 'alert';
      alert.textContent = message;
    }

    function setScopeError(message = '') {
      const field = document.getElementById('SCOPE_NAME');
      const error = document.getElementById('scopeNameError');
      error.textContent = message;
      error.hidden = !message;
      if (message) {
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', 'scopeNameError scopeNameHelp');
      } else {
        field.removeAttribute('aria-invalid');
        field.setAttribute('aria-describedby', 'scopeNameHelp');
      }
    }

    async function loadSettings(savedMessage = '') {
      setScopeError();
      setSettingsReady(false);
      showSettingsStatus(savedMessage || 'Kraunami išsaugoti nustatymai...', 'info');
      try {
        const data = await requestJson('/api/settings', 'Nepavyko įkelti nustatymų');
        if (restartAccepted) return;

        for (const id of ['WIFI_SSID', 'BOT_ID', 'SCOPE_NAME', ...numericSettings]) {
          document.getElementById(id).value = data[id] ?? '';
        }
        savedPublicKey = data.MY_PUBKEY_HEX ?? '';
        if (!document.getElementById('MY_PRVKEY_HEX').value) clearKeyPreview();
        document.getElementById('ENABLE_SCOPE').checked = !!data.ENABLE_SCOPE;
        showSettingsStatus(savedMessage, savedMessage ? 'success' : '');
        setSettingsReady(true);
      } catch (err) {
        if (restartAccepted) return;
        showSettingsStatus(`${savedMessage ? `${savedMessage} ` : ''}Nustatymų įkėlimo klaida: ${err.message}. Įkelkite puslapį iš naujo.`, 'error');
      }
    }

    async function saveSettings(restartAfter) {
      if (!settingsReady || portalWritePending) return;
      const seedField = document.getElementById('MY_SEED_HEX');
      const scopeField = document.getElementById('SCOPE_NAME');
      const scopeToggle = document.getElementById('ENABLE_SCOPE');
      const seed = seedField.value.trim();
      if (seed && !/^[a-f0-9]{64}$/i.test(seed)) {
        showToast('Įveskite 64 šešioliktainius sėklos simbolius arba palikite lauką tuščią.', 'error');
        seedField.focus();
        return;
      }
      const payload = {
        WIFI_SSID: document.getElementById('WIFI_SSID').value,
        WIFI_PASSWORD: document.getElementById('WIFI_PASSWORD').value,
        BOT_ID: document.getElementById('BOT_ID').value.trim(),
        ENABLE_SCOPE: scopeToggle.checked,
        SCOPE_NAME: scopeField.value.trim()
      };
      if (seed) payload.MY_SEED_HEX = seed;
      for (const id of numericSettings) {
        const field = document.getElementById(id);
        const value = Number(field.value);
        if (field.value === '' || !Number.isSafeInteger(value) || value < 0) {
          showToast(`${id}: įveskite neneigiamą sveikąjį skaičių.`, 'error');
          field.focus();
          return;
        }
        payload[id] = value;
      }

      setScopeError();
      const unlock = beginPortalWrite();
      let restarting = false;
      try {
        await requestJson('/api/settings', 'Nepavyko išsaugoti nustatymų', payload);
        clearSecret('WIFI_PASSWORD');
        clearSecret('MY_SEED_HEX');
        clearKeyPreview();
        restarting = restartAfter && confirm(`Nustatymai išsaugoti. Perkrauti ESP32 dabar?\n\n${reconnectInstructions}`) && await requestRestart();
        if (restarting) return;
        await loadSettings('Nustatymai išsaugoti. Norėdami juos pritaikyti, spauskite „Perkrauti ESP32“.');
      } catch (err) {
        if (err.field === 'SCOPE_NAME') {
          if (scopeToggle.checked === payload.ENABLE_SCOPE && scopeField.value.trim() === payload.SCOPE_NAME) {
            setScopeError(err.message);
            scopeField.focus();
          } else {
            showToast('Nustatymai neišsaugoti. Išsaugokite dar kartą.', 'error');
          }
        } else {
          showToast(`Nustatymų išsaugojimo klaida: ${err.message}`, 'error');
        }
      } finally {
        if (!restarting) unlock();
      }
    }

    async function resetDefaults() {
      if (portalWritePending) return;
      if (!confirm('Pakeisti visus išsaugotus nustatymus numatytaisiais iš config.h? Jie įsigalios perkrovus ESP32.')) return;
      const unlock = beginPortalWrite();
      const btn = document.getElementById('btnReset');
      const finish = startBusy(btn, 'Atstatoma...');
      try {
        await requestJson('/api/reset', 'Nepavyko atstatyti nustatymų', {});
        clearSecret('WIFI_PASSWORD');
        clearSecret('MY_SEED_HEX');
        clearKeyPreview();
        await loadSettings('Numatytieji nustatymai išsaugoti. Norėdami juos pritaikyti, spauskite „Perkrauti ESP32“.');
      } catch (err) {
        showToast(`Nustatymų atstatymo klaida: ${err.message}`, 'error');
      } finally {
        finish();
        unlock();
      }
    }

    async function restartDevice() {
      if (portalWritePending || !confirm(`Perkrauti ESP32 ir pritaikyti išsaugotus nustatymus?\n\n${reconnectInstructions}`)) return;
      const unlock = beginPortalWrite();
      if (!await requestRestart()) unlock();
    }

    async function requestRestart() {
      const finish = startBusy(document.getElementById('btnRestart'), 'Perkraunama...');
      try {
        await requestJson('/api/restart', 'Nepavyko perkrauti ESP32 modulio', {});
        restartAccepted = true;
        clearInterval(statusPoll);
        setSettingsReady(false);
        for (const id of ['btnScan', 'btnGenKeys', 'btnPreviewKeys']) document.getElementById(id).disabled = true;
        showSettingsStatus(`ESP32 persikrauna. ${reconnectInstructions}`, 'info');
        return true;
      } catch (err) {
        showToast(`ESP32 perkrovimo klaida: ${err.message}`, 'error');
        return false;
      } finally {
        finish();
        updateActions();
      }
    }

    // Inicializacija
    window.onload = () => {
      fetchStatus();
      loadSettings();
      statusPoll = setInterval(fetchStatus, 4000);

      document.getElementById('MY_SEED_HEX').addEventListener('input', clearKeyPreview);
      document.getElementById('SCOPE_NAME').addEventListener('input', () => setScopeError());
      document.getElementById('ENABLE_SCOPE').addEventListener('change', event => {
        if (!event.target.checked) setScopeError();
      });
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
  doc["wifi_ssid"] = isConn ? WiFi.SSID() : activeConfig.wifiSsid;
  doc["wifi_ip"] = ip;
  doc["wifi_rssi"] = rssi;
  doc["ap_active"] = apActive;
  doc["ap_ssid"] = DEFAULT_AP_SSID;
  doc["ap_ip"] = apActive ? WiFi.softAPIP().toString() : "0.0.0.0";
  doc["ap_clients"] = apActive ? WiFi.softAPgetStationNum() : 0;
  doc["bot_id"] = activeConfig.botId;
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

  if (!doc["ssid"].is<const char*>() || !doc["password"].is<const char*>()) {
    webServer.send(400, "application/json", "{\"success\":false,\"error\":\"Neteisingi Wi-Fi nustatymai\"}");
    return;
  }
  BotConfig candidate = editableConfig();
  String newSsid = doc["ssid"].as<String>();
  String newPass = doc["password"].as<String>();
  if (!newSsid.length() || newSsid.length() > 32 || newPass.length() > 64) {
    webServer.send(400, "application/json", "{\"success\":false,\"error\":\"Neteisingas SSID arba Wi-Fi slaptažodžio ilgis\"}");
    return;
  }
  if (!newPass.length() && newSsid == candidate.wifiSsid) newPass = candidate.wifiPassword;
  candidate.wifiSsid = newSsid;
  candidate.wifiPassword = newPass;

  if (wifiHttpMutex && xSemaphoreTake(wifiHttpMutex, pdMS_TO_TICKS(1000)) != pdTRUE) {
    webServer.send(503, "application/json", "{\"success\":false,\"error\":\"Wi-Fi užimtas. Pabandykite dar kartą.\"}");
    return;
  }

  Serial.printf("\n[WebPortal] Bandymas jungtis prie Wi-Fi '%s'...\n", newSsid.c_str());

  if (!WiFi.STA.disconnect(false, 1000)) {
    telemetryPaused = true;
    if (wifiHttpMutex) xSemaphoreGive(wifiHttpMutex);
    webServer.send(503, "application/json", "{\"success\":false,\"error\":\"Nepavyko atjungti Wi-Fi. Perkraukite įrenginį.\"}");
    return;
  }
  const bool started = WiFi.STA.connect(newSsid.c_str(), newPass.c_str());

  unsigned long start = millis();
  bool connected = false;
  while (started && millis() - start < 14000) {
    if (WiFi.status() == WL_CONNECTED && WiFi.SSID() == newSsid) {
      connected = true;
      break;
    }
    delay(200);
  }

  const bool conflict = connected && stationConflictsWithSetupAP();
  const bool saved = connected && !conflict && saveConfigToNVS(candidate);
  const bool disconnected = saved || WiFi.STA.disconnect(false, 1000);
  if (!disconnected) telemetryPaused = true;
  if (saved && (newSsid != activeConfig.wifiSsid || newPass != activeConfig.wifiPassword)) telemetryPaused = true;
  if (wifiHttpMutex) xSemaphoreGive(wifiHttpMutex);

  if (saved) {
    doc.clear();
    doc["success"] = true;
    doc["message"] = "Prisijungta ir išsaugota. Užbaikite konfigūravimą ir perkraukite.";
    doc["ip"] = WiFi.localIP().toString();
    doc["rssi"] = WiFi.RSSI();
    String out;
    serializeJson(doc, out);
    webServer.send(200, "application/json", out);
  } else if (!disconnected) {
    webServer.send(503, "application/json", "{\"success\":false,\"error\":\"Nepavyko atjungti bandomojo Wi-Fi. Perkraukite įrenginį.\"}");
  } else if (conflict) {
    webServer.send(409, "application/json", "{\"success\":false,\"message\":\"Wi-Fi tinklas persidengia su 192.168.4.0/24. Išsaugokite nustatymus, perkraukite ir leiskite išsijungti konfigūravimo tinklui.\"}");
  } else if (connected) {
    webServer.send(500, "application/json", "{\"success\":false,\"message\":\"Nepavyko išsaugoti Wi-Fi nustatymų\"}");
  } else {
    Serial.printf("[WebPortal] Nepavyko prisijungti prie '%s'\n", newSsid.c_str());
    webServer.send(400, "application/json", "{\"success\":false,\"error\":\"Nepavyko prisijungti. Patikrinkite slaptažodį arba tinklo atstumą.\"}");
  }
}

inline void handleApiGetSettings() {
#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
#else
  DynamicJsonDocument doc(1536);
#endif
  const BotConfig& cfg = editableConfig();
  writeConfigFields(doc, cfg);
  uint8_t seed[SEED_SIZE], pub[PUB_KEY_SIZE], priv[PRV_KEY_SIZE];
  if (decodeSeed(cfg.seed, seed)) {
    MeshCrypto::generateKeypair(pub, priv, seed);
    doc["MY_PUBKEY_HEX"] = bytesToHexString(pub, sizeof(pub));
  }

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

  BotConfig candidate = editableConfig();
  JsonObjectConst obj = doc.as<JsonObjectConst>();
  if (!readConfigFields(obj, candidate, false) ||
      (obj.containsKey("WIFI_PASSWORD") && !obj["WIFI_PASSWORD"].is<const char*>()) ||
      (obj.containsKey("MY_SEED_HEX") && !obj["MY_SEED_HEX"].is<const char*>())) {
    webServer.send(400, "application/json", "{\"success\":false,\"message\":\"Neteisingi nustatymų duomenys\"}");
    return;
  }
  if (candidate.scopeEnabled && SCOPE_CODE_1 == 0 && SCOPE_CODE_2 == 0) {
    const JsonString scope = obj["SCOPE_NAME"].as<JsonString>();
    // Detect truncation, e.g. if Arduino String conversion stopped at a NUL byte.
    if ((scope && scope.size() != candidate.scope.length()) ||
        !MeshCorePacket::validPublicScopeName(candidate.scope.c_str(), candidate.scope.length())) {
      webServer.send(400, "application/json",
                     "{\"success\":false,\"field\":\"SCOPE_NAME\",\"message\":\"Įveskite viešo regiono pavadinimą, pvz., kun arba #kun. Pavadinimas turi būti be tarpų ir ne ilgesnis nei 30 baitų (neskaitant pradinio #).\"}");
      return;
    }
  }
  String password = obj["WIFI_PASSWORD"] | "";
  if (password.length() || candidate.wifiSsid != editableConfig().wifiSsid) candidate.wifiPassword = password;
  String seed = obj["MY_SEED_HEX"] | "";
  if (seed.length()) candidate.seed = seed;
  if (!validConfig(candidate, true)) {
    webServer.send(400, "application/json", "{\"success\":false,\"message\":\"Patikrinkite Wi-Fi, boto ID, kelio hash ir Ed25519 sėklos nustatymus\"}");
    return;
  }
  if (!saveConfigToNVS(candidate)) {
    webServer.send(500, "application/json", "{\"success\":false,\"message\":\"Nepavyko išsaugoti nustatymų\"}");
    return;
  }
  webServer.send(200, "application/json", "{\"success\":true,\"message\":\"Nustatymai išsaugoti. Jie įsigalios po perkrovimo.\"}");
}

inline void handleApiResetSettings() {
  BotConfig defaults;
  if (!saveConfigToNVS(defaults)) {
    webServer.send(500, "application/json", "{\"success\":false,\"message\":\"Nepavyko atstatyti nustatymų\"}");
    return;
  }
  webServer.send(200, "application/json", "{\"success\":true,\"message\":\"Numatytieji nustatymai įsigalios po perkrovimo.\"}");
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
        } else {
          webServer.send(400, "application/json", "{\"success\":false,\"message\":\"Neteisinga Ed25519 sėkla\"}");
          return;
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
  webServer.send(404, "application/json", "{\"success\":false,\"error\":\"Unknown setup endpoint\"}");
}

// ================================================================================
// 🚀 INICIALIZAVIMAS IR APTARNAVIMAS
// ================================================================================

inline void initWebConfigPortal() {
  // 1. Konfigūruojame Wi-Fi AP + STA režimą
  WiFi.persistent(false);
  if (!WiFi.mode(WIFI_AP_STA) || !WiFi.softAPConfig(webConfigIP, webConfigIP, webConfigMask)) {
    Serial.println("[WebPortal] Nepavyko paruošti prieigos taško.");
    WiFi.mode(WIFI_STA);
    return;
  }

  if (!WiFi.softAP(DEFAULT_AP_SSID, nullptr, 1, 0, 1, false, WIFI_AUTH_OPEN)) {
    Serial.println("[WebPortal] Nepavyko paleisti konfigūravimo prieigos taško.");
    WiFi.mode(WIFI_STA);
    return;
  }
  lastApActivityTime = millis();
  apActive = true;

  Serial.printf("[WebPortal] Wi-Fi Prieigos Taškas (AP) paleistas: SSID: '%s', IP: %s (veiks 10 min.)\n",
                DEFAULT_AP_SSID, WiFi.softAPIP().toString().c_str());

  // Užregistruojame maršrutus
  // Some core versions listen on all IPs. Keep the destination and browser write checks.
  const char* headers[] = {"Origin", "Content-Type"};
  webServer.collectHeaders(headers, 2);
  webServer.addMiddleware([](WebServer& server, Middleware::Callback next) {
    if (server.client().localIP() != webConfigIP || !WiFi.AP.started() || stationConflictsWithSetupAP()) {
      server.send(403, "text/plain", "Configuration is only available on the setup Wi-Fi.");
      return false;
    }
    if ((server.hostHeader() != "192.168.4.1" && server.hostHeader() != "192.168.4.1:80") ||
        (server.method() != HTTP_GET &&
         (server.method() != HTTP_POST || server.header("Origin") != "http://192.168.4.1" ||
          server.header("Content-Type") != "application/json"))) {
      server.send(403, "application/json", "{\"success\":false,\"error\":\"Open http://192.168.4.1/ to change settings.\"}");
      return false;
    }
    server.sendHeader("Cache-Control", "no-store");
    return next();
  });
  webServer.on("/", HTTP_GET, handleRoot);
  webServer.on("/api/status", HTTP_GET, handleApiStatus);
  webServer.on("/api/scan", HTTP_GET, handleApiScan);
  webServer.on("/api/connect", HTTP_POST, handleApiConnect);
  webServer.on("/api/settings", HTTP_GET, handleApiGetSettings);
  webServer.on("/api/settings", HTTP_POST, handleApiSaveSettings);
  webServer.on("/api/reset", HTTP_POST, handleApiResetSettings);
  webServer.on("/api/restart", HTTP_POST, handleApiRestart);
  webServer.on("/api/generate_keys", HTTP_POST, handleApiGenerateKeys);

  webServer.onNotFound(handleNotFound);

  webServer.begin();
  Serial.println("[WebPortal] HTTP Serveris paruoštas darbui (Portas 80).");
}

inline void handleWebConfigPortal() {
  if (!apActive) return;
  if (stationConflictsWithSetupAP()) WiFi.disconnect(false);

  // Jei klientas buvo pasijungęs, bet atsijungė ir 10 min. nėra jokio veiksmo - išjungiame AP
  int clients = WiFi.softAPgetStationNum();
  if (clients == 0 && (millis() - lastApActivityTime >= 10 * 60 * 1000UL)) {
    stopWebConfigAP();
    return;
  }

  webServer.handleClient();
}

// Paleidus ESP32 paleidžiamas AP ir laukiama 1 minutę, ar kas nors prisijungs prie AP 'MeshPuller'
inline bool waitForApClients(unsigned long waitMs = AP_WAIT_WINDOW_MS) {
  if (!apActive) return false;
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
