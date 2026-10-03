#ifndef CONFIG_H
#define CONFIG_H

#include <Arduino.h>

// ================================================================================
// ⚙️ NUMATYTIEJI NUSTATYMAI (DEFAULTS IŠ CONFIG.H)
// Šios reikšmės naudojamos kaip numatytosios (default), jei vidinėje NVS atmintyje
// vartotojas dar neišsaugojo savo nustatymų per Web sąsają arba po nustatymų atstatymo.
// ================================================================================

// 1. Wi-Fi numatytieji nustatymai
const char *DEFAULT_WIFI_SSID = "2.4GHz";
const char *DEFAULT_WIFI_PASSWORD = "37810200438";

// 1b. Wi-Fi Access Point (AP) prieigos taško numatytieji nustatymai
const char *DEFAULT_AP_SSID = "MeshPuller";
const char *DEFAULT_AP_PASSWORD = ""; // Tuščias = atviras AP patogiam prisijungimui (arba pvz. "12345678")
const unsigned long AP_WAIT_WINDOW_MS = 60 * 1000UL; // Paleidus laukiama 1 min., ar kas nors prisijungs prie AP konfigūravimui

// 1c. Laiko juosta (Lietuvos laikas: EET/EEST, automatinis žiemos/vasaros laikas)
const char *TIMEZONE_STRING = "EET-2EEST,M3.5.0/3,M10.5.0/4";

// 2. API Endpoint
const char *API_BASE_URL = "https://telemetry.ite.lt";
const char *API_ENDPOINT_RAW =
    "/api/raw.php"; // Visas pagautas LoRa RAW srautas (RX ir TX su raktais)
const char *API_AUTH_KEY =
    ""; // Pvz.: "Bearer TAVO_TOKENAS" (jei nereikia - palikti "")

// 2b. ESP32 Boto identifikatorius (numatytasis)
const char *DEFAULT_BOT_ID = "KLP";

// 3. Stebimo kartotuvo gavimo URL (iš kur prieš kiekvieną ciklą parsiunčiamas
// 1 sekantis atnaujinamas kartotuvas pagal eilę)
const char *REPEATERS_URL = "https://telemetry.ite.lt/api/repeater";

// Maksimalus stebimų kartotuvų skaičius atmintyje:
const size_t MAX_MONITORED_REPEATERS = 16;

// Stebimo kartotuvo struktūra
struct RepeaterTarget {
  String name;      // Pavadinimas / prefiksas (pvz. "b9dd" arba "1bbd")
  String public_id; // 32 baitų (64 hex simbolių) kartotuvo viešasis raktas
  String password;  // Prisijungimo slaptažodis (pvz. "hello")
  bool isDirect;    // true = tiesioginis (0 hops), false = per kitus kartotuvus
                    // (FLOOD)
  bool reqStatus;   // true = siųsti būsenos užklausą (REQ_TYPE_GET_STATUS)
  bool reqTelemetry; // true = siųsti telemetrijos užklausą
                     // (REQ_TYPE_GET_TELEMETRY_DATA)
};

// Atsakymo laukimo tikslo tipas (apsauga nuo ankstyvo lango užsidarymo)
enum WaitTarget {
  WAIT_ANY = 0,
  WAIT_LOGIN = 1,
  WAIT_STATUS = 2,
  WAIT_TELEMETRY = 3
};

// Numatytieji atsakymo laukimo laikai (milisekundėmis):
const unsigned long DEFAULT_REPEATER_REPLY_TIMEOUT_MS = 12000;
const unsigned long DEFAULT_REPEATER_REMOTE_REPLY_TIMEOUT_MS = 30000;
const unsigned long DEFAULT_INTER_REQUEST_DELAY_MS = 10000;
const unsigned long DEFAULT_REPEATER_COOLDOWN_MS = 10000;

// Numatytieji ESP32 Mazgo Ed25519 Raktai (Mūsų tapatybė tinkle)
const char *DEFAULT_MY_SEED_HEX =
    "4a9232cb075ea937e5ff59e14eb89b595acfc82e9ccf4d7347372bff56393e09";
const char *DEFAULT_MY_PUBKEY_HEX =
    "0bbd84b028bdf755edd8f30c6f67839c3f81846c9f0079f377ea32a1e93410f3";
const char *DEFAULT_MY_PRVKEY_HEX =
    "4a9232cb075ea937e5ff59e14eb89b595acfc82e9ccf4d7347372bff56393e09"
    "0bbd84b028bdf755edd8f30c6f67839c3f81846c9f0079f377ea32a1e93410f3";

// Numatytasis užklausų ciklo periodiškumas (milisekundėmis): kas 1 minutę (60000 ms)
const unsigned long DEFAULT_TELEMETRY_REQUEST_INTERVAL_MS = 60000;

// ================================================================================
// 🌐 MESHCORE MARŠRUTIZAVIMO IR KELIO NUSTATYMAI (ROUTING, PATH & SCOPE)
// ================================================================================
const uint8_t DEFAULT_PATH_HASH_BITS = 16; // 16 bitų (2 baitai)
const bool DEFAULT_ENABLE_SCOPE = false;
const char *DEFAULT_SCOPE_NAME = "lt";
const uint16_t SCOPE_CODE_1 = 0x0000;
const uint16_t SCOPE_CODE_2 = 0x0000;

// ================================================================================
// 🚀 AKTYVŪS NUSTATYMAI (KINTAMIEJI, UŽKRAUNAMI IŠ NVS ARBA DEFAULT_*)
// ================================================================================
extern String WIFI_SSID;
extern String WIFI_PASSWORD;
extern String BOT_ID;
extern unsigned long REPEATER_REPLY_TIMEOUT_MS;
extern unsigned long REPEATER_REMOTE_REPLY_TIMEOUT_MS;
extern unsigned long INTER_REQUEST_DELAY_MS;
extern unsigned long REPEATER_COOLDOWN_MS;
extern String MY_SEED_HEX;
extern String MY_PUBKEY_HEX;
extern String MY_PRVKEY_HEX;
extern unsigned long TELEMETRY_REQUEST_INTERVAL_MS;
extern uint8_t PATH_HASH_BITS;
extern bool ENABLE_SCOPE;
extern String SCOPE_NAME;

// 4. Telemetrijos Duomenų Filtrai (Įjungti/Išjungti duomenų laukus iš siunčiamo
// JSON)
struct TelemetrySettings {
  bool includeNodeInfo = true;      // Mazgo ID
  bool includeSignalMetrics = true; // RSSI, SNR, Triukšmo lygis
  bool includeBattery = true;       // Baterijos įtampa (mV ir V)
  bool includeStatusMetrics =
      true; // Kartotuvo būsenos statistika (eterio laikas, paketai ir t.t.)
  bool includeTemperature = true; // MCU temperatūra
  bool includeUptime = true;      // Veikimo laikas (uptime)
  bool includeRawPayload = true;  // Neapdoroti gauti baitai diagnostikai
};

extern TelemetrySettings settings; // Nustatymų objektas

// 5. LoRa Dažnis ir Radijo Parametrai
// DĖMESIO: Standartinis MeshCore ES/EU dažnis yra 869.618 MHz, BW 62.5 kHz, SF
// 8 arba 10! Jei jūsų kartotuvas sukonfigūruotas pagal nutylėjimą, naudokite
// 869.618 MHz. Jei patys nustatėte kitokį dažnį repeaterio nustatymuose -
// pakeiskite čia.
#define LORA_FREQ                                                              \
  869.618 // MHz (Oficialus MeshCore ES dažnis; pakeiskite į 868.0 jei
          // kartotuvas pakeistas)
#define LORA_BW 62.5 // kHz (MeshCore standartas 62.5 arba 250.0)
#define LORA_SF 8    // Spreading Factor (MeshCore standartas SF8 arba SF10)
#define LORA_CR 8    // Coding Rate 4/8 (MeshCore EU regionui)
#define LORA_SYNC_WORD 0x12  // SX1262 LoRa Sync Word (0x12 Private / MeshCore)
#define LORA_TX_POWER 22     // dBm (Seeed Wio-SX1262 max 22 dBm)
#define LORA_PREAMBLE_LEN 16 // MeshCore naudoja 16

// ================================================================================
// 🔌 APARATŪROS KONFIGŪRACIJA (Seeed Studio XIAO ESP32-S3 + Wio-SX1262)
// ================================================================================
#define PIN_LORA_NSS 41   // SPI CS
#define PIN_LORA_RESET 42 // Reset Pin
#define PIN_LORA_BUSY 40  // BUSY Pin
#define PIN_LORA_DIO1 39  // DIO1 Interrupt
#define PIN_LORA_RXEN 38  // Wio-SX1262 RX Enable / RF Switch control

#endif // CONFIG_H
