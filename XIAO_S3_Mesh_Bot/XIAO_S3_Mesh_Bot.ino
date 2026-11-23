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
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <RadioLib.h>
#include <ArduinoJson.h>
#include "config.h"
#include "MeshCrypto.h"
#include "MeshCorePacket.h"
#include "WebConfigPortal.h"

// ESP-IDF's default public CA bundle, included in the ESP32 Arduino core.
extern const uint8_t caBundleStart[] asm("_binary_x509_crt_bundle_start");
extern const uint8_t caBundleEnd[] asm("_binary_x509_crt_bundle_end");

// ================================================================================
// 📡 MESHCORE PROTOKOLO KONSTANTOS
// ================================================================================

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

  // Išmoktas arba sukonfigūruotas kelias (Source Routing / DIRECT):
  bool hasRoute;
  bool routeConfirmed;   // Patvirtinta sėkmingu dvipusiu ryšiu (gavus RESPONSE per DIRECT)
  bool forceFlood;       // Priverstinis FLOOD (jei nustatyta į "FLOOD")
  uint8_t routeHopCount;
  uint8_t routeHashSize;
  uint8_t routePath[32];
  size_t routePathLen;
  unsigned long routeLearnedAt;

  // 🛡️ Savigydos mechanizmas: nesėkmingo kelio karantinas (Blacklist / Quarantine)
  // Jei per DIRECT kelią negaunamas atsakymas, šis kelias ar pirmasis jo šuolis izoliuojamas
  uint8_t quarantinePath[32];
  size_t quarantinePathLen;
  bool quarantineZeroHop;
  unsigned long quarantinedAt;
};
MonitoredNode monitoredNodes[16];

// Dinamiškai parsiunčiamų kartotuvų masyvas (iš REPEATERS_URL prieš kiekvieną ciklą)
RepeaterTarget monitoredRepeaters[16];
size_t monitoredRepeatersCount = 0;

// 🔑 Aktyvių kartotuvų sesijų (ACL) atmintinė ESP32 RAM'e
// Jei kartotuvas jau atsakė į Login ar Telemetriją, įsimename jo 2 baitų prefiksą (pubKey[0..1]),
// kad nereikėtų kaskart siųsti Login (ANON_REQ) paketo su slaptažodžiu.
static const size_t MAX_SESSION_CACHE = 64;
static uint16_t activeSessionCache[MAX_SESSION_CACHE];
static size_t activeSessionCount = 0;

uint16_t getNodeSessionKey(const uint8_t* pubKey) {
  return (uint16_t)((pubKey[0] << 8) | pubKey[1]);
}

bool hasLocalSession(const uint8_t* pubKey) {
  uint16_t key = getNodeSessionKey(pubKey);
  for (size_t i = 0; i < activeSessionCount; i++) {
    if (activeSessionCache[i] == key) return true;
  }
  return false;
}

void markLocalSessionActive(const uint8_t* pubKey) {
  uint16_t key = getNodeSessionKey(pubKey);
  for (size_t i = 0; i < activeSessionCount; i++) {
    if (activeSessionCache[i] == key) return;
  }
  if (activeSessionCount < MAX_SESSION_CACHE) {
    activeSessionCache[activeSessionCount++] = key;
  } else {
    // Žiedinis perrašymas
    for (size_t i = 1; i < MAX_SESSION_CACHE; i++) {
      activeSessionCache[i - 1] = activeSessionCache[i];
    }
    activeSessionCache[MAX_SESSION_CACHE - 1] = key;
  }
}

void clearLocalSession(const uint8_t* pubKey) {
  uint16_t key = getNodeSessionKey(pubKey);
  for (size_t i = 0; i < activeSessionCount; i++) {
    if (activeSessionCache[i] == key) {
      for (size_t j = i + 1; j < activeSessionCount; j++) {
        activeSessionCache[j - 1] = activeSessionCache[j];
      }
      activeSessionCount--;
      return;
    }
  }
}

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
void generateSelfIdentity(const uint8_t* seed);
bool fetchRepeatersFromURL();
void initMonitoredNodeKeys();
void requestTelemetryFromAllRepeaters();
void requestTelemetryFromSingleRepeater(size_t repIdx);
enum class PollResult { ReplyReceived, ReplyReceivedThenAborted, Timeout, Aborted, InvalidPassword };
enum class SendResult { ReadyToWait, Paused, InvalidPassword, EncodeFailed, TransmitFailed, ReceiveFailed };
struct ReciprocalPath {
  int peer = -1;
  uint8_t outboundLen = 0;
  const uint8_t* outbound = nullptr;
  uint8_t returnLen = 0;
  const uint8_t* returnPath = nullptr;
};
struct ReceivedPacket {
  bool acceptedReply;
  bool canContinue;
};
PollResult waitForReply(unsigned long timeoutMs, uint8_t expectedSenderHash, WaitTarget waitTarget);
bool processIncomingPacket(uint8_t expectedSenderHash, WaitTarget waitTarget, ReciprocalPath& reciprocal);
ReceivedPacket serviceReceivedPacket(uint8_t expectedSenderHash = 0, WaitTarget waitTarget = WAIT_ANY);
constexpr size_t MAX_REPEATER_LOGIN_PASSWORD_BYTES = 15;
SendResult sendMeshCoreLogin(size_t repIdx, bool flood);
SendResult sendMeshCorePath(const ReciprocalPath& path);
SendResult sendMeshCoreStatusRequest(size_t repIdx, bool flood);
SendResult sendMeshCoreTelemetryRequest(size_t repIdx, bool flood);
SendResult sendMeshCoreRaw(uint8_t header, uint8_t pathLen, const uint8_t* path, const uint8_t* payload, size_t payloadLen,
                     const char* targetName = nullptr, const char* targetPubKey = nullptr, const uint8_t* sharedSecret = nullptr);
bool yieldDelay(unsigned long ms);
uint8_t getPathLengthByte(uint8_t hops = 0);

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

  // Start the Wi-Fi radio before generating or migrating a missing seed.
  initWebConfigPortal();
  uint8_t seed[SEED_SIZE];
  if (!initConfigNVS() || !decodeSeed(savedConfig.seed, seed)) {
    Serial.println("[Sistema] Nepavyko užkrauti nustatymų. Sutvarkykite juos portale ir perkraukite.");
    return;
  }
  activeConfig = savedConfig;
  generateSelfIdentity(seed);
  waitForApClients(AP_WAIT_WINDOW_MS);

  Serial.println("\n========================================================");
  Serial.println("  Seeed Studio XIAO ESP32-S3 MeshCore Telemetry Bot");
  Serial.println("========================================================");
  Serial.printf("[Info] Kartotuvų sąrašas bus parsiųstas prieš kiekvieną ciklą iš: %s\n", REPEATERS_URL);
  Serial.printf("[Info] Laukimo laikas po kiekvienos užklausos: %lu ms (%.1f s)\n",
                activeConfig.replyTimeout, activeConfig.replyTimeout / 1000.0f);
  Serial.printf("[Info] Tikrinimo ciklo intervalas: %lu min (%lu ms)\n",
                activeConfig.interval / 60000, activeConfig.interval);
  Serial.printf("[Info] API Bazinis URL: %s\n", API_BASE_URL);
  Serial.printf("  * RAW trafiko endpoint:  %s%s (visi įrašai siunčiami čia ir apdorojami serveryje)\n", API_BASE_URL, API_ENDPOINT_RAW);
  Serial.printf("[Info] Mazgų kelio (Path) hash: %u bitai (%u baitai)\n", activeConfig.pathBits, (unsigned int)(activeConfig.pathBits / 8));
  Serial.printf("[Info] ESP32 Boto Identifikatorius (BOT_ID): '%s'\n", activeConfig.botId.c_str());
  if (activeConfig.scopeEnabled) {
    if (SCOPE_CODE_1 != 0) {
      Serial.println("[Info] Regioninis Scope: naudojami fiksuoti (legacy) FLOOD transporto kodai.");
    } else {
      Serial.printf("[Info] Regioninis Scope: ĮJUNGTAS ('%s', FLOOD kodas skaičiuojamas kiekvienam paketui)\n",
                    activeConfig.scope.c_str());
    }
  } else {
    Serial.println("[Info] Regioninis Scope: IŠJUNGTAS (Unscoped flood)");
  }

  // 3. Wi-Fi ryšys ir NTP laikas
  connectToWiFi();
  delay(200);

  // 3. Inicijuojame foninę API siuntimo eilę ir užduotį (Core 0),
  // kad išsiuntus duomenis į API NIEKADA nebūtų laukiama serverio atsakymo,
  // o pagrindinis branduolys (Core 1) netrukdomas klausytųsi LoRa radijo eterio!
  apiQueue = xQueueCreate(32, sizeof(QueuedApiMessage));
  wifiHttpMutex = xSemaphoreCreateMutex();
  if (!apiQueue || !wifiHttpMutex || xTaskCreatePinnedToCore(
    apiWorkerTask,
    "apiWorkerTask",
    8192,
    NULL,
    1,
    &apiTaskHandle,
    0 // Paleidžiama ant Core 0 (Wi-Fi branduolys)
  ) != pdPASS) {
    Serial.println("[Sistema] Nepavyko paleisti API siuntėjo.");
    return;
  }

  // 4. SX1262 LoRa radijas
  initLoRa();

  Serial.println("\n[Sistema] Paruošta darbui. Iškart inicijuojamas pirmasis duomenų gavimas...");
  requestTelemetryFromAllRepeaters();
  lastTelemetryRequest = millis(); // Nuo šio momento skaičiuojamas sekantis intervalas
  Serial.printf("[Sistema] Pirmas ciklas baigtas. Sekantis tikrinimas po %lu min.\n", activeConfig.interval / 60000);
}

// ================================================================================
// 🔄 MAIN LOOP
// ================================================================================
void loop() {
  // Aptarnaujame Web konfigūracijos portalą
  handleWebConfigPortal();
  if (!apiTaskHandle || telemetryPaused) {
    delay(20);
    return;
  }

  // 1. Klausomės eterio (jei kartotuvas atsiunčia paketą, pvz. ADVERT)
  if (receivedFlag && !serviceReceivedPacket().canContinue) return;

  // 2. Periodiškas užklausų inicijavimas po nustatyto laiko intervalo
  if (millis() - lastTelemetryRequest >= activeConfig.interval) {
    lastTelemetryRequest = millis();
    requestTelemetryFromAllRepeaters();
    Serial.printf("[Sistema] Ciklas baigtas. Sekantis tikrinimas po %lu min.\n", activeConfig.interval / 60000);
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
// ⏳ APKLAUSOS PAUZĖ
// Laukimo metu apdoroja gautus LoRa paketus ir aptarnauja konfigūracijos portalą.
// Grąžina false, jei telemetrija pristabdoma arba nepavyksta atnaujinti LoRa priėmimo.
// ================================================================================
bool yieldDelay(unsigned long ms) {
  unsigned long start = millis();
  while (millis() - start < ms) {
    if (receivedFlag && !serviceReceivedPacket().canContinue) return false;
    handleWebConfigPortal();
    if (telemetryPaused) return false;
    delay(10);
  }
  return !telemetryPaused;
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
  Serial.printf("[Ciklas] Nustatytas laukimo laikas po kiekvienos užklausos: %.1f s\n", activeConfig.replyTimeout / 1000.0f);
  Serial.println("========================================================");

  bool cooldownFailed = false;
  for (size_t i = 0; i < monitoredRepeatersCount && !telemetryPaused; i++) {
    if (!monitoredNodes[i].initialized) {
      Serial.printf("[Ciklas] Kartotuvas '%s' neinicializuotas (patikrinkite raktą). Praleidžiama.\n",
                    monitoredRepeaters[i].name.c_str());
      continue;
    }

    Serial.printf("\n>>> [%u/%u] PRADEDAMAS DARBAS SU: %s (Hash: %02X | %s) <<<\n",
                  (unsigned int)(i + 1), (unsigned int)monitoredRepeatersCount,
                  monitoredRepeaters[i].name.c_str(), monitoredNodes[i].hash,
                  monitoredRepeaters[i].isDirect ? "Tiesioginis DIRECT" : "Nutolęs FLOOD");

    requestTelemetryFromSingleRepeater(i);

    if (!telemetryPaused && i + 1 < monitoredRepeatersCount) {
      Serial.printf("\n[Pauzė] Baigtas darbas su '%s'. Pauzė %.1f s prieš kreipiantis į sekantį...\n",
                    monitoredRepeaters[i].name.c_str(), activeConfig.cooldown / 1000.0f);
      if (!yieldDelay(activeConfig.cooldown)) {
        cooldownFailed = !telemetryPaused;
        break;
      }
    }
  }

  // Pasibaigus apklausai bandome vėl įjungti LoRa priėmimą.
  radio.startReceive();
  if (telemetryPaused) Serial.println("\n[Ciklas] Telemetrija pristabdyta iki perkrovimo.");
  else if (cooldownFailed) Serial.println("\n[Ciklas] Apklausa nutraukta dėl LoRa klaidos.");
  else Serial.println("\n[Ciklas] Visi kartotuvai patikrinti!");
}

void requestTelemetryFromSingleRepeater(size_t repIdx) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t targetHash = monitoredNodes[repIdx].hash;

  currentRepDoc.clear();
  currentRepHasData = false;

  bool isDirectZeroHop = (monitoredNodes[repIdx].hasRoute && monitoredNodes[repIdx].routeHopCount == 0);
  unsigned long replyTimeout = isDirectZeroHop ? activeConfig.replyTimeout : activeConfig.remoteTimeout;

  String routeDesc = "FLOOD (kelias nežinomas)";
  if (monitoredNodes[repIdx].hasRoute) {
    if (monitoredNodes[repIdx].routeHopCount == 0) {
      routeDesc = "DIRECT 0-hop (tiesioginis)";
    } else {
      String pStr = "";
      for (size_t k = 0; k < monitoredNodes[repIdx].routePathLen; k++) {
        char b[3]; snprintf(b, sizeof(b), "%02X", monitoredNodes[repIdx].routePath[k]);
        pStr += b;
      }
      routeDesc = String("DIRECT ") + monitoredNodes[repIdx].routeHopCount + " hop(s) per [" + pStr + "]";
    }
  }

  Serial.printf("\n>>> [Kartotuvas %u/%u] '%s' (%s, Hash: %02X, Timeout: %.1fs) <<<\n",
                (unsigned int)(repIdx + 1), (unsigned int)monitoredRepeatersCount,
                rep.name.c_str(), routeDesc.c_str(),
                targetHash, replyTimeout / 1000.0f);

  const uint8_t* targetPubKey = monitoredNodes[repIdx].pubKey;
  bool sessionActive = rep.skipLogin || hasLocalSession(targetPubKey);
  bool didLoginThisCycle = false;

  // Atsakymo laukiame tik jei paketas išsiųstas ir LoRa priėmimas vėl įjungtas.
  auto sendAndWait = [&](SendResult (*send)(size_t, bool), bool flood, WaitTarget target) -> PollResult {
    unsigned long timeout = !flood && monitoredNodes[repIdx].routeHopCount == 0
        ? activeConfig.replyTimeout : activeConfig.remoteTimeout;
    switch (send(repIdx, flood)) {
      case SendResult::ReadyToWait: return waitForReply(timeout, targetHash, target);
      case SendResult::InvalidPassword: return PollResult::InvalidPassword;
      default: return PollResult::Aborted;
    }
  };

  // Laukiant atsakymo maršrutas gali pasikeisti. Pasibaigus laukimo laikui vertiname tik naudotą kelią.
  struct AttemptedRoute {
    bool direct;
    uint8_t hops;
    uint8_t hashSize;
    size_t length;
    uint8_t path[sizeof(MonitoredNode::routePath)];

    explicit AttemptedRoute(const MonitoredNode& node)
        : direct(node.hasRoute), hops(node.routeHopCount), hashSize(node.routeHashSize), length(node.routePathLen) {
      if (direct && length) memcpy(path, node.routePath, length);
    }

    bool matches(const MonitoredNode& node) const {
      return direct && node.hasRoute && hops == node.routeHopCount && hashSize == node.routeHashSize &&
             length == node.routePathLen && (!length || memcmp(path, node.routePath, length) == 0);
    }
  };
  auto receivedReply = [](PollResult result) {
    return result == PollResult::ReplyReceived || result == PollResult::ReplyReceivedThenAborted;
  };
  auto aborted = [](PollResult result) {
    return result == PollResult::Aborted || result == PollResult::ReplyReceivedThenAborted;
  };

  // Prisijungimas prieš apklausą arba po neatsakytos FLOOD užklausos.
  auto performLoginStep = [&]() -> PollResult {
    didLoginThisCycle = true;
    MonitoredNode& node = monitoredNodes[repIdx];
    AttemptedRoute route(node);
    PollResult result = sendAndWait(sendMeshCoreLogin, !route.direct, WAIT_LOGIN);
    if (result == PollResult::Timeout && route.direct) {
      Serial.printf("[Maršrutizatorius] DIRECT maršrutas į '%s' neatsakė į Login. Kartojama per FLOOD.\n", rep.name.c_str());
      // Laikinai paslepiame tik išbandytą kelią, kad FLOOD atsakymas galėtų jį pakeisti.
      const bool hidden = route.matches(node);
      if (hidden) node.hasRoute = false;
      result = sendAndWait(sendMeshCoreLogin, true, WAIT_LOGIN);
      const bool restoreRoute = result == PollResult::Aborted || result == PollResult::InvalidPassword ||
                                (result == PollResult::Timeout && route.length > 0);
      if (hidden && !node.hasRoute && restoreRoute) {
        node.hasRoute = true;
      }
    }
    if (receivedReply(result)) {
      markLocalSessionActive(targetPubKey);
    }
    if (aborted(result) || result == PollResult::InvalidPassword) return result;
    // Po išsiųstos Login užklausos palaukiame 3 s, net jei atsakymo negavome.
    if (!yieldDelay(3000)) return receivedReply(result) ? PollResult::ReplyReceivedThenAborted : PollResult::Aborted;
    return result;
  };

  // Žingsnis 1: Login praleidžiame, jei serveris nurodė skip_login arba turime vietinę sesiją.
  if (!sessionActive) {
    PollResult result = performLoginStep();
    if (aborted(result)) return;
    if (result == PollResult::Timeout) {
      Serial.printf("[Ciklas] ⚠️ LOGIN_OK iš '%s' negautas (gali būti prarastas grįžtant per multi-hop), tačiau bandoma 22 B užklausa...\n",
                    rep.name.c_str());
    }
  } else {
    Serial.printf("[Sesija] 🔑 Kartotuvas '%s' jau turi aktyvią sesiją (ACL) – Login (ANON_REQ) praleidžiamas!\n",
                  rep.name.c_str());
  }

  auto pollRequest = [&](SendResult (*send)(size_t, bool), WaitTarget target, const char* label) -> PollResult {
    auto tryRoute = [&]() -> PollResult {
      AttemptedRoute route(monitoredNodes[repIdx]);
      PollResult result = sendAndWait(send, !route.direct, target);
      if (result == PollResult::Timeout && route.direct) {
        Serial.printf("[Maršrutizatorius] '%s' neatsakė į %s per DIRECT. Kartojama per FLOOD.\n",
                      rep.name.c_str(), label);
        if (route.matches(monitoredNodes[repIdx])) quarantineFailedRoute(repIdx);
        result = sendAndWait(send, true, target);
      }
      return result;
    };

    PollResult result = tryRoute();
    if (result == PollResult::Timeout && !didLoginThisCycle) {
      Serial.printf("[Sesija Fallback] '%s' neatsakė į %s per FLOOD. Išvaloma sesija, vykdomas Login.\n",
                    rep.name.c_str(), label);
      clearLocalSession(targetPubKey);
      PollResult login = performLoginStep();
      if (aborted(login) || login == PollResult::InvalidPassword) return login;
      result = tryRoute();
    }
    if (receivedReply(result)) markLocalSessionActive(targetPubKey);
    return result;
  };

  // Žingsnis 2: Būsenos ir statistikos užklausa (REQ_TYPE_GET_STATUS)
  if (rep.reqStatus) {
    Serial.printf("[Užklausa 2/3] Siunčiama būsenos užklausa į '%s'.\n", rep.name.c_str());
    if (aborted(pollRequest(sendMeshCoreStatusRequest, WAIT_STATUS, "Status"))) return;
  } else {
    Serial.printf("[Užklausa 2/3] Būsenos užklausa ('status') išjungta kartotuvui '%s'. Praleidžiama.\n", rep.name.c_str());
  }

  // Nustatytas tarpas tarp būsenos ir telemetrijos užklausų.
  if (rep.reqStatus && rep.reqTelemetry) {
    Serial.printf("[Pauzė] Laukimo tarpas tarp užklausų: %.1f s...\n", activeConfig.interDelay / 1000.0f);
    if (!yieldDelay(activeConfig.interDelay)) return;
  }

  // Žingsnis 3: Telemetrijos užklausa (REQ_TYPE_GET_TELEMETRY_DATA - Baterija ir Temp)
  if (rep.reqTelemetry) {
    Serial.printf("[Užklausa 3/3] Siunčiama telemetrijos užklausa į '%s'.\n", rep.name.c_str());
    if (aborted(pollRequest(sendMeshCoreTelemetryRequest, WAIT_TELEMETRY, "Telemetriją"))) return;
  } else {
    Serial.printf("[Užklausa 3/3] Telemetrijos užklausa ('telemetry') išjungta kartotuvui '%s'. Praleidžiama.\n", rep.name.c_str());
  }

  Serial.printf("\n[Ciklas] Kartotuvo '%s' užklausų etapas baigtas.\n", rep.name.c_str());
}

// ================================================================================
// ⏳ ATSAKYMO LAUKIMAS
// Atsakymo nebuvimą atskiriame nuo pristabdytos apklausos ar LoRa priėmimo klaidos.
// ================================================================================
PollResult waitForReply(unsigned long timeoutMs, uint8_t expectedSenderHash, WaitTarget waitTarget) {
  unsigned long start = millis();
  int packetsCount = 0;
  Serial.printf("[RX Laukimas] Klausomasi kartotuvo atsakymo (iki %.1f s)...\n", timeoutMs / 1000.0f);

  while (millis() - start < timeoutMs) {
    if (telemetryPaused) return PollResult::Aborted;
    if (receivedFlag) {
      packetsCount++;
      ReceivedPacket packet = serviceReceivedPacket(expectedSenderHash, waitTarget);
      if (!packet.canContinue) return packet.acceptedReply ? PollResult::ReplyReceivedThenAborted : PollResult::Aborted;
      if (packet.acceptedReply) {
        Serial.println("[RX Laukimas] Atsakymas sėkmingai gautas ir apdorotas!");
        // Dar 500 ms priimame papildomus paketus. Jau gautas atsakymas lieka galioti.
        return yieldDelay(500) ? PollResult::ReplyReceived : PollResult::ReplyReceivedThenAborted;
      }
    }
    delay(15);
  }

  if (telemetryPaused) return PollResult::Aborted;
  if (packetsCount == 0) {
    Serial.println("[RX Laukimas] Per skirtą laiką atsakymo paketų negauta.");
  } else {
    Serial.printf("[RX Laukimas] Lango pabaiga. Gauta eterio paketų: %d (atsakymo iš tikslinio kartotuvo negauta).\n", packetsCount);
  }

  return PollResult::Timeout;
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
void generateSelfIdentity(const uint8_t* seed) {
  MeshCrypto::generateKeypair(myPublicKey, myPrivateKey, seed);
  myNodeHash = myPublicKey[0];
  activeSessionCount = 0;
  MY_PUBKEY_HEX = bytesToHexString(myPublicKey, PUB_KEY_SIZE);

  Serial.println("\n[MeshCore Kriptografija - Mūsų Tapatybė]");
  Serial.printf("  * Mūsų ESP32 Mazgo Hash: %02X\n", myNodeHash);
  Serial.print("  * Mūsų Ed25519 Viešasis: ");
  printHexBuffer(myPublicKey, PUB_KEY_SIZE);
  Serial.println();
}

// Parsiunčia kartotuvų sąrašą iš REPEATERS_URL prieš kiekvieną ciklą.
// Grąžina true jei sąraše yra bent vienas kartotuvas, false – jei nepasiekiamas arba tuščias.
bool fetchRepeatersFromURL() {
  monitoredRepeatersCount = 0;

  if (telemetryPaused) return false;
  if (!activeConfig.botId.length()) {
    Serial.println("[FetchRepeaters] Nenurodytas arba netinkamas BOT_ID. Nustatykite jį konfigūracijos portale.");
    return false;
  }
  if (!activeStationConnected()) {
    Serial.println("[FetchRepeaters] Wi-Fi neprisijungtas, negalima parsisiųsti sąrašo.");
    return false;
  }

  if (time(nullptr) < 1700000000UL) {
    Serial.println("[FetchRepeaters] Laukiama laiko sinchronizacijos prieš HTTPS užklausą.");
    return false;
  }

  if (wifiHttpMutex != nullptr && xSemaphoreTake(wifiHttpMutex, pdMS_TO_TICKS(5000)) != pdTRUE) {
    Serial.println("[FetchRepeaters] Wi-Fi kanalas šiuo metu užimtas.");
    return false;
  }

  String requestUrl = String(REPEATERS_URL);
  requestUrl += (requestUrl.indexOf('?') >= 0) ? "&bot_id=" : "?bot_id=";
  requestUrl += activeConfig.botId;

  WiFiClientSecure client;
  client.setCACertBundle(caBundleStart, caBundleEnd - caBundleStart);
  HTTPClient http;
  if (!requestUrl.startsWith("https://") || !http.begin(client, requestUrl)) {
    Serial.println("[FetchRepeaters] Nepavyko paruošti HTTPS užklausos.");
    if (wifiHttpMutex != nullptr) xSemaphoreGive(wifiHttpMutex);
    return false;
  }
  http.setTimeout(8000);
  http.addHeader("X-Bot-ID", activeConfig.botId);
  Serial.printf("[FetchRepeaters] Užklausiama sekantis kartotuvas botui '%s': %s\n", activeConfig.botId.c_str(), requestUrl.c_str());
  int code = http.GET();

  if (code != 200) {
    Serial.printf("[FetchRepeaters] HTTP klaida %d parsiunčiant iš '%s'\n", code, requestUrl.c_str());
    http.end();
    if (wifiHttpMutex != nullptr) xSemaphoreGive(wifiHttpMutex);
    return false;
  }

  String body = http.getString();
  http.end();
  if (wifiHttpMutex != nullptr) xSemaphoreGive(wifiHttpMutex);

  Serial.printf("[FetchRepeaters] Gautas atsakymas (%u baitai)\n", (unsigned int)body.length());

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

  // Palaikome formatus:
  // 1) Pavienis objektas: {"name": "...", "public_id": "...", ...}
  // 2) Tiesioginis masyvas: [...]
  // 3) Objektas su masyvu: {"repeaters": [...]}
  if (doc.is<JsonObject>() && (doc.containsKey("public_id") || doc.containsKey("name"))) {
    JsonObject obj = doc.as<JsonObject>();
    monitoredRepeaters[0].name         = obj["name"].as<String>();
    monitoredRepeaters[0].public_id    = obj["public_id"].as<String>();
    monitoredRepeaters[0].password     = obj["password"].as<String>();
    monitoredRepeaters[0].password.trim();
    if (monitoredRepeaters[0].password.length() == 0) {
      monitoredRepeaters[0].password = "hello";
    }
    monitoredRepeaters[0].isDirect     = obj["isDirect"] | false;
    monitoredRepeaters[0].skipLogin    = obj.containsKey("skip_login") ? (bool)obj["skip_login"] : (obj.containsKey("login") ? !((bool)obj["login"]) : false);
    monitoredRepeaters[0].reqStatus    = obj.containsKey("status") ? (bool)obj["status"] : true;
    monitoredRepeaters[0].reqTelemetry = obj.containsKey("telemetry") ? (bool)obj["telemetry"] : true;
    monitoredRepeaters[0].path         = obj.containsKey("path") ? obj["path"].as<String>() : "";
    monitoredRepeatersCount = 1;
  } else if (doc.is<JsonArray>() || doc["repeaters"].is<JsonArray>()) {
    JsonArray arr = doc.is<JsonArray>() ? doc.as<JsonArray>() : doc["repeaters"].as<JsonArray>();
    for (JsonObject obj : arr) {
      if (monitoredRepeatersCount >= MAX_MONITORED_REPEATERS) break;
      size_t idx = monitoredRepeatersCount;
      monitoredRepeaters[idx].name         = obj["name"].as<String>();
      monitoredRepeaters[idx].public_id    = obj["public_id"].as<String>();
      monitoredRepeaters[idx].password     = obj["password"].as<String>();
      monitoredRepeaters[idx].password.trim();
      if (monitoredRepeaters[idx].password.length() == 0) {
        monitoredRepeaters[idx].password = "hello";
      }
      monitoredRepeaters[idx].isDirect     = obj["isDirect"] | false;
      monitoredRepeaters[idx].skipLogin    = obj.containsKey("skip_login") ? (bool)obj["skip_login"] : (obj.containsKey("login") ? !((bool)obj["login"]) : false);
      monitoredRepeaters[idx].reqStatus    = obj.containsKey("status") ? (bool)obj["status"] : true;
      monitoredRepeaters[idx].reqTelemetry = obj.containsKey("telemetry") ? (bool)obj["telemetry"] : true;
      monitoredRepeaters[idx].path         = obj.containsKey("path") ? obj["path"].as<String>() : "";
      monitoredRepeatersCount++;
    }
  } else {
    Serial.println("[FetchRepeaters] Neatpažintas JSON formatas.");
    return false;
  }

  if (monitoredRepeatersCount == 0) {
    Serial.println("[FetchRepeaters] Sąrašas tuščias ([] arba nėra įrašų).");
    return false;
  }

  Serial.printf("[FetchRepeaters] Sėkmingai įkelti %u kartotuvai:\n", (unsigned int)monitoredRepeatersCount);
  for (size_t i = 0; i < monitoredRepeatersCount; i++) {
    Serial.printf("  [%u] %s (%s) | login: %s | status: %s | telemetry: %s\n",
                  (unsigned int)(i + 1), monitoredRepeaters[i].name.c_str(),
                  monitoredRepeaters[i].isDirect ? "DIRECT" : "FLOOD",
                  monitoredRepeaters[i].skipLogin ? "SKIP (Sesija aktyvi)" : "TAIP",
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

      Serial.printf("  * Kartotuvas [%u] '%s': Hash %02X | %s\n",
                    (unsigned int)(i + 1), monitoredRepeaters[i].name.c_str(),
                    monitoredNodes[i].hash,
                    monitoredRepeaters[i].isDirect ? "DIRECT 0 hops" : "FLOOD multi-hop");

      // Kiekvieno ciklo pradžioje išvalome seno mazgo karantiną (nes per /api/repeater slotas [0] keičiasi!)
      // ir nustatome maršrutą pagal serverio pateiktą 'path':
      String p = monitoredRepeaters[i].path;
      p.trim();
      monitoredNodes[i].forceFlood = false;
      monitoredNodes[i].routeConfirmed = false;
      monitoredNodes[i].quarantinePathLen = 0;
      monitoredNodes[i].quarantineZeroHop = false;
      monitoredNodes[i].quarantinedAt = 0;
      if (p.equalsIgnoreCase("FLOOD")) {
        monitoredNodes[i].forceFlood = true;
        monitoredNodes[i].hasRoute = false;
        monitoredNodes[i].routeHopCount = 0;
        monitoredNodes[i].routePathLen = 0;
        Serial.printf("    - Maršrutas: PRIVERSTINIS FLOOD (visada transliuojama per visą tinklą)\n");
      } else if (p.equalsIgnoreCase("DIRECT") || (p.length() == 0 && monitoredRepeaters[i].isDirect)) {
        monitoredNodes[i].hasRoute = true;
        monitoredNodes[i].routeHopCount = 0;
        monitoredNodes[i].routeHashSize = (activeConfig.pathBits == 16) ? 2 : 1;
        monitoredNodes[i].routePathLen = 0;
        monitoredNodes[i].routeLearnedAt = millis();
        Serial.printf("    - Maršrutas: TIESIOGINIS DIRECT (0 šuolių)\n");
      } else if (p.length() >= 2 && (p.length() % 2 == 0)) {
        size_t pBytes = 0;
        if (hexStringToBytes(p.c_str(), monitoredNodes[i].routePath, sizeof(monitoredNodes[i].routePath), pBytes)) {
          monitoredNodes[i].hasRoute = true;
          uint8_t defHashSize = (activeConfig.pathBits == 24) ? 3 : ((activeConfig.pathBits == 16) ? 2 : 1);
          if (pBytes % defHashSize != 0) defHashSize = 1;
          monitoredNodes[i].routeHashSize = defHashSize;
          monitoredNodes[i].routeHopCount = (uint8_t)(pBytes / defHashSize);
          monitoredNodes[i].routePathLen = pBytes;
          monitoredNodes[i].routeLearnedAt = millis();
          Serial.printf("    - Maršrutas: TIKSLINIS DIRECT (%u šuol., kelias: [%s])\n",
                        monitoredNodes[i].routeHopCount, p.c_str());
        }
      } else {
        monitoredNodes[i].hasRoute = false;
        monitoredNodes[i].routeHopCount = 0;
        monitoredNodes[i].routePathLen = 0;
        Serial.printf("    - Maršrutas: NĖRA (pradžioje bus naudojamas FLOOD)\n");
      }
    } else {
      Serial.printf("  [KLAIDA] Neteisingas viešasis raktas kartotuvui '%s'!\n",
                    monitoredRepeaters[i].name.c_str());
    }
  }
}

// 🛡️ Savigydos mechanizmas: nesėkmingo kelio izoliavimas (Quarantine / Blacklist)
// Kai DIRECT užklausa negauna atsakymo (pvz., tarpinis kartotuvas nepersiunčia signalo atgal),
// šis kelias įtraukiamas į karantiną 15 minučių, kad ESP32 iš karto pereitų prie veikiančių kartotuvų arba FLOOD.
void quarantineFailedRoute(size_t repIdx) {
  if (repIdx >= monitoredRepeatersCount) return;
  if (!monitoredNodes[repIdx].hasRoute) return;

  monitoredNodes[repIdx].quarantinePathLen = monitoredNodes[repIdx].routePathLen;
  if (monitoredNodes[repIdx].routePathLen > 0) {
    memcpy(monitoredNodes[repIdx].quarantinePath, monitoredNodes[repIdx].routePath, monitoredNodes[repIdx].routePathLen);
  } else {
    monitoredNodes[repIdx].quarantineZeroHop = true;
  }
  monitoredNodes[repIdx].quarantinedAt = millis();
  monitoredNodes[repIdx].hasRoute = false;
  monitoredNodes[repIdx].routeHopCount = 0;
  monitoredNodes[repIdx].routePathLen = 0;
  monitoredNodes[repIdx].routeConfirmed = false;
}

// Tikrina ar siūlomas kandidatinis kelias (ar jo pirmasis šuolis) šiuo metu yra karantine
bool isPathQuarantined(size_t repIdx, uint8_t hopCount, const uint8_t* candidatePath, size_t pathLen, uint8_t hashSize) {
  if (repIdx >= monitoredRepeatersCount) return false;

  // Karantinas automatiškai baigiasi po 15 minučių
  if (monitoredNodes[repIdx].quarantinedAt == 0 || millis() - monitoredNodes[repIdx].quarantinedAt > 15 * 60 * 1000UL) {
    monitoredNodes[repIdx].quarantinePathLen = 0;
    monitoredNodes[repIdx].quarantineZeroHop = false;
    return false;
  }

  // 1. Tiesioginis 0-hop ryšys buvo karantine:
  if (hopCount == 0 && monitoredNodes[repIdx].quarantineZeroHop) {
    return true;
  }

  // 2. Tikriname ar kandidato pirmasis šuolis (artimiausias kartotuvas prie mūsų) sutampa su nesėkmingu:
  if (hashSize < 1) hashSize = 2;
  if (pathLen >= hashSize && monitoredNodes[repIdx].quarantinePathLen >= hashSize) {
    if (memcmp(candidatePath, monitoredNodes[repIdx].quarantinePath, hashSize) == 0) {
      return true; // Šis pirmasis kartotuvas jau parodė, kad nepersiunčia signalo atgal
    }
  }

  // 3. Tikriname ar visas kelias sutampa:
  if (pathLen > 0 && pathLen == monitoredNodes[repIdx].quarantinePathLen) {
    if (memcmp(candidatePath, monitoredNodes[repIdx].quarantinePath, pathLen) == 0) {
      return true;
    }
  }

  return false;
}

// Patvirtina veikiantį maršrutą, kai iš kartotuvo gaunamas tikras atsakymas per DIRECT kelią
void confirmRoute(size_t repIdx) {
  if (repIdx < monitoredRepeatersCount && monitoredNodes[repIdx].hasRoute) {
    monitoredNodes[repIdx].routeConfirmed = true;
    monitoredNodes[repIdx].quarantinePathLen = 0;
    monitoredNodes[repIdx].quarantineZeroHop = false;
  }
}

// Antraštę parenkame pagal šio bandymo maršrutą: DIRECT be scope arba FLOOD.
// Jei numatytasis scope įjungtas, FLOOD bandymui naudojame TRANSPORT_FLOOD.
uint8_t getRepeaterRouteHeader(uint8_t payloadType, bool flood) {
  uint8_t rType = MeshCorePacket::repeaterRouteType(!flood, activeConfig.scopeEnabled);
  return ((payloadType & 0x0F) << 2) | rType;
}

uint8_t getRepeaterRoutePathLen(size_t repIdx) {
  if (repIdx < monitoredRepeatersCount && monitoredNodes[repIdx].hasRoute) {
    uint8_t hops = monitoredNodes[repIdx].routeHopCount;
    uint8_t hashSize = monitoredNodes[repIdx].routeHashSize;
    if (hashSize < 1) hashSize = 1;
    if (hashSize > 3) hashSize = 2;
    return (uint8_t)(((hashSize - 1) << 6) | (hops & 0x3F));
  }
  return getPathLengthByte(0);
}

const uint8_t* getRepeaterRoutePath(size_t repIdx) {
  if (repIdx < monitoredRepeatersCount && monitoredNodes[repIdx].hasRoute && monitoredNodes[repIdx].routeHopCount > 0) {
    return monitoredNodes[repIdx].routePath;
  }
  return nullptr;
}

// ================================================================================
// 🌐 MARŠRUTIZAVIMO IR SCOPE PAGALBINĖS FUNKCIJOS
// ================================================================================

// Apskaičiuoja oficialų MeshCore path_length_byte pagal nustatytą PATH_HASH_BITS:
// Bits 7:6 = (hash_size_bytes - 1): 00 -> 1B (8b), 01 -> 2B (16b), 10 -> 3B (24b)
// Bits 5:0 = šuolių skaičius (hops: 0..63)
uint8_t getPathLengthByte(uint8_t hops) {
  uint8_t hashSize = 1;
  if (activeConfig.pathBits == 16) hashSize = 2;
  else if (activeConfig.pathBits == 24) hashSize = 3;
  return ((hashSize - 1) << 6) | (hops & 0x3F);
}

// ================================================================================
// 📤 MESHCORE PAKETŲ SIUNTIMAS
// ================================================================================

SendResult sendMeshCoreRaw(uint8_t header, uint8_t pathLen, const uint8_t* path, const uint8_t* payload, size_t payloadLen,
                     const char* targetName, const char* targetPubKey, const uint8_t* sharedSecret) {
  if (telemetryPaused) return SendResult::Paused;
  uint8_t packet[256];
  size_t idx = MeshCorePacket::encode(packet, sizeof(packet), header, pathLen, path, payload, payloadLen,
                                       {activeConfig.scope.c_str(), static_cast<uint16_t>(SCOPE_CODE_1),
                                       static_cast<uint16_t>(SCOPE_CODE_2)});
  uint8_t rType = header & 0x03;
  if (!idx) {
    Serial.println(MeshCorePacket::hasTransportCodes(rType)
      ? "[LoRa TX KLAIDA] Nepavyko koduoti paketo su scope: patikrinkite paketo dydį, SCOPE_NAME ir transporto kodus."
      : "[LoRa TX KLAIDA] Nepavyko paruošti paketo.");
    return SendResult::EncodeFailed;
  }

  uint8_t pType = (header >> 2) & 0x0F;

  Serial.printf("[LoRa TX] Siunčiamas paketas (%u baitai) | Header: 0x%02X (Tipas: %u, Maršrutas: %u",
                (unsigned int)idx, header, pType, rType);
  if (MeshCorePacket::hasTransportCodes(rType)) {
    Serial.printf(", Scope: 0x%02X%02X:0x%02X%02X", packet[2], packet[1], packet[4], packet[3]);
  }
  Serial.printf(", PathLenByte: 0x%02X) | RAW HEX: ", pathLen);
  printHexBuffer(packet, idx);
  Serial.println();

  // Po TX bandome atnaujinti priėmimą net jei siuntimas nepavyko.
  receivedFlag = false;
  digitalWrite(PIN_LORA_RXEN, LOW);
  int txState = radio.transmit(packet, idx);
  digitalWrite(PIN_LORA_RXEN, HIGH);
  receivedFlag = false; // Prieš įjungdami RX išvalome TXDone nustatytą programinę vėliavėlę.
  int rxState = radio.startReceive();

  if (rxState != RADIOLIB_ERR_NONE) {
    Serial.printf("[LoRa RX KLAIDA] Nepavyko atnaujinti priėmimo po TX, klaidos kodas: %d\n", rxState);
  }
  if (txState != RADIOLIB_ERR_NONE) {
    Serial.printf("[LoRa TX KLAIDA] Nepavyko išsiųsti, klaidos kodas: %d\n", txState);
    return SendResult::TransmitFailed;
  }
  Serial.println("[LoRa TX] Paketas sėkmingai ištransliuotas į eterį.");

  // Į API eilę perduodame tik išsiųstus paketus, net jei po TX nepavyko atnaujinti RX.
  #if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument rawTxDoc;
  #else
  StaticJsonDocument<1024> rawTxDoc;
  #endif

  rawTxDoc["direction"] = "TX";
  rawTxDoc["bot_id"] = activeConfig.botId;
  time_t now = time(nullptr);
  rawTxDoc["timestamp"] = (now >= 1700000000) ? (uint32_t)now : lastTxTimestamp;
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
  return rxState == RADIOLIB_ERR_NONE ? SendResult::ReadyToWait : SendResult::ReceiveFailed;
}

// 1. Siunčiama užšifruota prisijungimo (Login) užklausa su slaptažodžiu
SendResult sendMeshCoreLogin(size_t repIdx, bool flood) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  String password = rep.password;
  password.trim();
  if (password.length() == 0) password = "hello";
  size_t pwdLen = password.length();
  if (pwdLen > MAX_REPEATER_LOGIN_PASSWORD_BYTES) {
    Serial.printf("[Login KLAIDA] '%s': kartotuvo slaptažodis negali būti ilgesnis nei %u baitų.\n",
                  rep.name.c_str(), static_cast<unsigned>(MAX_REPEATER_LOGIN_PASSWORD_BYTES));
    return SendResult::InvalidPassword;
  }
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t header = getRepeaterRouteHeader(PAYLOAD_TYPE_ANON_REQ, flood);
  uint8_t pathLen = flood ? getPathLengthByte(0) : getRepeaterRoutePathLen(repIdx);
  const uint8_t* path = flood ? nullptr : getRepeaterRoutePath(repIdx);

  uint8_t payload[128];
  size_t pLen = 0;

  // 1. Tikslinio kartotuvo hash
  payload[pLen++] = target;

  // 2. Mūsų 32 baitų Ed25519 viešasis raktas
  memcpy(&payload[pLen], myPublicKey, PUB_KEY_SIZE);
  pLen += PUB_KEY_SIZE;

  // 3. Paruošiame šifruojamus duomenis (Plaintext)
  uint8_t plain[sizeof(uint32_t) + MAX_REPEATER_LOGIN_PASSWORD_BYTES + 1];
  size_t plainLen = 0;

  uint32_t ts = getNextTimestamp();
  memcpy(&plain[plainLen], &ts, 4);
  plainLen += 4;

  memcpy(&plain[plainLen], password.c_str(), pwdLen + 1);
  plainLen += (pwdLen + 1);

  int encLen = MeshCrypto::encryptThenMAC(secret, &payload[pLen], plain, plainLen);
  pLen += encLen;

  return sendMeshCoreRaw(header, pathLen, path, payload, pLen, rep.name.c_str(), rep.public_id.c_str(), secret);
}

// Išorinis gauto FLOOD paketo kelias tampa kartotuvo grįžimo keliu.
SendResult sendMeshCorePath(const ReciprocalPath& path) {
  const size_t repIdx = path.peer;
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t header = ((PAYLOAD_TYPE_PATH & 0x0F) << 2) | ROUTE_TYPE_DIRECT; // 0x22

  uint8_t plain[MeshCorePacket::MAX_PATH_BYTES + 6];
  const size_t returnBytes = MeshCorePacket::pathByteCount(path.returnLen);
  plain[0] = path.returnLen;
  memcpy(plain + 1, path.returnPath, returnBytes);
  plain[1 + returnBytes] = 0xFF; // Nėra įdėto atsakymo.
  esp_fill_random(plain + 2 + returnBytes, 4);

  // Du mazgų hash baitai, MAC ir AES iki pilno bloko papildyti duomenys.
  uint8_t payload[2 + CIPHER_MAC_SIZE + ((sizeof(plain) + 15) / 16) * 16] = {target, myNodeHash};
  int encLen = MeshCrypto::encryptThenMAC(secret, payload + 2, plain, returnBytes + 6);
  return sendMeshCoreRaw(header, path.outboundLen, path.outbound, payload, 2 + encLen,
                         rep.name.c_str(), rep.public_id.c_str(), secret);
}

// 3. Siunčiama užšifruota būsenos ir statistikos užklausa (REQ_TYPE_GET_STATUS = 0x01)
SendResult sendMeshCoreStatusRequest(size_t repIdx, bool flood) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t header = getRepeaterRouteHeader(PAYLOAD_TYPE_REQ, flood);
  uint8_t pathLen = flood ? getPathLengthByte(0) : getRepeaterRoutePathLen(repIdx);
  const uint8_t* path = flood ? nullptr : getRepeaterRoutePath(repIdx);

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

  return sendMeshCoreRaw(header, pathLen, path, payload, pLen, rep.name.c_str(), rep.public_id.c_str(), secret);
}

// 4. Siunčiama užšifruota jutiklių telemetrijos užklausa (REQ_TYPE_GET_TELEMETRY_DATA = 0x03)
SendResult sendMeshCoreTelemetryRequest(size_t repIdx, bool flood) {
  const RepeaterTarget& rep = monitoredRepeaters[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t header = getRepeaterRouteHeader(PAYLOAD_TYPE_REQ, flood);
  uint8_t pathLen = flood ? getPathLengthByte(0) : getRepeaterRoutePathLen(repIdx);
  const uint8_t* path = flood ? nullptr : getRepeaterRoutePath(repIdx);

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

  return sendMeshCoreRaw(header, pathLen, path, payload, pLen, rep.name.c_str(), rep.public_id.c_str(), secret);
}

// ================================================================================
// 🛡️ LORA RX DUBLIKATŲ / AIDŲ FILTRAVIMAS (DEDUPLICATION)
// ================================================================================
struct RxDedupEntry {
  uint32_t hash;
  uint8_t payloadType;
  uint32_t timestampMs;
};

static const size_t RX_DEDUP_CACHE_SIZE = 32;
struct RxDedupCache {
  RxDedupEntry entries[RX_DEDUP_CACHE_SIZE];
  size_t head;
};
static RxDedupCache rxDedupCache, reciprocalDedupCache;
static bool isDuplicateRxPayload(RxDedupCache& cache, uint8_t payloadType, const uint8_t* payload, size_t payloadLen);

// FNV-1a greitas hešas paketo kroviniui
static uint32_t calcPayloadHash(const uint8_t* data, size_t len) {
  uint32_t hash = 2166136261UL;
  for (size_t i = 0; i < len; i++) {
    hash ^= data[i];
    hash *= 16777619UL;
  }
  return hash;
}

// Tikrina, ar toks paketo krovinys jau buvo gautas per pastarąsias 20 sekundžių
static bool isDuplicateRxPayload(RxDedupCache& cache, uint8_t payloadType, const uint8_t* payload, size_t payloadLen) {
  if (payload == nullptr || payloadLen == 0) return false;

  uint32_t hash = calcPayloadHash(payload, payloadLen);
  uint32_t nowMs = millis();
  const uint32_t DEDUP_WINDOW_MS = 20000; // 20 sekundžių apsaugos langas

  for (const auto& entry : cache.entries) {
    if (entry.hash == hash && entry.payloadType == payloadType) {
      if ((nowMs - entry.timestampMs) < DEDUP_WINDOW_MS) {
        return true; // Dublikatas rastas!
      }
    }
  }

  // Įrašome naują įrašą į žiedinį buferį
  cache.entries[cache.head] = {hash, payloadType, nowMs};
  cache.head = (cache.head + 1) % RX_DEDUP_CACHE_SIZE;

  return false;
}

// ================================================================================
// 📥 GAUTŲ PAKETŲ APDOROJIMAS IR ATSAKYMŲ PARSINIMAS
// ================================================================================
ReceivedPacket serviceReceivedPacket(uint8_t expectedSenderHash, WaitTarget waitTarget) {
  receivedFlag = false;
  ReciprocalPath reciprocal;
  const bool accepted = processIncomingPacket(expectedSenderHash, waitTarget, reciprocal);
  if (reciprocal.peer >= 0) {
    // Paketo ir iššifruoti buferiai galioja iki kito RX apdorojimo.
    const SendResult sent = sendMeshCorePath(reciprocal);
    if (sent != SendResult::Paused && sent != SendResult::EncodeFailed) {
      return {accepted, sent == SendResult::ReadyToWait}; // TX jau bandė atnaujinti RX.
    }
  }
  int rxState = radio.startReceive();
  if (rxState != RADIOLIB_ERR_NONE) {
    Serial.printf("[LoRa RX KLAIDA] Nepavyko atnaujinti priėmimo, klaidos kodas: %d\n", rxState);
  }
  return {accepted, reciprocal.peer < 0 && rxState == RADIOLIB_ERR_NONE};
}

bool processIncomingPacket(uint8_t expectedSenderHash, WaitTarget waitTarget, ReciprocalPath& reciprocal) {
  int length = radio.getPacketLength();
  static uint8_t packetBuffer[256];
  if (length < 2 || (size_t)length > sizeof(packetBuffer)) return false;
  int state = radio.readData(packetBuffer, length);
  if (state != RADIOLIB_ERR_NONE) return false;

  float rssi = radio.getRSSI();
  float snr  = radio.getSNR();

  uint8_t header = packetBuffer[0];
  uint8_t version = (header >> 6) & 0x03;
  uint8_t payloadType = (header >> 2) & 0x0F;
  uint8_t routeType = header & 0x03;
  const bool isFloodRx = routeType == ROUTE_TYPE_FLOOD || routeType == ROUTE_TYPE_TRANSPORT_FLOOD;

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
  if (MeshCorePacket::hasTransportCodes(routeType)) {
    if ((size_t)length < offset + 4) return false;
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
  size_t pathByteLength = MeshCorePacket::pathByteCount(pathLenByte);

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
  const bool validPathFrame = payloadType == PAYLOAD_TYPE_PATH && version == 0 && !useLegacy &&
                              MeshCorePacket::validPathLength(pathLenByte) &&
                              (isFloodRx || pathHopCount == 0) && payloadLen <= MeshCorePacket::MAX_PAYLOAD_BYTES;

  // --------------------------------------------------------------------------
  // 1. FILTRUOJAME MŪSŲ PAČIŲ AIDĄ IR SVETIMAS UŽKLAUSAS IŠ ETERIO:
  // --------------------------------------------------------------------------
  // A) Jei src_hash == myNodeHash: tai yra mūsų pačių ką tik išsiųstas paketas,
  //    kurį kartotuvas retransliavo atgal į eterį. Mes jį jau užregistravome kaip TX.
  if (payloadLen >= 2 && payload[1] == myNodeHash) {
    Serial.println("\n--------------------------------------------------------");
    Serial.printf("[LoRa RX] Pagautas mūsų pačių mazgo (0x%02X) paketo aidas iš kartotuvo. Ignoruojama.\n", myNodeHash);
    return false;
  }

  // B) Jei paketo tipas yra REQ arba ANON_REQ: tai yra užklausa (mūsų arba kito mazgo),
  //    o ne kartotuvo atsakas mums. Tokių paketų nereikia siųsti į API kaip RX.
  if (payloadType == PAYLOAD_TYPE_REQ || payloadType == PAYLOAD_TYPE_ANON_REQ) {
    Serial.println("\n--------------------------------------------------------");
    Serial.printf("[LoRa RX] Gautas %s paketas (%d baitai) iš eterio (svetima užklausa / aidas). Praleidžiama.\n",
                  typeStr, length);
    return false;
  }

  // C) DUBLIKATŲ / AIDŲ FILTRAVIMAS (Mesh flood pakartojimai per kaimyninius kartotuvus):
  //    Kai tas pats atsakymas retransliuojamas per kelis kartotuvus (pvz., tiesiogiai, per B9DD, per 1BBD),
  //    jo krovinys (payload) yra 100% identiškas. Jei tokį jau gavome per 20s – nebesiunčiame pakartotinai į API!
  //    IŠIMTIS: Jei paketas skirtas MŪSŲ mazgui (dHash == myNodeHash) ir naujas paketas atkeliavo per VIETINĮ kartotuvą
  //    (turi daugiau šuolių ir stiprų RSSI nei silpnas tolimas aidas), leidžiame atnaujinti maršrutą!
  bool isDupe = isDuplicateRxPayload(rxDedupCache, payloadType, payload, payloadLen);
  bool isResponseForUs = ((payloadType == PAYLOAD_TYPE_RESPONSE || payloadType == PAYLOAD_TYPE_PATH) && payloadLen >= 2 && payload[0] == myNodeHash);
  const bool couldReturnPath = validPathFrame && isResponseForUs && isFloodRx;
  const bool skipRepeatedRx = isDupe && (!isResponseForUs || pathHopCount <= 1);

  if (skipRepeatedRx && !couldReturnPath) {
    Serial.println("\n--------------------------------------------------------");
    Serial.printf("[LoRa RX Deduplikacija] Dublikatas iš eterio (%s, %d B). Šis paketas jau priimtas neseniai per kitą kartotuvą. Ignoruojama.\n",
                  typeStr, (int)payloadLen);
    return false;
  }

  // --------------------------------------------------------------------------
  // 2. RAW TRAFIKO SIUNTIMAS Į /api/raw (VISAS PAGAUTAS SRAUTAS BE DEKODAVIMO)
  // --------------------------------------------------------------------------
  #if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument rawRxDoc;
  #else
  StaticJsonDocument<1024> rawRxDoc;
  #endif

  rawRxDoc["direction"] = "RX";
  rawRxDoc["bot_id"] = activeConfig.botId;
  time_t now = time(nullptr);
  rawRxDoc["timestamp"] = (now >= 1700000000) ? (uint32_t)now : lastTxTimestamp;
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
  int matchedIdx = -1;
  bool pathHasLoginOk = false;
  bool pathHasStats = false;
  bool pathHasTelemetry = false;
  bool validInnerPath = false;
  int decryptedLen = 0;

  if ((payloadType == PAYLOAD_TYPE_RESPONSE || payloadType == PAYLOAD_TYPE_PATH) && payloadLen >= 2) {
    uint8_t dHash = payload[0];
    uint8_t sHash = payload[1];

    // 1. Pirmiausia ieškome tikslaus siuntėjo atitikmens pagal sHash:
    for (size_t i = 0; i < monitoredRepeatersCount; i++) {
      if (monitoredNodes[i].initialized && monitoredNodes[i].hash == sHash) {
        matchedIdx = (int)i;
        break;
      }
    }

    // Tik RESPONSE leidžia bandyti laukiamo kartotuvo raktą, jei sHash neatpažintas.
    if (payloadType == PAYLOAD_TYPE_RESPONSE && matchedIdx < 0 && expectedSenderHash != 0 && dHash == myNodeHash) {
      for (size_t i = 0; i < monitoredRepeatersCount; i++) {
        if (monitoredNodes[i].initialized && monitoredNodes[i].hash == expectedSenderHash) {
          matchedIdx = (int)i;
          break;
        }
      }
    }

    if (matchedIdx >= 0) {
      rawRxDoc["sender_name"] = monitoredRepeaters[matchedIdx].name;
      rawRxDoc["sender_public_key"] = monitoredRepeaters[matchedIdx].public_id;
      rawRxDoc["my_public_key"] = MY_PUBKEY_HEX;
      rawRxDoc["is_encrypted"] = true;

      // Bendrą raktą dešifravimui pridedame TIK tada, jei paketas skirtas MŪSŲ mazgui (dHash == myNodeHash).
      // Jei paketas skirtas kitam mazgui (dHash != myNodeHash), kartotuvas šį atsaką šifravo su KITO mazgo raktu.
      if (dHash == myNodeHash) {
        char secHex[65];
        for (int k = 0; k < 32; k++) snprintf(&secHex[k * 2], 3, "%02x", monitoredNodes[matchedIdx].sharedSecret[k]);
        rawRxDoc["shared_secret_hex"] = secHex;

        // Patikriname MAC ir iššifruojame paketą vietoje, kad:
        // 1) Iš PAYLOAD_TYPE_PATH ištrauktume tikrąjį maršrutą ir įdėtą atsakymą (pvz., LOGIN_OK atsakant į FLOOD ANON_REQ!)
        // 2) Neapsigautume dėl ROUTE_TYPE_DIRECT išorinės antraštės (kur tarpiniai kartotuvai nuima savo šuolius iki 0!)
        static uint8_t decData[256];
        uint8_t innerPathHops = 0;
        uint8_t innerPathHashSize = 1;
        size_t innerPathByteLen = 0;
        const uint8_t* innerPathPtr = nullptr;
        bool hasInnerPath = false;

        if (payloadLen > 2 + CIPHER_MAC_SIZE) {
          decryptedLen = MeshCrypto::MACThenDecrypt(
            monitoredNodes[matchedIdx].sharedSecret,
            decData,
            &payload[2],
            (int)(payloadLen - 2)
          );
          if (decryptedLen > 0 && validPathFrame) {
            uint8_t pLenByte = decData[0];
            innerPathHashSize = ((pLenByte >> 6) & 0x03) + 1;
            innerPathHops = pLenByte & 0x3F;
            innerPathByteLen = MeshCorePacket::pathByteCount(pLenByte);
            int k = 1 + (int)innerPathByteLen;
            // A route-only PATH (0xFF) includes a four-byte nonce.
            if (MeshCorePacket::validPathLength(pLenByte) && k < decryptedLen &&
                (decData[k] != 0xFF || decryptedLen - k >= 5)) {
              validInnerPath = true;
              innerPathPtr = &decData[1];
              hasInnerPath = innerPathByteLen <= sizeof(MonitoredNode::routePath);
              // Tik pirmoji tinkama FLOOD kopija inicijuoja atgalinio kelio siuntimą, jei leidžiamas DIRECT.
              if (couldReturnPath && !monitoredNodes[matchedIdx].forceFlood &&
                  !isDuplicateRxPayload(reciprocalDedupCache, payloadType, payload, payloadLen)) {
                reciprocal.peer = matchedIdx;
                reciprocal.outboundLen = pLenByte;
                reciprocal.outbound = innerPathPtr;
                reciprocal.returnLen = pathLenByte;
                reciprocal.returnPath = &packetBuffer[pathOffset];
              }
              uint8_t extraType = decData[k] & 0x0F;
              uint8_t* respPtr = &decData[k + 1];
              int respLen = decryptedLen - (k + 1);
              if (extraType == PAYLOAD_TYPE_RESPONSE && respLen >= 4) {
                if (waitTarget == WAIT_LOGIN || waitTarget == WAIT_ANY) {
                  pathHasLoginOk = respLen >= 5 && respLen < 4 + 44 && respPtr[4] == RESP_SERVER_LOGIN_OK;
                } else {
                  // STATUS ir TELEMETRY atsakymai pakartoja užklausos laiko žymą.
                  uint32_t echoedTimestamp;
                  memcpy(&echoedTimestamp, respPtr, sizeof(echoedTimestamp));
                  if (echoedTimestamp == lastTxTimestamp) {
                    pathHasStats = waitTarget == WAIT_STATUS && respLen >= 4 + 44;
                    pathHasTelemetry = waitTarget == WAIT_TELEMETRY;
                  }
                }
              }
            }
          }
        }
        if (skipRepeatedRx && reciprocal.peer < 0) return false;

        // ------------------------------------------------------------------------
        // 🗺️ UNIVERSALUS DINAMINIS MARŠRUTO IŠMOKYMAS (Reverse Path Learning)
        // SVARBU: Kai paketas atkeliauja per ROUTE_TYPE_DIRECT, kiekvienas tarpinis kartotuvas
        // nuima savo šuolį iš išorinės antraštės, todėl pas mus jis atkeliauja su pathHopCount == 0!
        // Todėl iš išorinės antraštės mokomės TIK tada, kai paketas atkeliavo per FLOOD,
        // arba imame tikrąjį maršrutą iš iššifruoto PAYLOAD_TYPE_PATH vidinio krovinio!
        // ------------------------------------------------------------------------
        if (monitoredNodes[matchedIdx].forceFlood) {
          // Mazgui nustatytas priverstinis FLOOD - nesimokome DIRECT kelio
        } else if (hasInnerPath || (isFloodRx && payloadType != PAYLOAD_TYPE_PATH)) {
          // SVARBU: Jei tai PAYLOAD_TYPE_PATH paketas (net jei atkeliavo per FLOOD 0x21!),
          // jo viduje esantis 'innerPath' yra tikrasis priekinis kelias (Botas -> Kartotuvas),
          // kuriuo mūsų užklausa pasiekė kartotuvą (pvz., B9DD -> 4607), o išorinis FLOOD kelias
          // yra atgalinis (Kartotuvas -> Botas, pvz., 3BBD -> B9DD), kuris gali būti asimetrinis!
          uint8_t learnedHops = hasInnerPath ? innerPathHops : pathHopCount;
          uint8_t learnedHashSize = hasInnerPath ? innerPathHashSize : pathHashSize;

          if (monitoredNodes[matchedIdx].hasRoute && learnedHops > monitoredNodes[matchedIdx].routeHopCount) {
            // Jau turime TRUMPESNĮ maršrutą (pvz., 0 arba 1 šuolio) – neperrašome jo ilgesniais eterio aidais!
          } else if (monitoredNodes[matchedIdx].routeConfirmed && learnedHops >= monitoredNodes[matchedIdx].routeHopCount) {
            // Maršrutas jau patvirtintas sėkmingu dvipusiu ryšiu ir naujas kelias nėra trumpesnis - neperrašome
          } else {
            // Suformuojame kandidatinį kelią (Botas -> Kartotuvas):
            uint8_t candPath[32] = {0};
            size_t candLen = 0;
            if (hasInnerPath) {
              if (innerPathByteLen > 0 && innerPathByteLen <= sizeof(candPath) && innerPathPtr != nullptr) {
                // PAYLOAD_TYPE_PATH vidinis maršrutas jau yra tiesiogine (Botas -> Kartotuvas) tvarka
                memcpy(candPath, innerPathPtr, innerPathByteLen);
                candLen = innerPathByteLen;
              }
            } else if (isFloodRx) {
              if (pathByteLength > 0 && pathByteLength <= sizeof(candPath)) {
                for (uint8_t k = 0; k < pathHopCount; k++) {
                  uint8_t srcHopIdx = pathHopCount - 1 - k;
                  memcpy(&candPath[k * pathHashSize],
                         &packetBuffer[pathOffset + srcHopIdx * pathHashSize],
                         pathHashSize);
                }
                candLen = pathByteLength;
              }
            }

            // Apsauga nuo asimetrinio ryšio (asymmetric fringe link):
            // 1) 0-hop tiesioginis ryšys patikimas TIK esant stipriam signalui (>= -95 dBm)
            // 2) 1-hop arba daugiapakopis: artimiausias kartotuvas turi būti girdimas bent -102 dBm
            bool isAsymmetricFringe = (learnedHops == 0 && (rssi < -95.0f || snr < 0.0f)) ||
                                      (learnedHops >= 1 && (rssi < -102.0f || snr < -5.0f));

            // Apsauga nuo karantine esančių kartotuvų (kurie neatiduoda signalo atgal):
            bool isQuarantined = isPathQuarantined(matchedIdx, learnedHops, candPath, candLen, learnedHashSize);

            if (isQuarantined) {
              Serial.printf("[Maršrutizatorius] Atmetamas kandidatas į '%s' - šis kelias/kartotuvas yra karantine (neseniai nesugrąžino signalo).\n",
                            monitoredRepeaters[matchedIdx].name.c_str());
            } else if (isAsymmetricFringe) {
              Serial.printf("[Maršrutizatorius] Ignoruojamas silpnas %u-hop kelias į '%s' (RSSI: %.1f dBm, SNR: %.1f dB) - asimetrinis ryšys.\n",
                            learnedHops, monitoredRepeaters[matchedIdx].name.c_str(), rssi, snr);
            } else if (learnedHops == 0 && rssi >= -95.0f) {
              monitoredNodes[matchedIdx].hasRoute = true;
              monitoredNodes[matchedIdx].routeHopCount = 0;
              monitoredNodes[matchedIdx].routeHashSize = learnedHashSize;
              monitoredNodes[matchedIdx].routePathLen = 0;
              monitoredNodes[matchedIdx].routeLearnedAt = millis();
              Serial.printf("[Maršrutizatorius] >> IŠMOKTAS KELIAS << Į '%s' (0x%02X) yra TIESIOGINIS ryšys (DIRECT 0 šuolių, RSSI: %.1f dBm)!\n",
                            monitoredRepeaters[matchedIdx].name.c_str(), sHash, rssi);
            } else if (candLen > 0) {
              memcpy(monitoredNodes[matchedIdx].routePath, candPath, candLen);
              monitoredNodes[matchedIdx].hasRoute = true;
              monitoredNodes[matchedIdx].routeHopCount = learnedHops;
              monitoredNodes[matchedIdx].routeHashSize = learnedHashSize;
              monitoredNodes[matchedIdx].routePathLen = candLen;
              monitoredNodes[matchedIdx].routeLearnedAt = millis();

              Serial.printf("[Maršrutizatorius] >> IŠMOKTAS KELIAS << Į '%s' (0x%02X): DIRECT %u šuol. (%u B hash, RSSI: %.1f dBm) -> [",
                            monitoredRepeaters[matchedIdx].name.c_str(), sHash, learnedHops, learnedHashSize, rssi);
              for (size_t k = 0; k < candLen; k++) {
                Serial.printf("%02X", monitoredNodes[matchedIdx].routePath[k]);
              }
              Serial.println("]");
            }
          }
        }
      }
      attachedKey = true;
    }
  }
  if (!attachedKey) {
    rawRxDoc["is_encrypted"] = false;
  }

  sendToAPI(rawRxDoc);

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
  // 4. TIKRINAME AR GAUTAS TIKSLINIS ATSAKYMAS MŪSŲ MAZGUI:
  // --------------------------------------------------------------------------
  if (payloadLen >= 2) {
    uint8_t destHash = payload[0];
    uint8_t srcHash = payload[1];

    if (destHash == myNodeHash && (expectedSenderHash == 0 || srcHash == expectedSenderHash)) {
      if (payloadType == PAYLOAD_TYPE_RESPONSE) {
        if (matchedIdx >= 0 && decryptedLen <= 0) {
          Serial.printf("[LoRa RX] Įspėjimas: RESPONSE paketo MAC patikra nepavyko (galima 1B hash kolizija). Laukiama toliau...\n");
          return false;
        }
        Serial.printf("[LoRa RX] Gautas tikslinis RESPONSE atsakymas mūsų mazgui (Src: 0x%02X -> Dest: 0x%02X). RAW paketas jau perduotas į API eilę.\n",
                      srcHash, destHash);
        if (matchedIdx >= 0) {
          confirmRoute((size_t)matchedIdx);
          markLocalSessionActive(monitoredNodes[matchedIdx].pubKey);
        }
        return true;
      } else if (payloadType == PAYLOAD_TYPE_PATH) {
        if (matchedIdx < 0 || !validInnerPath) {
          Serial.println("[LoRa RX] Nepavyko patvirtinti PATH paketo arba jo vidinio kelio. Laukiama toliau...");
          return false;
        }
        // MeshCore protokole į FLOOD ANON_REQ (Login) kartotuvas atsako PAYLOAD_TYPE_PATH paketu,
        // kurio viduje yra įdėtas RESP_SERVER_LOGIN_OK (arba STATUS / TELEMETRY atsakymas)!
        if ((waitTarget == WAIT_LOGIN || waitTarget == WAIT_ANY) && pathHasLoginOk) {
          Serial.printf("[LoRa RX] ✅ Gautas PATH + LOGIN_OK atsakymas iš kartotuvo (Src: 0x%02X -> Dest: 0x%02X)! Sesija ir maršrutas nustatyti.\n",
                        srcHash, destHash);
          markLocalSessionActive(monitoredNodes[matchedIdx].pubKey);
          return true;
        }
        if (waitTarget == WAIT_STATUS && pathHasStats) {
          Serial.printf("[LoRa RX] ✅ Gautas PATH + STATUS atsakymas iš kartotuvo (Src: 0x%02X -> Dest: 0x%02X)!\n",
                        srcHash, destHash);
          markLocalSessionActive(monitoredNodes[matchedIdx].pubKey);
          return true;
        }
        if (waitTarget == WAIT_TELEMETRY && pathHasTelemetry) {
          Serial.printf("[LoRa RX] ✅ Gautas PATH + TELEMETRY atsakymas iš kartotuvo (Src: 0x%02X -> Dest: 0x%02X)!\n",
                        srcHash, destHash);
          markLocalSessionActive(monitoredNodes[matchedIdx].pubKey);
          return true;
        }

        Serial.printf("[LoRa RX] Gautas PATH (maršruto) paketas iš kartotuvo (Src: 0x%02X -> Dest: 0x%02X). Perduota į API, toliau laukiama duomenų RESPONSE atsakymo...\n",
                      srcHash, destHash);
        return false;
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
  if (!isWebConfigAPActive()) {
    WiFi.mode(WIFI_STA);
  } else {
    WiFi.mode(WIFI_AP_STA);
  }
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
  if (telemetryPaused || !activeConfig.wifiSsid.length()) return;

  if (WiFi.status() != WL_CONNECTED) {
    // Prieš jungiantis nuskaitome ir išvedame pasiekiamų Wi-Fi tinklų sąrašą
    scanWiFiNetworks();

    WiFi.mode(isWebConfigAPActive() ? WIFI_AP_STA : WIFI_STA);
    WiFi.disconnect(false);
    delay(200);

    Serial.printf("\n[Wi-Fi] Jungiamasi prie tinklo '%s'...", activeConfig.wifiSsid.c_str());
    WiFi.setSleep(false); // Užtikrina stabilų ryšį su iPhone Hotspot
    WiFi.begin(activeConfig.wifiSsid.c_str(), activeConfig.wifiPassword.c_str());

    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 30) {
      delay(500);
      Serial.print(".");
      attempts++;
      handleWebConfigPortal();
    }
  }

  if (stationConflictsWithSetupAP()) {
    WiFi.disconnect(false);
    Serial.println("[Wi-Fi] Tinklas persidengia su konfigūravimo tinklu 192.168.4.0/24.");
    return;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[Wi-Fi] Prisijungta sėkmingai!");
    Serial.printf("[Wi-Fi] IP adresas: %s | RSSI: %d dBm\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());

    // Sinchronizuojame realų Unix laiką per NTP su Lietuvos laiko juosta (EET/EEST)
    setenv("TZ", TIMEZONE_STRING, 1);
    tzset();
    configTime(0, 0, "pool.ntp.org", "time.google.com");
    Serial.print("[NTP] Sinchronizuojamas realus laikas (Lietuvos laiko juosta)...");
    int retry = 0;
    while (time(nullptr) < 1700000000UL && ++retry <= 15) {
      delay(200);
      Serial.print(".");
    }
    time_t now = time(nullptr);
    if (now >= 1700000000UL) {
      struct tm timeinfo;
      getLocalTime(&timeinfo);
      char timeBuf[32];
      strftime(timeBuf, sizeof(timeBuf), "%Y-%m-%d %H:%M:%S", &timeinfo);
      Serial.printf("\n[NTP] Laikas sinchronizuotas: %s (Lietuvos laikas, Unix: %lu)\n", timeBuf, (unsigned long)now);
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
      if (xSemaphoreTake(wifiHttpMutex, pdMS_TO_TICKS(5000)) == pdTRUE) {
        if (activeStationConnected()) {
          const char* endpointPath = API_ENDPOINT_RAW;
          String fullUrl = String(API_BASE_URL) + endpointPath;

          WiFiClientSecure client;
          client.setCACertBundle(caBundleStart, caBundleEnd - caBundleStart);
          HTTPClient http;
          if (fullUrl.startsWith("https://") && http.begin(client, fullUrl)) {
            http.addHeader("Content-Type", "application/json");
            if (strlen(API_AUTH_KEY) > 0) http.addHeader("Authorization", API_AUTH_KEY);
            http.setTimeout(2500);

            int httpCode = http.POST((uint8_t*)msg.payload, strlen(msg.payload));
            if (httpCode >= 200 && httpCode < 300) {
              Serial.printf("[API Foninis Siuntėjas] %s -> Kodas: %d (išsiųsta sėkmingai).\n", endpointPath, httpCode);
            } else if (httpCode > 0) {
              Serial.printf("[API Foninis Siuntėjas] %s -> HTTP klaida %d.\n", endpointPath, httpCode);
            } else {
              Serial.printf("[API Foninis Siuntėjas] %s -> Siuntimo klaida: %s\n", endpointPath, http.errorToString(httpCode).c_str());
            }
            http.end();
          } else {
            Serial.println("[API Foninis Siuntėjas] Nepavyko paruošti HTTPS užklausos.");
          }
        }
        xSemaphoreGive(wifiHttpMutex);
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
  if (telemetryPaused || !activeConfig.botId.length() || time(nullptr) < 1700000000UL) return;
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
