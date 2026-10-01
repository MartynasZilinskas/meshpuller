#ifndef CONFIG_H
#define CONFIG_H

#include <Arduino.h>

// ================================================================================
// ⚙️ PAGRINDINIAI NUSTATYMAI (CONFIG)
// ================================================================================

// 1. Wi-Fi nustatymai
const char *WIFI_SSID = "2.4GHz";
const char *WIFI_PASSWORD = "37810200438";

// const char *WIFI_SSID = "Nerijus’s iPhone";
// const char *WIFI_PASSWORD = "kodas123";

// 2. API URL (kur siųsti surinktus telemetrijos duomenis HTTP POST užklausa)
const char *API_URL = "https://telemetry.ite.lt";
const char *API_AUTH_KEY =
    ""; // Pvz.: "Bearer TAVO_TOKENAS" (jei nereikia - palikti tuščią "")

// 3. Stebimų kartotuvų sąrašo URL (iš kur prieš kiekvieną ciklą parsiunčiamas
// kartotuvų JSON sąrašas)
const char *REPEATERS_URL = "https://telemetry.ite.lt/r.json";

// Maksimalus stebimų kartotuvų skaičius atmintyje:
const size_t MAX_MONITORED_REPEATERS = 16;

// Stebimo kartotuvo struktūra
struct RepeaterTarget {
  String name;        // Pavadinimas / prefiksas (pvz. "b9dd" arba "1bbd")
  String public_id;   // 32 baitų (64 hex simbolių) kartotuvo viešasis raktas
  String password;    // Prisijungimo slaptažodis (pvz. "hello")
  bool isDirect;      // true = tiesioginis (0 hops), false = per kitus kartotuvus (FLOOD)
  bool reqStatus;     // true = siųsti būsenos užklausą (REQ_TYPE_GET_STATUS)
  bool reqTelemetry;  // true = siųsti telemetrijos užklausą (REQ_TYPE_GET_TELEMETRY_DATA)
};

// Atsakymo laukimo tikslo tipas (apsauga nuo ankstyvo lango užsidarymo)
enum WaitTarget {
  WAIT_ANY = 0,
  WAIT_LOGIN = 1,
  WAIT_STATUS = 2,
  WAIT_TELEMETRY = 3
};

// Atsakymo laukimo laikas (milisekundėmis) po kiekvienos užklausos (10 sekundžių):
const unsigned long REPEATER_REPLY_TIMEOUT_MS = 10000;

// Pauzė (milisekundėmis) tarp atskirų užklausų laukimo (pvz. po status prieš telemetriją):
const unsigned long INTER_REQUEST_DELAY_MS = 10000;

// Pauzė (milisekundėmis) tarp kartotuvų apklausos (po API išsiuntimo prieš sekantį kartotuvą):
const unsigned long REPEATER_COOLDOWN_MS = 10000;

// ESP32 Mazgo Ed25519 Raktai (Mūsų tapatybė tinkle)
const char *MY_SEED_HEX =
    "4a9232cb075ea937e5ff59e14eb89b595acfc82e9ccf4d7347372bff56393e09";
const char *MY_PUBKEY_HEX =
    "0bbd84b028bdf755edd8f30c6f67839c3f81846c9f0079f377ea32a1e93410f3";
const char *MY_PRVKEY_HEX =
    "4a9232cb075ea937e5ff59e14eb89b595acfc82e9ccf4d7347372bff56393e09"
    "0bbd84b028bdf755edd8f30c6f67839c3f81846c9f0079f377ea32a1e93410f3";

// Užklausų ciklo periodiškumas (milisekundėmis): kas 5 minutes (300000 ms)
const unsigned long TELEMETRY_REQUEST_INTERVAL_MS = 60000 * 60;

// 4. Telemetrijos Duomenų Filtrai (Įjungti/Išjungti duomenų laukus iš siunčiamo
// JSON)
struct TelemetrySettings {
  bool includeNodeInfo = true;      // Mazgo ID
  bool includeSignalMetrics = true; // RSSI, SNR, Triukšmo lygis
  bool includeBattery = true;       // Baterijos įtampa (mV ir V)
  bool includeStatusMetrics = true; // Kartotuvo būsenos statistika (eterio laikas, paketai ir t.t.)
  bool includeTemperature = true;   // MCU temperatūra
  bool includeUptime = true;        // Veikimo laikas (uptime)
  bool includeRawPayload = true;    // Neapdoroti gauti baitai diagnostikai
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
