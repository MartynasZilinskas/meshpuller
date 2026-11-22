#pragma once

#include <Arduino.h>

// Public firmware profile. Provision device credentials after flashing.
constexpr char DEFAULT_WIFI_SSID[] = "";
constexpr char DEFAULT_WIFI_PASSWORD[] = "";
constexpr char DEFAULT_BOT_ID[] = "";
constexpr char DEFAULT_MY_SEED_HEX[] = "";
constexpr char API_BASE_URL[] = "https://telemetry.ite.lt";
constexpr char API_AUTH_KEY[] = "";
constexpr char API_ENDPOINT_RAW[] = "/api/raw.php";
constexpr char REPEATERS_URL[] = "https://telemetry.ite.lt/api/repeater";

constexpr char DEFAULT_AP_SSID[] = "MeshPuller";
constexpr unsigned long AP_WAIT_WINDOW_MS = 60000;
constexpr char TIMEZONE_STRING[] = "EET-2EEST,M3.5.0/3,M10.5.0/4";

constexpr unsigned long DEFAULT_TELEMETRY_REQUEST_INTERVAL_MS = 60000;
constexpr unsigned long DEFAULT_REPEATER_REPLY_TIMEOUT_MS = 12000;
constexpr unsigned long DEFAULT_REPEATER_REMOTE_REPLY_TIMEOUT_MS = 30000;
constexpr unsigned long DEFAULT_INTER_REQUEST_DELAY_MS = 10000;
constexpr unsigned long DEFAULT_REPEATER_COOLDOWN_MS = 10000;
constexpr size_t MAX_MONITORED_REPEATERS = 16;
constexpr uint8_t DEFAULT_PATH_HASH_BITS = 16;
constexpr bool DEFAULT_ENABLE_SCOPE = false;
constexpr char DEFAULT_SCOPE_NAME[] = "";
constexpr uint16_t SCOPE_CODE_1 = 0;
constexpr uint16_t SCOPE_CODE_2 = 0;

// Seeed Studio XIAO ESP32-S3 + Wio-SX1262, EU radio profile.
constexpr int PIN_LORA_NSS = 41;
constexpr int PIN_LORA_RESET = 42;
constexpr int PIN_LORA_BUSY = 40;
constexpr int PIN_LORA_DIO1 = 39;
constexpr int PIN_LORA_RXEN = 38;
constexpr float LORA_FREQ = 869.618f;
constexpr float LORA_BW = 62.5f;
constexpr uint8_t LORA_SF = 8;
constexpr uint8_t LORA_CR = 8;
constexpr uint8_t LORA_SYNC_WORD = 0x12;
constexpr int8_t LORA_TX_POWER = 22;
constexpr uint16_t LORA_PREAMBLE_LEN = 16;

// Reply types used by the sketch's receive loop.
enum WaitTarget {
  WAIT_ANY,
  WAIT_LOGIN,
  WAIT_STATUS,
  WAIT_TELEMETRY,
};

struct RepeaterTarget {
  String name;
  String public_id;
  String password;
  String path;
  bool isDirect;
  bool skipLogin;
  bool reqStatus;
  bool reqTelemetry;
};
