/*
  ================================================================================
  Seeed Studio XIAO ESP32-S3 + Wio-SX1262 MeshCore Telemetry Bot
  ================================================================================
  Platforma: Arduino IDE (ESP32 by Espressif plokščių paketas)
  Plokštė:   Seeed Studio XIAO ESP32S3
  Protokolas: MeshCore (https://docs.meshcore.io/)
  ================================================================================
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <RadioLib.h>
#include <ArduinoJson.h>
#include "config.h"
#include "MeshCrypto.h"

// ================================================================================
// 📡 MESHCORE PROTOKOLO KONSTANTOS
// ================================================================================

// MeshCore Maršrutizavimo Tipai (Header bits 0-1)
#define ROUTE_TYPE_TRANSPORT_FLOOD  0x00
#define ROUTE_TYPE_FLOOD            0x01
#define ROUTE_TYPE_DIRECT           0x02
#define ROUTE_TYPE_TRANSPORT_DIRECT 0x03

// MeshCore Krovinio Tipai (Header bits 2-5)
#define PAYLOAD_TYPE_REQ            0x00  // Užklausa
#define PAYLOAD_TYPE_RESPONSE       0x01  // Atsakas į užklausą
#define PAYLOAD_TYPE_TXT_MSG        0x02  // Tekstinis pranešimas
#define PAYLOAD_TYPE_ACK            0x03  // Patvirtinimas (ACK)
#define PAYLOAD_TYPE_ADVERT         0x04  // Mazgo reklama (Identity advert)
#define PAYLOAD_TYPE_ANON_REQ       0x07  // Anoniminė užklausa / Login
#define PAYLOAD_TYPE_PATH           0x08  // Grąžintas kelias
#define PAYLOAD_TYPE_CONTROL        0x0B  // Valdymo paketas

// Kartotuvo Užklausų Tipai (Request Types)
#define REQ_TYPE_GET_STATUS         0x01  // RepeaterStats gavimas (stats)
#define REQ_TYPE_KEEP_ALIVE         0x02  // Sesijos palaikymas
#define REQ_TYPE_GET_TELEMETRY_DATA 0x03  // CayenneLPP telemetrijos gavimas
#define REQ_TYPE_GET_ACCESS_LIST    0x05  // ACL sąrašas
#define REQ_TYPE_GET_NEIGHBOURS     0x06  // Kaimyniniai mazgai
#define REQ_TYPE_GET_OWNER_INFO     0x07  // Savininko informacija

// Anoniminių užklausų tipai
#define ANON_REQ_TYPE_REGIONS       0x01
#define ANON_REQ_TYPE_OWNER         0x02
#define ANON_REQ_TYPE_BASIC         0x03  // Laikrodis ir kartotuvo būsena

// Atsakų kodai
#define RESP_SERVER_LOGIN_OK        0x00  // Sėkmingas prisijungimas

// MeshCore RepeaterStats struktūra (iš kartotuvo atsakymo į REQ_TYPE_GET_STATUS)
#pragma pack(push, 1)
struct RepeaterStats {
  uint16_t batt_milli_volts;     // Baterijos įtampa mV
  uint16_t curr_tx_queue_len;    // Siuntimo eilės ilgis
  int16_t  noise_floor;          // Triukšmo lygis dBm
  int16_t  last_rssi;            // Paskutinis RSSI
  uint32_t n_packets_recv;       // Gautų paketų skaičius
  uint32_t n_packets_sent;       // Išsiųstų paketų skaičius
  uint32_t total_air_time_secs;  // Bendras eterio laikas (s)
  uint32_t total_up_time_secs;   // Veikimo laikas (uptime s)
  uint32_t n_sent_flood;         // Išsiųsta flood
  uint32_t n_sent_direct;        // Išsiųsta direct
  uint32_t n_recv_flood;         // Gauta flood
  uint32_t n_recv_direct;        // Gauta direct
  uint16_t err_events;           // Klaidų įvykiai
  int16_t  last_snr;             // SNR padaugintas iš 4
  uint16_t n_direct_dups;        // Direct dublikatai
  uint16_t n_flood_dups;         // Flood dublikatai
  uint32_t total_rx_air_time_secs;// Gautų paketų eterio laikas (s)
  uint32_t n_recv_errors;        // Priėmimo klaidos
};
#pragma pack(pop)

// ================================================================================
// 🔌 APARATŪROS IR BŪSENOS KINTAMIEJI
// ================================================================================

TelemetrySettings settings;

// SX1262 Modulio inicijavimas
SX1262 radio = new Module(PIN_LORA_NSS, PIN_LORA_DIO1, PIN_LORA_RESET, PIN_LORA_BUSY);

volatile bool receivedFlag = false;
void IRAM_ATTR setFlag(void) {
  receivedFlag = true;
}

unsigned long lastTelemetryRequest = 0;
bool sessionLoggedIn = false;

// ================================================================================
// 🔑 MESHCORE KRIPTOGRAFIJA IR RAKTAI
// ================================================================================
uint8_t myPublicKey[PUB_KEY_SIZE];
uint8_t myPrivateKey[PRV_KEY_SIZE];
uint8_t myNodeHash = 0xAA;

struct MonitoredNode {
  uint8_t hash;
  uint8_t pubKey[PUB_KEY_SIZE];
  uint8_t sharedSecret[PUB_KEY_SIZE];
  bool initialized;
};
MonitoredNode monitoredNodes[16];

// Dinamiškai parsiunčiamų kartotuvų masyvas (iš REPEATERS_URL prieš kiekvieną ciklą)
RepeaterTarget monitoredRepeaters[16];
size_t monitoredRepeatersCount = 0;

// Konsoliduotas telemetrijos dokumentas (sujungia visų užklausų atsakymus į 1 ataskaitą)
#if ARDUINOJSON_VERSION_MAJOR >= 7
JsonDocument currentRepDoc;
#else
StaticJsonDocument<1536> currentRepDoc;
#endif
bool currentRepHasData = false;

// Saugus pradinis laikas (apsauga nuo MeshCore replay-attack filtro):
// Kadangi į B9 kartotuvo atmintį ankstesnio paleidimo metu buvo įrašyta ~1790630500 žyma,
// bet kokia žyma, mažesnė už ją, kartotuve yra atmetama kaip replay-attack.
// Todėl pradedame nuo 1790700000UL ir sinchronizuojame su realiu NTP laiku.
static uint32_t lastTxTimestamp = 1790700000UL;

uint32_t getNextTimestamp() {
  time_t now = time(nullptr);
  uint32_t ts = 0;

  if (now >= 1700000000UL) {
    // Turime tikrą NTP laiką. Pridedame poslinkį, kad žyma viršytų 1790700000UL:
    ts = 1790700000UL + (uint32_t)(now - 1790620000UL);
  } else {
    // Jei NTP dar nespėjo sinchronizuoti:
    ts = 1790700000UL + (millis() / 1000);
  }

  if (ts <= lastTxTimestamp) {
    ts = lastTxTimestamp + 1;
  }
  lastTxTimestamp = ts;
  return ts;
}


// Funkcijų prototipai
void scanWiFiNetworks();
void connectToWiFi();
void initLoRa();
void generateSelfIdentity();
bool fetchRepeatersFromURL();
void initMonitoredNodeKeys();
void requestTelemetryFromAllRepeaters();
void requestTelemetryFromSingleRepeater(size_t repIdx);
bool waitForReply(unsigned long timeoutMs, uint8_t expectedSenderHash = 0, WaitTarget waitTarget = WAIT_ANY);
bool processIncomingPacket(uint8_t expectedSenderHash = 0, WaitTarget waitTarget = WAIT_ANY);
void sendMeshCoreLogin(size_t repIdx);
void sendMeshCorePath(size_t repIdx);
void sendMeshCoreStatusRequest(size_t repIdx);
void sendMeshCoreRaw(uint8_t header, uint8_t pathLen, const uint8_t* path, const uint8_t* payload, size_t payloadLen,
                     const char* targetName = nullptr, const char* targetPubKey = nullptr, const uint8_t* sharedSecret = nullptr);
bool hexStringToBytes(const char* hexStr, uint8_t* outBytes, size_t maxLen, size_t &outLen);
void printHexBuffer(const uint8_t* buf, size_t len);
void yieldDelay(unsigned long ms);

// Foninės API siuntimo eilės ir užduoties kintamieji (Core 0):
struct QueuedApiMessage {
  char* payload;
};

QueueHandle_t apiQueue = nullptr;
TaskHandle_t apiTaskHandle = nullptr;
SemaphoreHandle_t wifiHttpMutex = nullptr;
void apiWorkerTask(void* pvParameters);

#if ARDUINOJSON_VERSION_MAJOR >= 7
void sendToAPI(const JsonDocument& doc);
#else
void sendToAPI(const JsonDocument& doc);
#endif

// ================================================================================
// 🛠️ SETUP
// ================================================================================
void setup() {
  Serial.begin(115200);
  delay(1500);

  Serial.println("\n========================================================");
  Serial.println("  Seeed Studio XIAO ESP32-S3 MeshCore Telemetry Bot");
  Serial.println("========================================================");
  Serial.printf("[Info] Kartotuvų sąrašas bus parsiųstas prieš kiekvieną ciklą iš: %s\n", REPEATERS_URL);
  Serial.printf("[Info] Laukimo laikas po kiekvienos užklausos: %lu ms (%.1f s)\n",
                REPEATER_REPLY_TIMEOUT_MS, REPEATER_REPLY_TIMEOUT_MS / 1000.0f);
  Serial.printf("[Info] Tikrinimo ciklo intervalas: %lu min (%lu ms)\n",
                TELEMETRY_REQUEST_INTERVAL_MS / 60000, TELEMETRY_REQUEST_INTERVAL_MS);
  Serial.printf("[Info] API Bazinis URL: %s\n", API_BASE_URL);
  Serial.printf("  * RAW trafiko endpoint:  %s%s (visi įrašai siunčiami čia ir apdorojami serveryje)\n", API_BASE_URL, API_ENDPOINT_RAW);

  // 1. Generuojame unikalų ESP32 mazgo raktą (tik save – kartotuvų raktai generuojami po JSON parsisiuntimo)
  generateSelfIdentity();

  // 2. Wi-Fi ryšys ir NTP laikas
  connectToWiFi();
  delay(200);

  // 3. Inicijuojame foninę API siuntimo eilę ir užduotį (Core 0),
  // kad išsiuntus duomenis į API NIEKADA nebūtų laukiama serverio atsakymo,
  // o pagrindinis branduolys (Core 1) netrukdomas klausytųsi LoRa radijo eterio!
  apiQueue = xQueueCreate(32, sizeof(QueuedApiMessage));
  wifiHttpMutex = xSemaphoreCreateMutex();
  xTaskCreatePinnedToCore(
    apiWorkerTask,
    "apiWorkerTask",
    8192,
    NULL,
    1,
    &apiTaskHandle,
    0 // Paleidžiama ant Core 0 (Wi-Fi branduolys)
  );

  // 4. SX1262 LoRa radijas
  initLoRa();

  Serial.println("\n[Sistema] Paruošta darbui. Iškart inicijuojamas pirmasis duomenų gavimas...");
  requestTelemetryFromAllRepeaters();
  lastTelemetryRequest = millis(); // Nuo šio momento skaičiuojamas sekantis intervalas
  Serial.printf("[Sistema] Pirmas ciklas baigtas. Sekantis tikrinimas po %lu min.\n", TELEMETRY_REQUEST_INTERVAL_MS / 60000);
}

// ================================================================================
// 🔄 MAIN LOOP
// ================================================================================
void loop() {
  // 1. Klausomės eterio (jei kartotuvas atsiunčia paketą, pvz. ADVERT)
  if (receivedFlag) {
    receivedFlag = false;
    processIncomingPacket(0);
    radio.startReceive();
  }

  // 2. Periodiškas užklausų inicijavimas po nustatyto laiko intervalo
  if (millis() - lastTelemetryRequest >= TELEMETRY_REQUEST_INTERVAL_MS) {
    lastTelemetryRequest = millis();
    requestTelemetryFromAllRepeaters();
    Serial.printf("[Sistema] Ciklas baigtas. Sekantis tikrinimas po %lu min.\n", TELEMETRY_REQUEST_INTERVAL_MS / 60000);
  }

  // 3. Wi-Fi ryšio palaikymas (su 30 s pauze tarp bandymų, kad neperkrautų Wi-Fi posistemės)
  static unsigned long lastWiFiRetry = 0;
  if (WiFi.status() != WL_CONNECTED && (millis() - lastWiFiRetry >= 30000 || lastWiFiRetry == 0)) {
    lastWiFiRetry = millis();
    connectToWiFi();
  }

  delay(20);
}

// ================================================================================
// ⏳ NE-BLOKUOJANTI PAUZĖ (Pauzės metu radijas toliau aktyviai klausosi ir sugaudo
// visus eteryje atsiradusius ADVERT skelbimus, akimirksniu išsiųsdamas juos į API)
// ================================================================================
void yieldDelay(unsigned long ms) {
  unsigned long start = millis();
  while (millis() - start < ms) {
    if (receivedFlag) {
      receivedFlag = false;
      processIncomingPacket(0);
      radio.startReceive();
    }
    delay(15);
  }
}

// ================================================================================
// 📡 DUOMENŲ UŽKLAUSŲ CIKLAS (Nuoseklus darbas su kiekvienu kartotuvu paeiliui)
// ================================================================================
void requestTelemetryFromAllRepeaters() {
  Serial.println("\n========================================================");
  Serial.println("[Ciklas] Parsiunčiamas kartotuvų sąrašas iš interneto...");
  Serial.println("========================================================");

  if (!fetchRepeatersFromURL()) {
    Serial.println("[Ciklas] Kartotuvų sąrašas tuščias arba nepasiekiamas. Laukiama kito periodo.");
    radio.startReceive();
    return;
  }

  // Apskaičiuojame ECDH bendrus raktus su ką tik parsiunčiamais kartotuvais
  initMonitoredNodeKeys();

  Serial.printf("[Ciklas] Pradedama %u kartotuvų apklausa paeiliui...\n", (unsigned int)monitoredRepeatersCount);
  Serial.printf("[Ciklas] Nustatytas laukimo laikas po kiekvienos užklausos: %.1f s\n", REPEATER_REPLY_TIMEOUT_MS / 1000.0f);
  Serial.println("========================================================");

  for (size_t i = 0; i < monitoredRepeatersCount; i++) {
    if (!monitoredNodes[i].initialized) {
      Serial.printf("[Ciklas] Kartotuvas '%s' neinicializuotas (patikrinkite raktą). Praleidžiama.\n",
                    monitoredRepeaters[i].name.c_str());
      continue;
    }

    Serial.printf("\n>>> [%u/%u] PRADEDAMAS DARBAS SU: %s (Hash: 0x%02X | %s) <<<\n",
                  (unsigned int)(i + 1), (unsigned int)monitoredRepeatersCount,
                  monitoredRepeaters[i].name.c_str(), monitoredNodes[i].hash,
                  monitoredRepeaters[i].isDirect ? "Tiesioginis DIRECT" : "Nutolęs FLOOD");

    requestTelemetryFromSingleRepeater(i);

    if (i + 1 < monitoredRepeatersCount) {
      Serial.printf("\n[Pauzė] Baigtas darbas su '%s'. Pauzė %.1f s prieš kreipiantis į sekantį...\n",
                    monitoredRepeaters[i].name.c_str(), REPEATER_COOLDOWN_MS / 1000.0f);
      yieldDelay(REPEATER_COOLDOWN_MS);
    }
  }

  // Grįžtame į nuolatinio klausymosi režimą
  radio.startReceive();
  Serial.println("\n[Ciklas] Visi kartotuvai patikrinti!");
}

void requestTelemetryFromSingleRepeater(size_t repIdx) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t targetHash = monitoredNodes[repIdx].hash;

  currentRepDoc.clear();
  currentRepHasData = false;

  // Žingsnis 1: Užšifruotas prisijungimas (Login) su slaptažodžiu
  if (rep.password.length() > 0) {
    Serial.printf("[Užklausa 1/3] Siunčiamas užšifruotas Login į '%s' su slaptažodžiu '%s'...\n",
                  rep.name.c_str(), rep.password.c_str());
    sendMeshCoreLogin(repIdx);
    waitForReply(REPEATER_REPLY_TIMEOUT_MS, targetHash, WAIT_LOGIN);
    // Trumpa 2.0 s pauzė eteriui nurimti po login patvirtinimo
    yieldDelay(2000);
  } else {
    Serial.printf("[Užklausa 1/3] Slaptažodis kartotuvui '%s' nenurodytas. Prisijungimas praleidžiamas.\n", rep.name.c_str());
  }

  // Žingsnis 2: Būsenos ir statistikos užklausa (REQ_TYPE_GET_STATUS)
  if (rep.reqStatus) {
    Serial.printf("[Užklausa 2/3] Siunčiama būsenos užklausa (REQ_TYPE_GET_STATUS) į '%s' (Laukiama iki %.1f s)...\n",
                  rep.name.c_str(), REPEATER_REPLY_TIMEOUT_MS / 1000.0f);
    sendMeshCoreStatusRequest(repIdx);
    waitForReply(REPEATER_REPLY_TIMEOUT_MS, targetHash, WAIT_STATUS);
  } else {
    Serial.printf("[Užklausa 2/3] Būsenos užklausa ('status') išjungta kartotuvui '%s'. Praleidžiama.\n", rep.name.c_str());
  }

  // Tarpas tarp užklausų laukimo (10 sekundžių):
  if (rep.reqStatus && rep.reqTelemetry) {
    Serial.printf("[Pauzė] Laukimo tarpas tarp užklausų: %.1f s...\n", INTER_REQUEST_DELAY_MS / 1000.0f);
    yieldDelay(INTER_REQUEST_DELAY_MS);
  }

  // Žingsnis 3: Telemetrijos užklausa (REQ_TYPE_GET_TELEMETRY_DATA - Baterija ir Temp)
  if (rep.reqTelemetry) {
    Serial.printf("[Užklausa 3/3] Siunčiama telemetrijos užklausa (REQ_TYPE_GET_TELEMETRY_DATA) į '%s' (Laukiama iki %.1f s)...\n",
                  rep.name.c_str(), REPEATER_REPLY_TIMEOUT_MS / 1000.0f);
    sendMeshCoreTelemetryRequest(repIdx);
    waitForReply(REPEATER_REPLY_TIMEOUT_MS, targetHash, WAIT_TELEMETRY);
  } else {
    Serial.printf("[Užklausa 3/3] Telemetrijos užklausa ('telemetry') išjungta kartotuvui '%s'. Praleidžiama.\n", rep.name.c_str());
  }

  // Visi gauti ir išsiųsti paketai automatiškai perduodami į RAW endpoint (/api/raw) ir apdorojami serveryje!
  Serial.printf("\n[Ciklas] Kartotuvo '%s' užklausų etapas baigtas. Visi RAW paketai jau nukreipti į serverį.\n", rep.name.c_str());
}

// ================================================================================
// ⏳ AKTYVUS ATSAKYMO LAUKIMAS (LAUKIAMA IKI TIMEOUT ARBA IKI TIKSLINIO ATSAKYMO)
// ================================================================================
bool waitForReply(unsigned long timeoutMs, uint8_t expectedSenderHash, WaitTarget waitTarget) {
  unsigned long start = millis();
  int packetsCount = 0;
  bool gotTargetResponse = false;
  Serial.printf("[RX Laukimas] Klausomasi kartotuvo atsakymo (iki %.1f s)...\n", timeoutMs / 1000.0f);

  while (millis() - start < timeoutMs) {
    if (receivedFlag) {
      receivedFlag = false;
      packetsCount++;
      bool valid = processIncomingPacket(expectedSenderHash, waitTarget);
      radio.startReceive(); // Grįžtame į priėmimo režimą tolesniems paketams
      if (valid) {
        gotTargetResponse = true;
        // Trumpa 500 ms ne-blokuojanti pauzė leisti atvykti galimiems papildomiems paketams
        yieldDelay(500);
        break;
      }
    }
    delay(15);
  }

  if (gotTargetResponse) {
    Serial.println("[RX Laukimas] Atsakymas sėkmingai gautas ir apdorotas!");
  } else if (packetsCount == 0) {
    Serial.println("[RX Laukimas] Per skirtą laiką atsakymo paketų negauta.");
  } else {
    Serial.printf("[RX Laukimas] Lango pabaiga. Gauta eterio paketų: %d (atsakymo iš tikslinio kartotuvo negauta).\n", packetsCount);
  }

  return gotTargetResponse;
}

// ================================================================================
// 📡 LORA RADIJO INICIJAVIMAS (Seeed Wio-SX1262)
// ================================================================================
void initLoRa() {
  Serial.print("[LoRa] Inicijuojamas Wio-SX1262 modulis... ");

  // 1. Aktyvuojame RXEN stiprintuvo kontaktą (Pin 38 Seeed XIAO S3 Wio)
  pinMode(PIN_LORA_RXEN, OUTPUT);
  digitalWrite(PIN_LORA_RXEN, HIGH);

  // 2. SX1262 parametrai:
  // Dažnis, Pralaidumas (BW), SF, CR, SyncWord, Galia (dBm), Preamble, TCXO įtampa (1.8V), DC-DC reguliatorius
  int state = radio.begin(LORA_FREQ, LORA_BW, LORA_SF, LORA_CR, LORA_SYNC_WORD, LORA_TX_POWER, LORA_PREAMBLE_LEN, 1.8f, false);

  if (state == RADIOLIB_ERR_NONE) {
    Serial.println("SĖKMINGAI!");
    // Įjungiame RF switch valdymą per DIO2 ir RXEN (Būtina Seeed Wio-SX1262!)
    radio.setDio2AsRfSwitch(true);
    radio.setRfSwitchPins(PIN_LORA_RXEN, RADIOLIB_NC);
    radio.setRxBoostedGainMode(true);

    Serial.printf("[LoRa Parametrai] Dažnis: %.3f MHz | BW: %.1f kHz | SF: %d | CR: 4/%d | Galia: %d dBm | Preamble: %d\n",
                  LORA_FREQ, LORA_BW, LORA_SF, LORA_CR, LORA_TX_POWER, LORA_PREAMBLE_LEN);
  } else {
    Serial.printf("KLAIDA! Kodas: %d\n", state);
    Serial.println("[LoRa] Patikrinkite modulio kontaktus ir maitinimą!");
    while (true) { delay(1000); }
  }

  // Nustatome DIO1 pertrauktį paketų priėmimui
  radio.setDio1Action(setFlag);
  receivedFlag = false;
  radio.startReceive();
  Serial.println("[LoRa] Klausymosi režimas aktyvuotas.");
}

// ================================================================================
// 🔑 ESP32 IDENTITETO GENERAVIMAS IR KRIPTOGRAFINIS RYŠYS
// ================================================================================
// ================================================================================
// 🔑 ESP32 IDENTITETO GENERAVIMAS IR KRIPTOGRAFINIS RYŠYS
// ================================================================================
void generateSelfIdentity() {
  // Užkrauname vartotojo nurodytus ESP32 Ed25519 raktus iš config.h
  uint8_t seed[SEED_SIZE];
  size_t seedLen = 0;
  if (hexStringToBytes(MY_SEED_HEX, seed, SEED_SIZE, seedLen) && seedLen == SEED_SIZE) {
    MeshCrypto::generateKeypair(myPublicKey, myPrivateKey, seed);
  } else {
    uint8_t mac[6];
    WiFi.macAddress(mac);
    for (int i = 0; i < 32; i++) seed[i] = (uint8_t)(mac[i % 6] ^ (i * 0x5A + 0x33));
    MeshCrypto::generateKeypair(myPublicKey, myPrivateKey, seed);
  }
  myNodeHash = myPublicKey[0];

  Serial.println("\n[MeshCore Kriptografija - Mūsų Tapatybė]");
  Serial.printf("  * Mūsų ESP32 Mazgo Hash: 0x%02X\n", myNodeHash);
  Serial.print("  * Mūsų Ed25519 Viešasis: ");
  printHexBuffer(myPublicKey, PUB_KEY_SIZE);
  Serial.println();
  // Kartotuvų raktai bus sugeneruoti po fetchRepeatersFromURL() kiekvieną ciklą
}

// Parsiunčia kartotuvų sąrašą iš REPEATERS_URL prieš kiekvieną ciklą.
// Grąžina true jei sąraše yra bent vienas kartotuvas, false – jei nepasiekiamas arba tuščias.
bool fetchRepeatersFromURL() {
  monitoredRepeatersCount = 0;

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[FetchRepeaters] Wi-Fi neprisijungtas, negalima parsisiųsti sąrašo.");
    return false;
  }

  if (wifiHttpMutex != nullptr && xSemaphoreTake(wifiHttpMutex, pdMS_TO_TICKS(5000)) != pdTRUE) {
    Serial.println("[FetchRepeaters] Wi-Fi kanalas šiuo metu užimtas.");
    return false;
  }

  HTTPClient http;
  http.begin(REPEATERS_URL);
  http.setTimeout(8000);
  int code = http.GET();

  if (code != 200) {
    Serial.printf("[FetchRepeaters] HTTP klaida %d parsiunčiant '%s'\n", code, REPEATERS_URL);
    http.end();
    if (wifiHttpMutex != nullptr) xSemaphoreGive(wifiHttpMutex);
    return false;
  }

  String body = http.getString();
  http.end();
  if (wifiHttpMutex != nullptr) xSemaphoreGive(wifiHttpMutex);

  Serial.printf("[FetchRepeaters] Gautas atsakymas (%d baitai): %s\n", body.length(), body.substring(0, 120).c_str());

#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument doc;
#else
  StaticJsonDocument<2048> doc;
#endif

  DeserializationError err = deserializeJson(doc, body);
  if (err) {
    Serial.printf("[FetchRepeaters] JSON klaida: %s\n", err.c_str());
    return false;
  }

  // Palaikome abu formatus: tiesioginis masyvas [...] arba objektas {"repeaters": [...]}
  JsonArray arr;
  if (doc.is<JsonArray>()) {
    arr = doc.as<JsonArray>();
  } else if (doc["repeaters"].is<JsonArray>()) {
    arr = doc["repeaters"].as<JsonArray>();
  } else {
    Serial.println("[FetchRepeaters] Neatpažintas JSON formatas.");
    return false;
  }

  for (JsonObject obj : arr) {
    if (monitoredRepeatersCount >= MAX_MONITORED_REPEATERS) break;
    size_t idx = monitoredRepeatersCount;
    monitoredRepeaters[idx].name         = obj["name"].as<String>();
    monitoredRepeaters[idx].public_id    = obj["public_id"].as<String>();
    monitoredRepeaters[idx].password     = obj["password"].as<String>();
    monitoredRepeaters[idx].isDirect     = obj["isDirect"] | false;
    monitoredRepeaters[idx].reqStatus    = obj.containsKey("status") ? (bool)obj["status"] : true;
    monitoredRepeaters[idx].reqTelemetry = obj.containsKey("telemetry") ? (bool)obj["telemetry"] : true;
    monitoredRepeatersCount++;
  }

  if (monitoredRepeatersCount == 0) {
    Serial.println("[FetchRepeaters] Sąrašas tuščias ([] arba nėra įrašų).");
    return false;
  }

  Serial.printf("[FetchRepeaters] Sėkmingai įkelti %u kartotuvai:\n", (unsigned int)monitoredRepeatersCount);
  for (size_t i = 0; i < monitoredRepeatersCount; i++) {
    Serial.printf("  [%u] %s (%s) | pw: '%s' | status: %s | telemetry: %s\n",
                  (unsigned int)(i + 1), monitoredRepeaters[i].name.c_str(),
                  monitoredRepeaters[i].isDirect ? "DIRECT" : "FLOOD",
                  monitoredRepeaters[i].password.c_str(),
                  monitoredRepeaters[i].reqStatus ? "TAIP" : "NE",
                  monitoredRepeaters[i].reqTelemetry ? "TAIP" : "NE");
  }
  return true;
}

// Apskaičiuoja ECDH bendrus raktus su visais monitoredRepeaters[] kartotuvais.
// Kviečiama iškart po fetchRepeatersFromURL().
void initMonitoredNodeKeys() {
  Serial.println("\n[MeshCore Kriptografija - Kartotuvų Raktai]");
  for (size_t i = 0; i < monitoredRepeatersCount && i < MAX_MONITORED_REPEATERS; i++) {
    monitoredNodes[i].initialized = false;
    size_t keyLen = 0;
    if (hexStringToBytes(monitoredRepeaters[i].public_id.c_str(), monitoredNodes[i].pubKey, PUB_KEY_SIZE, keyLen)
        && keyLen == PUB_KEY_SIZE) {
      monitoredNodes[i].hash = monitoredNodes[i].pubKey[0];
      MeshCrypto::calcSharedSecret(monitoredNodes[i].sharedSecret, monitoredNodes[i].pubKey, myPrivateKey);
      monitoredNodes[i].initialized = true;

      Serial.printf("  * Kartotuvas [%u] '%s': Hash 0x%02X | %s\n",
                    (unsigned int)(i + 1), monitoredRepeaters[i].name.c_str(),
                    monitoredNodes[i].hash,
                    monitoredRepeaters[i].isDirect ? "DIRECT 0 hops" : "FLOOD multi-hop");
      Serial.print("    - Bendras šifravimo raktas: ");
      printHexBuffer(monitoredNodes[i].sharedSecret, 8);
      Serial.println("...");
    } else {
      Serial.printf("  [KLAIDA] Neteisingas viešasis raktas kartotuvui '%s'!\n",
                    monitoredRepeaters[i].name.c_str());
    }
  }
}

// ================================================================================
// 📤 MESHCORE PAKETŲ SIUNTIMAS
// ================================================================================

void sendMeshCoreRaw(uint8_t header, uint8_t pathLen, const uint8_t* path, const uint8_t* payload, size_t payloadLen,
                     const char* targetName, const char* targetPubKey, const uint8_t* sharedSecret) {
  uint8_t packet[256];
  size_t idx = 0;

  packet[idx++] = header;
  packet[idx++] = pathLen;

  if (path != nullptr && pathLen > 0) {
    memcpy(&packet[idx], path, pathLen);
    idx += pathLen;
  }

  if (payload != nullptr && payloadLen > 0) {
    memcpy(&packet[idx], payload, payloadLen);
    idx += payloadLen;
  }

  uint8_t pType = (header >> 2) & 0x0F;
  uint8_t rType = header & 0x03;

  Serial.printf("[LoRa TX] Siunčiamas paketas (%u baitai) | Header: 0x%02X (Tipas: %u, Maršrutas: %u) | RAW HEX: ",
                (unsigned int)idx, header, pType, rType);
  printHexBuffer(packet, idx);
  Serial.println();

  // Neapdoroto TX srauto siuntimas į /api/raw
  #if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument rawTxDoc;
  #else
  StaticJsonDocument<1024> rawTxDoc;
  #endif

  rawTxDoc["direction"] = "TX";
  time_t now = time(nullptr);
  rawTxDoc["timestamp"] = (now >= 1700000000UL) ? (uint32_t)now : lastTxTimestamp;
  rawTxDoc["length"] = idx;

  String rawHex = "";
  for (size_t i = 0; i < idx; i++) {
    char b[3]; snprintf(b, sizeof(b), "%02X", packet[i]);
    rawHex += b;
  }
  rawTxDoc["raw_hex"] = rawHex;

  if (targetName != nullptr && strlen(targetName) > 0) {
    rawTxDoc["target_name"] = targetName;
  }
  if (targetPubKey != nullptr && strlen(targetPubKey) > 0) {
    rawTxDoc["target_public_key"] = targetPubKey;
  }
  rawTxDoc["my_public_key"] = MY_PUBKEY_HEX;

  if (sharedSecret != nullptr) {
    char secretHex[65];
    for (int i = 0; i < 32; i++) snprintf(&secretHex[i * 2], 3, "%02x", sharedSecret[i]);
    rawTxDoc["shared_secret_hex"] = secretHex;
    rawTxDoc["is_encrypted"] = true;
  } else {
    rawTxDoc["is_encrypted"] = false;
  }

  sendToAPI(rawTxDoc);

  // Siuntimo metu atjungiame RX vėliavėlę, kad TXDone pertrauktis nesukeltų klaidingo RX
  receivedFlag = false;

  // 📡 Wio-SX1262 RF SWITCH VALDYMAS:
  // Siuntimo metu RXEN (Pin 38) PRIVALO būti LOW, kad siųstuvo PA galia patektų į anteną!
  digitalWrite(PIN_LORA_RXEN, LOW);

  int state = radio.transmit(packet, idx);

  // Po siuntimo vėl įjungiame RXEN (Pin 38 = HIGH), kad antena būtų nukreipta į imtuvą
  digitalWrite(PIN_LORA_RXEN, HIGH);

  // IŠVALOME TXDone pertraukties vėliavėlę po siuntimo ir grįžtame į klausymąsi
  receivedFlag = false;
  radio.startReceive();

  if (state == RADIOLIB_ERR_NONE) {
    Serial.println("[LoRa TX] Paketas sėkmingai ištransliuotas į eterį.");
  } else {
    Serial.printf("[LoRa TX KLAIDA] Nepavyko išsiųsti, klaidos kodas: %d\n", state);
  }
}

// 1. Siunčiama užšifruota prisijungimo (Login) užklausa su slaptažodžiu
void sendMeshCoreLogin(size_t repIdx) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t header = ((PAYLOAD_TYPE_ANON_REQ & 0x0F) << 2) | ROUTE_TYPE_FLOOD;

  uint8_t payload[128];
  size_t pLen = 0;

  // 1. Tikslinio kartotuvo hash
  payload[pLen++] = target;

  // 2. Mūsų 32 baitų Ed25519 viešasis raktas
  memcpy(&payload[pLen], myPublicKey, PUB_KEY_SIZE);
  pLen += PUB_KEY_SIZE;

  // 3. Paruošiame šifruojamus duomenis (Plaintext)
  uint8_t plain[64];
  size_t plainLen = 0;

  uint32_t ts = getNextTimestamp();
  memcpy(&plain[plainLen], &ts, 4);
  plainLen += 4;

  size_t pwdLen = rep.password.length();
  memcpy(&plain[plainLen], rep.password.c_str(), pwdLen + 1);
  plainLen += (pwdLen + 1);

  int encLen = MeshCrypto::encryptThenMAC(secret, &payload[pLen], plain, plainLen);
  pLen += encLen;

  sendMeshCoreRaw(header, 0, nullptr, payload, pLen, rep.name.c_str(), rep.public_id.c_str(), secret);
}

// 2. Siunčiamas tiesioginio kelio registravimas (PAYLOAD_TYPE_PATH) su 0 apynių
void sendMeshCorePath(size_t repIdx) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t header = ((PAYLOAD_TYPE_PATH & 0x0F) << 2) | ROUTE_TYPE_DIRECT; // 0x22

  uint8_t payload[64];
  size_t pLen = 0;

  payload[pLen++] = target;
  payload[pLen++] = myNodeHash;

  // Plaintext: [path_len 0x00][extra_type 0xFF][timestamp 4B]
  uint8_t plain[16] = {0};
  plain[0] = 0x00; // 0 apynių kelias
  plain[1] = 0xFF; // extra_type dummy
  uint32_t ts = getNextTimestamp();
  memcpy(&plain[2], &ts, 4);

  int encLen = MeshCrypto::encryptThenMAC(secret, &payload[pLen], plain, 6);
  pLen += encLen;

  sendMeshCoreRaw(header, 0, nullptr, payload, pLen, rep.name.c_str(), rep.public_id.c_str(), secret);
}

// 3. Siunčiama užšifruota būsenos ir statistikos užklausa (REQ_TYPE_GET_STATUS = 0x01)
void sendMeshCoreStatusRequest(size_t repIdx) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  // Užklausoms visada naudojame ROUTE_TYPE_FLOOD (patikimas pristatymas tiek tiesioginiams, tiek retransliuojamiems)
  uint8_t header = ((PAYLOAD_TYPE_REQ & 0x0F) << 2) | ROUTE_TYPE_FLOOD;

  uint8_t payload[64];
  size_t pLen = 0;

  payload[pLen++] = target;
  payload[pLen++] = myNodeHash;

  // Šifruojamas krovinys: oficialus MeshCore 13 baitų formatas:
  // [timestamp 4B][REQ_TYPE_GET_STATUS 0x01][reserved 4B (0)][random blob 4B]
  uint8_t plain[16] = {0};
  uint32_t ts = getNextTimestamp();
  memcpy(&plain[0], &ts, 4);
  plain[4] = REQ_TYPE_GET_STATUS;
  // plain[5..8] = 0 (rezervuota 'since' / query params)
  esp_fill_random(&plain[9], 4); // 4 atsitiktiniai baitai paketo unikalumui (apsauga nuo dubliavimo filtrų)

  int encLen = MeshCrypto::encryptThenMAC(secret, &payload[pLen], plain, 13);
  pLen += encLen;

  sendMeshCoreRaw(header, 0, nullptr, payload, pLen, rep.name.c_str(), rep.public_id.c_str(), secret);
}

// 4. Siunčiama užšifruota jutiklių telemetrijos užklausa (REQ_TYPE_GET_TELEMETRY_DATA = 0x03)
void sendMeshCoreTelemetryRequest(size_t repIdx) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t header = ((PAYLOAD_TYPE_REQ & 0x0F) << 2) | ROUTE_TYPE_FLOOD;

  uint8_t payload[64];
  size_t pLen = 0;

  payload[pLen++] = target;
  payload[pLen++] = myNodeHash;

  // Šifruojamas krovinys: oficialus MeshCore 13 baitų formatas:
  // [timestamp 4B][REQ_TYPE_GET_TELEMETRY_DATA 0x03][perm_mask 0x00][reserved 3B (0)][random blob 4B]
  uint8_t plain[16] = {0};
  uint32_t ts = getNextTimestamp();
  memcpy(&plain[0], &ts, 4);
  plain[4] = REQ_TYPE_GET_TELEMETRY_DATA;
  plain[5] = 0x00; // visos teisės (unmasked)
  // plain[6..8] = 0
  esp_fill_random(&plain[9], 4); // 4 atsitiktiniai baitai paketo unikalumui

  int encLen = MeshCrypto::encryptThenMAC(secret, &payload[pLen], plain, 13);
  pLen += encLen;

  sendMeshCoreRaw(header, 0, nullptr, payload, pLen, rep.name.c_str(), rep.public_id.c_str(), secret);
}

// ================================================================================
// 📥 GAUTŲ PAKETŲ APDOROJIMAS IR ATSAKYMŲ PARSINIMAS
// ================================================================================
bool processIncomingPacket(uint8_t expectedSenderHash, WaitTarget waitTarget) {
  int length = radio.getPacketLength();
  if (length <= 0) {
    return false; // Tuščias arba klaidingas pertraukties sužadinimas
  }

  static uint8_t packetBuffer[256];
  int state = radio.readData(packetBuffer, length);
  if (state != RADIOLIB_ERR_NONE || length < 2) {
    return false;
  }

  float rssi = radio.getRSSI();
  float snr  = radio.getSNR();

  uint8_t header = packetBuffer[0];
  uint8_t version = (header >> 6) & 0x03;
  uint8_t payloadType = (header >> 2) & 0x0F;
  uint8_t routeType = header & 0x03;

  const char* typeStr = "NEŽINOMAS";
  switch (payloadType) {
    case PAYLOAD_TYPE_REQ:      typeStr = "REQ (Užklausa)"; break;
    case PAYLOAD_TYPE_RESPONSE: typeStr = "RESPONSE (Atsakas)"; break;
    case PAYLOAD_TYPE_TXT_MSG:  typeStr = "TXT_MSG (Tekstas)"; break;
    case PAYLOAD_TYPE_ACK:      typeStr = "ACK (Patvirtinimas)"; break;
    case PAYLOAD_TYPE_ADVERT:   typeStr = "ADVERT (Kartotuvo Reklama/Būsena)"; break;
    case PAYLOAD_TYPE_ANON_REQ: typeStr = "ANON_REQ (Anoniminė)"; break;
    case PAYLOAD_TYPE_PATH:     typeStr = "PATH (Kelias)"; break;
    case PAYLOAD_TYPE_CONTROL:  typeStr = "CONTROL (Valdymas)"; break;
  }

  size_t offset = 1;

  // 1. Transport codes (jei ROUTE_TYPE_TRANSPORT_FLOOD arba ROUTE_TYPE_TRANSPORT_DIRECT)
  uint16_t transportCode1 = 0, transportCode2 = 0;
  bool hasTransportCodes = (routeType == ROUTE_TYPE_TRANSPORT_FLOOD || routeType == ROUTE_TYPE_TRANSPORT_DIRECT);
  if (hasTransportCodes) {
    if ((size_t)length < offset + 4) return false;
    transportCode1 = packetBuffer[offset] | (packetBuffer[offset + 1] << 8);
    transportCode2 = packetBuffer[offset + 2] | (packetBuffer[offset + 3] << 8);
    offset += 4;
  }

  // 2. MeshCore kelio (Path) dekodavimas pagal oficialią MeshCore specifikaciją:
  // Baitas ties offset (path_length_byte):
  //   Bits 7:6 = hash dydis baitais: ((pathLenByte >> 6) + 1) -> 1, 2 arba 3 baitai
  //   Bits 5:0 = šuolių (hops) skaičius: 0..63
  //   pathByteLength = hop_count * hash_size
  if ((size_t)length < offset + 1) return false;
  uint8_t pathLenByte = packetBuffer[offset];
  offset += 1;

  uint8_t pathHashSize = (pathLenByte >> 6) + 1; // 1, 2 arba 3 baitai
  uint8_t pathHopCount = pathLenByte & 0x3F;      // 0..63 šuoliai
  size_t pathByteLength = (size_t)pathHopCount * pathHashSize;

  size_t remainingAfterHeader = (size_t)length - offset;
  bool useLegacy = false;
  if (pathHashSize == 4 || pathByteLength > remainingAfterHeader) {
    useLegacy = true;
    pathByteLength = min((size_t)pathLenByte, remainingAfterHeader);
    if (pathByteLength > 64) pathByteLength = 64;
    pathHopCount = (uint8_t)pathByteLength;
    pathHashSize = 1;
  }

  size_t pathOffset = offset;
  size_t payloadOffset = offset + pathByteLength;
  if (payloadOffset > (size_t)length) {
    return false;
  }

  uint8_t* payload = &packetBuffer[payloadOffset];
  size_t payloadLen = length - payloadOffset;

  // --------------------------------------------------------------------------
  // 1. RAW TRAFIKO SIUNTIMAS Į /api/raw (VISAS PAGAUTAS SRAUTAS BE DEKODAVIMO)
  // --------------------------------------------------------------------------
  #if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument rawRxDoc;
  #else
  StaticJsonDocument<1024> rawRxDoc;
  #endif

  rawRxDoc["direction"] = "RX";
  time_t now = time(nullptr);
  rawRxDoc["timestamp"] = (now >= 1700000000UL) ? (uint32_t)now : lastTxTimestamp;
  rawRxDoc["length"] = length;
  rawRxDoc["rssi"] = rssi;
  rawRxDoc["snr"] = snr;

  String rawHex = "";
  for (int i = 0; i < length; i++) {
    char b[3]; snprintf(b, sizeof(b), "%02X", packetBuffer[i]);
    rawHex += b;
  }
  rawRxDoc["raw_hex"] = rawHex;

  bool attachedKey = false;
  if ((payloadType == PAYLOAD_TYPE_RESPONSE || payloadType == PAYLOAD_TYPE_PATH) && payloadLen >= 2) {
    uint8_t dHash = payload[0];
    uint8_t sHash = payload[1];
    for (size_t i = 0; i < monitoredRepeatersCount; i++) {
      if (monitoredNodes[i].initialized && (monitoredNodes[i].hash == sHash || dHash == myNodeHash)) {
        rawRxDoc["sender_name"] = monitoredRepeaters[i].name;
        rawRxDoc["sender_public_key"] = monitoredRepeaters[i].public_id;
        rawRxDoc["my_public_key"] = MY_PUBKEY_HEX;
        char secHex[65];
        for (int k = 0; k < 32; k++) snprintf(&secHex[k * 2], 3, "%02x", monitoredNodes[i].sharedSecret[k]);
        rawRxDoc["shared_secret_hex"] = secHex;
        rawRxDoc["is_encrypted"] = true;
        attachedKey = true;
        break;
      }
    }
  }
  if (!attachedKey) {
    rawRxDoc["is_encrypted"] = false;
  }

  sendToAPI(rawRxDoc);

  // 3. Filtruojame mūsų pačių arba kito mazgo pakartotus užklausų paketus (REQ arba ANON_REQ)
  if (payloadType == PAYLOAD_TYPE_REQ || payloadType == PAYLOAD_TYPE_ANON_REQ) {
    Serial.println("\n--------------------------------------------------------");
    Serial.printf("[LoRa RX] Gautas %s paketas (%d baitai) iš eterio (aidas / ne atsakymas mūsų mazgui). Praleidžiama.\n",
                  typeStr, length);
    return false;
  }

  Serial.println("\n========================================================");
  Serial.printf("[LoRa RX] GAUTAS PAKETAS! Dydis: %d baitai | RSSI: %.1f dBm | SNR: %.1f dB\n", length, rssi, snr);
  Serial.print("[LoRa RX RAW HEX] ");
  printHexBuffer(packetBuffer, length);
  Serial.println();

  Serial.printf("[MeshCore Header] Versija: %u | Tipas: 0x%02X (%s) | Maršrutas: 0x%02X\n",
                version, payloadType, typeStr, routeType);

  Serial.printf("[MeshCore Kelias] Šuoliai: %u (hash: %u B, iš viso: %u B)",
                pathHopCount, pathHashSize, (unsigned int)pathByteLength);
  if (pathByteLength > 0 && pathOffset + pathByteLength <= (size_t)length) {
    Serial.print(" -> [");
    for (size_t i = 0; i < pathByteLength; i += pathHashSize) {
      if (i > 0) Serial.print(", ");
      for (size_t j = 0; j < pathHashSize && (i + j) < pathByteLength; j++) {
        Serial.printf("%02X", packetBuffer[pathOffset + i + j]);
      }
    }
    Serial.print("]");
  }
  Serial.println();

  Serial.printf("[MeshCore Krovinys] Dydis: %u baitai: ", (unsigned int)payloadLen);
  printHexBuffer(payload, payloadLen);
  Serial.println();

  // --------------------------------------------------------------------------
  // 4. ESP32 NIEKO NEDEKODUOJA:
  // Visi neapdoroti duomenys jau perduoti į foninę siuntimo eilę per sendToAPI(rawRxDoc)!
  // Serveryje jie bus išsaugoti DB ir pilnai iškoduoti bei dešifruoti.
  // Čia patikriname TIK ar šis paketas yra atsakymas mūsų mazgui, kad waitForReply() žinotų sėkmę.
  // --------------------------------------------------------------------------
  if ((payloadType == PAYLOAD_TYPE_RESPONSE || payloadType == PAYLOAD_TYPE_PATH) && payloadLen >= 2) {
    uint8_t destHash = payload[0];
    uint8_t srcHash = payload[1];

    if (destHash == myNodeHash) {
      if (expectedSenderHash == 0 || srcHash == expectedSenderHash) {
        Serial.printf("[LoRa RX] Gautas atsakymas mūsų mazgui (Src: 0x%02X -> Dest: 0x%02X). RAW paketas jau perduotas į API eilę.\n",
                      srcHash, destHash);
        return true;
      }
    }
  }

  // Visi kiti eteryje pagauti paketai (ADVERT skelbimai, kitų mazgų eismas)
  // jau sėkmingai atiduoti į /api/raw siuntimui, tačiau waitForReply tęsia laukimą tikro atsakymo.
  return false;
}

// ================================================================================
// 🌐 WI-FI IR HTTP API SIUNTIMAS
// ================================================================================

// Skenuoja aplinkinius Wi-Fi tinklus ir išveda jų sąrašą į Serial Monitor
void scanWiFiNetworks() {
  Serial.println("\n========================================================");
  Serial.println("[Wi-Fi Skenavimas] Ieškoma pasiekiamų Wi-Fi tinklų...");
  Serial.println("========================================================");
  WiFi.mode(WIFI_STA);
  WiFi.disconnect();
  delay(100);

  int n = WiFi.scanNetworks();
  if (n == 0) {
    Serial.println("[Wi-Fi Skenavimas] Nerasta jokių Wi-Fi tinklų.");
  } else {
    Serial.printf("[Wi-Fi Skenavimas] Rasta tinklų: %d\n", n);
    Serial.println("  --------------------------------------------------------------------------------");
    Serial.printf("  %-4s | %-28s | %-6s | %-4s | %-12s\n", "Nr.", "SSID", "RSSI", "Ch", "Apsauga");
    Serial.println("  --------------------------------------------------------------------------------");
    for (int i = 0; i < n; ++i) {
      const char* secType = "Atviras";
      switch (WiFi.encryptionType(i)) {
        case WIFI_AUTH_OPEN:            secType = "Atviras"; break;
        case WIFI_AUTH_WEP:             secType = "WEP"; break;
        case WIFI_AUTH_WPA_PSK:         secType = "WPA"; break;
        case WIFI_AUTH_WPA2_PSK:        secType = "WPA2"; break;
        case WIFI_AUTH_WPA_WPA2_PSK:    secType = "WPA/WPA2"; break;
        case WIFI_AUTH_WPA2_ENTERPRISE: secType = "Enterprise"; break;
        case WIFI_AUTH_WPA3_PSK:        secType = "WPA3"; break;
        case WIFI_AUTH_WPA2_WPA3_PSK:   secType = "WPA2/WPA3"; break;
        default:                        secType = "Kita"; break;
      }
      Serial.printf("  [%2d] | %-28.28s | %4d | %2d   | %-12s\n",
                    i + 1, WiFi.SSID(i).c_str(), WiFi.RSSI(i), WiFi.channel(i), secType);
      delay(10);
    }
    Serial.println("  --------------------------------------------------------------------------------");
  }
  WiFi.scanDelete();
}

void connectToWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  // Prieš jungiantis nuskaitome ir išvedame pasiekiamų Wi-Fi tinklų sąrašą
  scanWiFiNetworks();

  // Pilnai atjungiame ir išvalome ankstesnę būseną (išvengiama "wifi:sta is connecting" klaidos)
  WiFi.disconnect(true, true);
  delay(300);

  Serial.printf("\n[Wi-Fi] Jungiamasi prie tinklo '%s'...", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false); // Užtikrina stabilų ryšį su iPhone Hotspot
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  // iPhone Hotspot gali užtrukti ilgiau išduodant IP (laukiama iki 20 sekundžių)
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 40) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[Wi-Fi] Prisijungta sėkmingai!");
    Serial.printf("[Wi-Fi] IP adresas: %s | RSSI: %d dBm\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());

    // Sinchronizuojame realų Unix laiką per NTP
    configTime(0, 0, "pool.ntp.org", "time.google.com");
    Serial.print("[NTP] Sinchronizuojamas realus laikas...");
    int retry = 0;
    while (time(nullptr) < 1700000000UL && ++retry <= 15) {
      delay(200);
      Serial.print(".");
    }
    time_t now = time(nullptr);
    if (now >= 1700000000UL) {
      Serial.printf("\n[NTP] Laikas sinchronizuotas: %lu (Unix epoch)\n", (unsigned long)now);
    } else {
      Serial.println("\n[NTP] Nepavyko sinchronizuoti per skirtą laiką, bus naudojamas 2026 m. bazinis laikas.");
    }
  } else {
    Serial.println("\n[Wi-Fi Įspėjimas] Nepavyko prisijungti. Bus bandoma vėliau.");
  }
}

// ================================================================================
// 🧵 FONINIS API SIUNTĖJAS (FreeRTOS Task paleistas ant Core 0)
// Vykdo HTTP POST siuntimą fone, todėl pagrindinė gija (Core 1) su LoRa radiju
// NIEKADA neužstringa ir nelaukia serverio atsakymo!
// ================================================================================
void apiWorkerTask(void* pvParameters) {
  while (true) {
    QueuedApiMessage msg;
    if (xQueueReceive(apiQueue, &msg, portMAX_DELAY) == pdTRUE && msg.payload != nullptr) {
      if (WiFi.status() == WL_CONNECTED) {
        if (wifiHttpMutex == nullptr || xSemaphoreTake(wifiHttpMutex, pdMS_TO_TICKS(5000)) == pdTRUE) {
          const char* endpointPath = API_ENDPOINT_RAW;
          String fullUrl = String(API_BASE_URL) + endpointPath;

          HTTPClient http;
          http.begin(fullUrl);
          http.addHeader("Content-Type", "application/json");
          if (strlen(API_AUTH_KEY) > 0) {
            http.addHeader("Authorization", API_AUTH_KEY);
          }
          http.setTimeout(2500); // 2.5s apsauga nuo pakibimo

          int httpCode = http.POST((uint8_t*)msg.payload, strlen(msg.payload));
          if (httpCode > 0) {
            Serial.printf("[API Foninis Siuntėjas] %s -> Kodas: %d (išsiųsta sėkmingai).\n", endpointPath, httpCode);
          } else {
            Serial.printf("[API Foninis Siuntėjas] %s -> Siuntimo klaida: %s\n", endpointPath, http.errorToString(httpCode).c_str());
          }
          http.end();

          if (wifiHttpMutex != nullptr) {
            xSemaphoreGive(wifiHttpMutex);
          }
        }
      } else {
        Serial.println("[API Foninis Siuntėjas] Wi-Fi neprisijungtas, paketas praleistas.");
      }
      free(msg.payload);
    }
  }
}

// ================================================================================
// ⚡ NE-BLOKUOJANTIS DUOMENŲ PERDAVIMAS Į API
// Išsiunčia duomenis į foninę eilę ir IŠKART grįžta į darbą. Nelaukiama serverio!
// ================================================================================
#if ARDUINOJSON_VERSION_MAJOR >= 7
void sendToAPI(const JsonDocument& doc) {
#else
void sendToAPI(const JsonDocument& doc) {
#endif
  String jsonString;
  serializeJson(doc, jsonString);

  if (apiQueue == nullptr) {
    Serial.println("[API] Klaida: Siuntimo eilė neinicializuota!");
    return;
  }

  char* payloadCopy = strdup(jsonString.c_str());
  if (payloadCopy == nullptr) {
    Serial.println("[API Klaida] Trūksta atminties paketo kopijai!");
    return;
  }

  QueuedApiMessage msg;
  msg.payload = payloadCopy;

  // Siunčiame į foninę eilę be jokio laukimo (0 ticks):
  // Pagrindinė gija (LoRa klausymasis) iškart tęsia darbą be jokio serverio atsakymo laukimo!
  if (xQueueSend(apiQueue, &msg, 0) == pdTRUE) {
    const char* nodeId = doc["node_id"] | (doc["target_name"] | (doc["sender_name"] | "mazgas"));
    const char* pType = doc["direction"] | "RAW";
    Serial.printf("[API Eilė] [RAW] %s '%s' (%u baitai) atiduotas siuntimui į %s.\n",
                  pType, nodeId, (unsigned int)jsonString.length(), API_ENDPOINT_RAW);
    Serial.println("[API] >> LAIKOMA KAIP IŠSIŲSTA << Serverio atsakymo nelaukiama, LoRa radijas toliau nepertraukiamai klausosi!");
  } else {
    Serial.println("[API Įspėjimas] [RAW] Siuntimo eilė pilna. Paketas atmestas, kad neužlaikytų LoRa.");
    free(payloadCopy);
  }
}

// ================================================================================
// 🛠️ PAGALBINĖS FUNKCIJOS
// ================================================================================

// Hex eilutės konvertavimas į baitų masyvą
bool hexStringToBytes(const char* hexStr, uint8_t* outBytes, size_t maxLen, size_t &outLen) {
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

// Baitų masyvo spausdinimas heksadecimaliu formatu į Serial
void printHexBuffer(const uint8_t* buf, size_t len) {
  if (buf == nullptr || len == 0) {
    Serial.print("(tuščia)");
    return;
  }
  for (size_t i = 0; i < len; i++) {
    Serial.printf("%02X", buf[i]);
    if (i + 1 < len) Serial.print(" ");
  }
}
