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
void connectToWiFi();
void initLoRa();
void generateSelfIdentity();
void requestTelemetryFromAllRepeaters();
void requestTelemetryFromSingleRepeater(size_t repIdx);
bool waitForReply(unsigned long timeoutMs, uint8_t expectedSenderHash = 0);
bool processIncomingPacket(uint8_t expectedSenderHash = 0);
void sendMeshCoreLogin(size_t repIdx);
void sendMeshCorePath(size_t repIdx);
void sendMeshCoreStatusRequest(size_t repIdx);
void sendMeshCoreTelemetryRequest(size_t repIdx);
void sendMeshCoreRaw(uint8_t header, uint8_t pathLen, const uint8_t* path, const uint8_t* payload, size_t payloadLen);
bool hexStringToBytes(const char* hexStr, uint8_t* outBytes, size_t maxLen, size_t &outLen);
void printHexBuffer(const uint8_t* buf, size_t len);

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
  Serial.printf("[Info] Stebimų kartotuvų skaičius: %u\n", (unsigned int)MONITORED_REPEATERS_COUNT);
  for (size_t i = 0; i < MONITORED_REPEATERS_COUNT; i++) {
    Serial.printf("  [%u] %s (%s) | Slaptažodis: '%s'\n",
                  (unsigned int)(i + 1), MONITORED_REPEATERS[i].name,
                  MONITORED_REPEATERS[i].isDirect ? "DIRECT 0 hops" : "FLOOD multi-hop",
                  MONITORED_REPEATERS[i].password);
  }
  Serial.printf("[Info] Laukimo laikas po kiekvienos užklausos: %lu ms (%.1f s)\n",
                REPEATER_REPLY_TIMEOUT_MS, REPEATER_REPLY_TIMEOUT_MS / 1000.0f);
  Serial.printf("[Info] Tikrinimo ciklo intervalas: %lu min (%lu ms)\n",
                TELEMETRY_REQUEST_INTERVAL_MS / 60000, TELEMETRY_REQUEST_INTERVAL_MS);
  Serial.printf("[Info] API URL: %s\n", API_URL);

  // 1. Generuojame unikalų ESP32 mazgo raktą ir kartotuvų bendrus šifravimo raktus
  generateSelfIdentity();

  // 2. Wi-Fi ryšys ir NTP laikas
  connectToWiFi();
  delay(200);

  // 3. SX1262 LoRa radijas
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

  // 3. Wi-Fi ryšio palaikymas
  if (WiFi.status() != WL_CONNECTED) {
    connectToWiFi();
  }

  delay(20);
}

// ================================================================================
// 📡 DUOMENŲ UŽKLAUSŲ CIKLAS (Nuoseklus darbas su kiekvienu kartotuvu paeiliui)
// ================================================================================
void requestTelemetryFromAllRepeaters() {
  Serial.println("\n========================================================");
  Serial.printf("[Ciklas] Pradedama %u kartotuvų apklausa paeiliui...\n", (unsigned int)MONITORED_REPEATERS_COUNT);
  Serial.printf("[Ciklas] Nustatytas laukimo laikas po kiekvienos užklausos: %.1f s\n", REPEATER_REPLY_TIMEOUT_MS / 1000.0f);
  Serial.println("========================================================");

  for (size_t i = 0; i < MONITORED_REPEATERS_COUNT; i++) {
    if (!monitoredNodes[i].initialized) {
      Serial.printf("[Ciklas] Kartotuvas '%s' neinicializuotas (patikrinkite raktą). Praleidžiama.\n", MONITORED_REPEATERS[i].name);
      continue;
    }

    Serial.printf("\n>>> [%u/%u] PRADEDAMAS DARBAS SU: %s (Hash: 0x%02X | %s) <<<\n",
                  (unsigned int)(i + 1), (unsigned int)MONITORED_REPEATERS_COUNT,
                  MONITORED_REPEATERS[i].name, monitoredNodes[i].hash,
                  MONITORED_REPEATERS[i].isDirect ? "Tiesioginis DIRECT" : "Nutolęs FLOOD");

    requestTelemetryFromSingleRepeater(i);

    if (i + 1 < MONITORED_REPEATERS_COUNT) {
      Serial.println("\n[Pauzė] Baigtas darbas su šiuo kartotuvu. Trumpa 3 s pauzė prieš kreipiantis į sekantį...");
      delay(3000);
    }
  }

  // Grįžtame į nuolatinio klausymosi režimą
  radio.startReceive();
  Serial.println("\n[Ciklas] Visi kartotuvai patikrinti!");
}

void requestTelemetryFromSingleRepeater(size_t repIdx) {
  const RepeaterTarget& rep = MONITORED_REPEATERS[repIdx];
  uint8_t targetHash = monitoredNodes[repIdx].hash;

  currentRepDoc.clear();
  currentRepHasData = false;

  // Žingsnis 1: Užšifruotas prisijungimas (Login) su slaptažodžiu
  Serial.printf("[Užklausa 1/4] Siunčiamas užšifruotas Login į '%s' su slaptažodžiu '%s'...\n", rep.name, rep.password);
  sendMeshCoreLogin(repIdx);
  waitForReply(REPEATER_REPLY_TIMEOUT_MS, targetHash);

  // Žingsnis 2: Jei kartotuvas tiesioginis (0 hops), registruojame tiesioginį kelią
  if (rep.isDirect) {
    delay(500);
    Serial.printf("[Užklausa 2/4] Registruojamas tiesioginis kelias į '%s' (PATH 0 hops)...\n", rep.name);
    sendMeshCorePath(repIdx);
    delay(500);
  } else {
    Serial.printf("[Užklausa 2/4] Praleidžiamas tiesioginis PATH registravimas ('%s' yra per kelis hops / FLOOD).\n", rep.name);
  }

  // Žingsnis 3: Būsenos ir statistikos užklausa (REQ_TYPE_GET_STATUS)
  Serial.printf("[Užklausa 3/4] Siunčiama būsenos užklausa (REQ_TYPE_GET_STATUS) į '%s' (Laukiama iki %.1f s)...\n",
                rep.name, REPEATER_REPLY_TIMEOUT_MS / 1000.0f);
  sendMeshCoreStatusRequest(repIdx);
  waitForReply(REPEATER_REPLY_TIMEOUT_MS, targetHash);

  // Žingsnis 4: Telemetrijos užklausa (REQ_TYPE_GET_TELEMETRY_DATA - Baterija ir Temp)
  delay(1000);
  Serial.printf("[Užklausa 4/4] Siunčiama telemetrijos užklausa (REQ_TYPE_GET_TELEMETRY_DATA) į '%s' (Laukiama iki %.1f s)...\n",
                rep.name, REPEATER_REPLY_TIMEOUT_MS / 1000.0f);
  sendMeshCoreTelemetryRequest(repIdx);
  waitForReply(REPEATER_REPLY_TIMEOUT_MS, targetHash);

  // 📡 Konsoliduotas telemetrijos siuntimas į API (viskas vienoje ataskaitoje)
  if (currentRepHasData) {
    currentRepDoc["protocol"] = "MeshCore";
    currentRepDoc["node_id"] = rep.name;
    currentRepDoc["public_id"] = rep.public_id;
    Serial.printf("\n[API] Siunčiama pilna konsoliduota kartotuvo '%s' telemetrija į serverį...\n", rep.name);
    sendToAPI(currentRepDoc);
  } else {
    Serial.printf("\n[API] Iš kartotuvo '%s' negauta telemetrijos duomenų, API siuntimas praleidžiamas.\n", rep.name);
  }
}

// ================================================================================
// ⏳ AKTYVUS ATSAKYMO LAUKIMAS (LAUKIAMA IKI TIMEOUT ARBA IKI TIKSLINIO ATSAKYMO)
// ================================================================================
bool waitForReply(unsigned long timeoutMs, uint8_t expectedSenderHash) {
  unsigned long start = millis();
  int packetsCount = 0;
  bool gotTargetResponse = false;
  Serial.printf("[RX Laukimas] Klausomasi kartotuvo atsakymo (iki %.1f s)...\n", timeoutMs / 1000.0f);

  while (millis() - start < timeoutMs) {
    if (receivedFlag) {
      receivedFlag = false;
      packetsCount++;
      bool valid = processIncomingPacket(expectedSenderHash);
      radio.startReceive(); // Grįžtame į priėmimo režimą tolesniems paketams
      if (valid) {
        gotTargetResponse = true;
        // Trumpa 500 ms pauzė leisti atvykti galimiems papildomiems paketams
        delay(500);
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
  // 1. Užkrauname vartotojo nurodytus ESP32 Ed25519 raktus iš config.h
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
  myNodeHash = myPublicKey[0]; // 0x3B iš 3bbdf77c...

  Serial.println("\n[MeshCore Kriptografija - Mūsų Tapatybė]");
  Serial.printf("  * Mūsų ESP32 Mazgo Hash: 0x%02X\n", myNodeHash);
  Serial.print("  * Mūsų Ed25519 Viešasis: ");
  printHexBuffer(myPublicKey, PUB_KEY_SIZE);
  Serial.println();

  // 2. Kiekvienam stebimam kartotuvui sugeneruojame atskirą Curve25519 ECDH Shared Secret
  Serial.println("\n[MeshCore Kriptografija - Kartotuvų Raktai]");
  for (size_t i = 0; i < MONITORED_REPEATERS_COUNT && i < 16; i++) {
    monitoredNodes[i].initialized = false;
    size_t keyLen = 0;
    if (hexStringToBytes(MONITORED_REPEATERS[i].public_id, monitoredNodes[i].pubKey, PUB_KEY_SIZE, keyLen) && keyLen == PUB_KEY_SIZE) {
      monitoredNodes[i].hash = monitoredNodes[i].pubKey[0];
      MeshCrypto::calcSharedSecret(monitoredNodes[i].sharedSecret, monitoredNodes[i].pubKey, myPrivateKey);
      monitoredNodes[i].initialized = true;

      Serial.printf("  * Kartotuvas [%u] '%s': Hash 0x%02X | %s\n",
                    (unsigned int)(i + 1), MONITORED_REPEATERS[i].name,
                    monitoredNodes[i].hash, MONITORED_REPEATERS[i].isDirect ? "DIRECT 0 hops" : "FLOOD multi-hop");
      Serial.print("    - Bendras šifravimo raktas: ");
      printHexBuffer(monitoredNodes[i].sharedSecret, 8);
      Serial.println("...");
    } else {
      Serial.printf("  [KLAIDA] Neteisingas viešasis raktas kartotuvui '%s' config.h faile!\n", MONITORED_REPEATERS[i].name);
    }
  }
}

// ================================================================================
// 📤 MESHCORE PAKETŲ SIUNTIMAS
// ================================================================================

void sendMeshCoreRaw(uint8_t header, uint8_t pathLen, const uint8_t* path, const uint8_t* payload, size_t payloadLen) {
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
  const RepeaterTarget& rep = MONITORED_REPEATERS[repIdx];
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

  size_t pwdLen = strlen(rep.password);
  memcpy(&plain[plainLen], rep.password, pwdLen + 1);
  plainLen += (pwdLen + 1);

  int encLen = MeshCrypto::encryptThenMAC(secret, &payload[pLen], plain, plainLen);
  pLen += encLen;

  sendMeshCoreRaw(header, 0, nullptr, payload, pLen);
}

// 2. Siunčiamas tiesioginio kelio registravimas (PAYLOAD_TYPE_PATH) su 0 apynių
void sendMeshCorePath(size_t repIdx) {
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

  sendMeshCoreRaw(header, 0, nullptr, payload, pLen);
}

// 3. Siunčiama užšifruota būsenos ir statistikos užklausa (REQ_TYPE_GET_STATUS = 0x01)
void sendMeshCoreStatusRequest(size_t repIdx) {
  const RepeaterTarget& rep = MONITORED_REPEATERS[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t rType = rep.isDirect ? ROUTE_TYPE_DIRECT : ROUTE_TYPE_FLOOD;
  uint8_t header = ((PAYLOAD_TYPE_REQ & 0x0F) << 2) | rType;

  uint8_t payload[64];
  size_t pLen = 0;

  payload[pLen++] = target;
  payload[pLen++] = myNodeHash;

  // Šifruojamas krovinys: [timestamp 4B][REQ_TYPE_GET_STATUS 0x01]
  uint8_t plain[16];
  uint32_t ts = getNextTimestamp();
  memcpy(&plain[0], &ts, 4);
  plain[4] = REQ_TYPE_GET_STATUS;

  int encLen = MeshCrypto::encryptThenMAC(secret, &payload[pLen], plain, 5);
  pLen += encLen;

  sendMeshCoreRaw(header, 0, nullptr, payload, pLen);
}

// 4. Siunčiama užšifruota jutiklių telemetrijos užklausa (REQ_TYPE_GET_TELEMETRY_DATA = 0x03)
void sendMeshCoreTelemetryRequest(size_t repIdx) {
  const RepeaterTarget& rep = MONITORED_REPEATERS[repIdx];
  uint8_t target = monitoredNodes[repIdx].hash;
  const uint8_t* secret = monitoredNodes[repIdx].sharedSecret;

  uint8_t rType = rep.isDirect ? ROUTE_TYPE_DIRECT : ROUTE_TYPE_FLOOD;
  uint8_t header = ((PAYLOAD_TYPE_REQ & 0x0F) << 2) | rType;

  uint8_t payload[64];
  size_t pLen = 0;

  payload[pLen++] = target;
  payload[pLen++] = myNodeHash;

  // Šifruojamas krovinys: [timestamp 4B][REQ_TYPE_GET_TELEMETRY_DATA 0x03][perm_mask 0x00]
  uint8_t plain[16];
  uint32_t ts = getNextTimestamp();
  memcpy(&plain[0], &ts, 4);
  plain[4] = REQ_TYPE_GET_TELEMETRY_DATA;
  plain[5] = 0x00; // visos teisės (unmasked)

  int encLen = MeshCrypto::encryptThenMAC(secret, &payload[pLen], plain, 6);
  pLen += encLen;

  sendMeshCoreRaw(header, 0, nullptr, payload, pLen);
}

// ================================================================================
// 📥 GAUTŲ PAKETŲ APDOROJIMAS IR ATSAKYMŲ PARSINIMAS
// ================================================================================
bool processIncomingPacket(uint8_t expectedSenderHash) {
  int length = radio.getPacketLength();
  if (length <= 0) {
    return false; // Tuščias arba klaidingas pertraukties sužadinimas
  }

  uint8_t packetBuffer[256];
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

  uint8_t pathLen = packetBuffer[1];
  size_t payloadOffset = 2 + pathLen;
  if (payloadOffset > (size_t)length) {
    return false;
  }

  uint8_t* payload = &packetBuffer[payloadOffset];
  size_t payloadLen = length - payloadOffset;

  // 1. Filtruojame mūsų pačių arba kito mazgo pakartotus užklausų paketus (REQ arba ANON_REQ)
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

  Serial.printf("[MeshCore Kelias] Ilgis: %u baitai", pathLen);
  if (pathLen > 0 && 2 + pathLen <= (size_t)length) {
    Serial.print(" -> ");
    printHexBuffer(&packetBuffer[2], pathLen);
  }
  Serial.println();

  Serial.printf("[MeshCore Krovinys] Dydis: %u baitai: ", (unsigned int)payloadLen);
  printHexBuffer(payload, payloadLen);
  Serial.println();

  // JSON ruošimas siuntimui į API
#if ARDUINOJSON_VERSION_MAJOR >= 7
  JsonDocument jsonDoc;
#else
  StaticJsonDocument<1024> jsonDoc;
#endif

  jsonDoc["protocol"] = "MeshCore";

  if (settings.includeSignalMetrics) {
    JsonObject signal = jsonDoc["signal"].to<JsonObject>();
    signal["rssi"] = rssi;
    signal["snr"]  = snr;
  }

  JsonObject telemetry = jsonDoc["telemetry"].to<JsonObject>();
  bool hasValidTelemetry = false;

  // --------------------------------------------------------------------------
  // A) ADVERT paketas (Kartotuvas pats skelbia savo buvimą ir telemetriją)
  // --------------------------------------------------------------------------
  if (payloadType == PAYLOAD_TYPE_ADVERT && payloadLen >= 36) {
    Serial.println(">>> [MeshCore SĖKMĖ] Gautas kartotuvo periodinis skelbimas (ADVERT)! <<<");
    char pubKeyHex[65];
    for (int i = 0; i < 32; i++) {
      snprintf(&pubKeyHex[i * 2], 3, "%02x", payload[i]);
    }
    Serial.printf("  * Mazgo Viešasis Raktas: %s\n", pubKeyHex);

    for (size_t i = 0; i < MONITORED_REPEATERS_COUNT; i++) {
      if (strcasecmp(MONITORED_REPEATERS[i].public_id, pubKeyHex) == 0) {
        jsonDoc["node_id"] = MONITORED_REPEATERS[i].name;
        jsonDoc["public_id"] = MONITORED_REPEATERS[i].public_id;
        break;
      }
    }

    if (payloadLen > 100) {
      uint8_t appFlags = payload[100];
      Serial.printf("  * AppData Vėliavėlės: 0x%02X\n", appFlags);
    }
    jsonDoc["advert_received"] = true;
    hasValidTelemetry = true;
  }

  // --------------------------------------------------------------------------
  // B) ŠIFRUOTO ATSAKO DEŠIFRAVIMAS IR APDOROJIMAS (RESPONSE arba PATH)
  // --------------------------------------------------------------------------
  uint8_t decData[256];
  int decLen = 0;

  if ((payloadType == PAYLOAD_TYPE_RESPONSE || payloadType == PAYLOAD_TYPE_PATH) && payloadLen > 2 + CIPHER_MAC_SIZE) {
    uint8_t destHash = payload[0];
    uint8_t srcHash = payload[1];

    if (destHash != myNodeHash) {
      Serial.printf("[MeshCore] Paketas skirtas mazgui 0x%02X (ne mūsų mazgui 0x%02X). Praleidžiama.\n",
                    destHash, myNodeHash);
      return false;
    }

    // Nustatome, kuris kartotuvas atsiuntė šį atsakymą pagal jo Hash
    int repIdx = -1;
    for (size_t i = 0; i < MONITORED_REPEATERS_COUNT; i++) {
      if (monitoredNodes[i].initialized && monitoredNodes[i].hash == srcHash) {
        repIdx = (int)i;
        break;
      }
    }

    const uint8_t* activeSecret = nullptr;
    if (repIdx >= 0) {
      activeSecret = monitoredNodes[repIdx].sharedSecret;
      jsonDoc["node_id"] = MONITORED_REPEATERS[repIdx].name;
      jsonDoc["public_id"] = MONITORED_REPEATERS[repIdx].public_id;
      Serial.printf(">>> [MeshCore] Gautas atsakymas iš kartotuvo '%s' (Hash: 0x%02X) <<<\n",
                    MONITORED_REPEATERS[repIdx].name, srcHash);
    } else {
      for (size_t i = 0; i < MONITORED_REPEATERS_COUNT; i++) {
        if (monitoredNodes[i].initialized) {
          activeSecret = monitoredNodes[i].sharedSecret;
          jsonDoc["node_id"] = MONITORED_REPEATERS[i].name;
          jsonDoc["public_id"] = MONITORED_REPEATERS[i].public_id;
          break;
        }
      }
    }

    if (activeSecret != nullptr) {
      decLen = MeshCrypto::MACThenDecrypt(activeSecret, decData, &payload[2], payloadLen - 2);
    }

    if (decLen > 0) {
      Serial.printf(">>> [MeshCore Kriptografija SĖKMĖ] Paketas sėkmingai iššifruotas! (Dydis: %d baitai) <<<\n", decLen);
      Serial.print("  * Iššifruoti duomenys (HEX): ");
      printHexBuffer(decData, decLen);
      Serial.println();

      uint8_t* respPtr = decData;
      int respLen = decLen;

      // Jei tai PATH paketas: išsipakuojame papildomą atsaką iš PATH krovinio
      if (payloadType == PAYLOAD_TYPE_PATH) {
        uint8_t pLenByte = decData[0];
        uint8_t hSize = (pLenByte >> 6) + 1;
        uint8_t hCount = pLenByte & 63;
        int k = 1 + hSize * hCount;
        if (k < decLen) {
          uint8_t extraType = decData[k++] & 0x0F;
          Serial.printf("  * PATH pakete rastas papildomas krovinys, tipas: 0x%02X\n", extraType);
          respPtr = &decData[k];
          respLen = decLen - k;
        }
      }

      // 1. Tikriname ar tai Prisijungimo (Login) patvirtinimas
      if (respLen >= 5 && respPtr[4] == RESP_SERVER_LOGIN_OK) {
        sessionLoggedIn = true;
        uint8_t perms = (respLen >= 8) ? respPtr[7] : 0;
        Serial.println(">>> [MeshCore SĖKMĖ] Kartotuvas patvirtino prisijungimą! (LOGIN SUCCESS) <<<");
        Serial.printf("[MeshCore] Suteiktos prieigos teisės: 0x%02X\n", perms);
        if (expectedSenderHash != 0) {
          currentRepDoc["login_status"] = "SUCCESS";
          currentRepDoc["permissions"] = perms;
        } else {
          jsonDoc["login_status"] = "SUCCESS";
          jsonDoc["permissions"] = perms;
        }
        hasValidTelemetry = true;
      }

      // 2. Tikriname ar tai RepeaterStats (52 baitų būsenos struktūra)
      if (respLen >= 4 + (int)sizeof(RepeaterStats)) {
        const RepeaterStats* stats = (const RepeaterStats*)&respPtr[4];
        if (stats->batt_milli_volts >= 2000 && stats->batt_milli_volts <= 6000) {
          float battVolts = stats->batt_milli_volts / 1000.0f;
          float snrVal = stats->last_snr / 4.0f;

          Serial.println(">>> [MeshCore SĖKMĖ] Sėkmingai gauta kartotuvo būsena (RepeaterStats)! <<<");
          Serial.printf("  * Baterija:           %u mV (%.2f V)\n", stats->batt_milli_volts, battVolts);
          Serial.printf("  * Veikimo laikas:     %u s (%.1f h)\n", stats->total_up_time_secs, stats->total_up_time_secs / 3600.0f);
          Serial.printf("  * Triukšmo lygis:     %d dBm\n", stats->noise_floor);
          Serial.printf("  * Gauti paketai:      %u (Direct: %u, Flood: %u)\n", stats->n_packets_recv, stats->n_recv_direct, stats->n_recv_flood);
          Serial.printf("  * Išsiųsti paketai:   %u (Direct: %u, Flood: %u)\n", stats->n_packets_sent, stats->n_sent_direct, stats->n_sent_flood);
          Serial.printf("  * Eterio laikas:      %u s\n", stats->total_air_time_secs);
          Serial.printf("  * Paskutinis SNR:     %.2f dB\n", snrVal);
          Serial.printf("  * Paskutinis RSSI:    %d dBm\n", stats->last_rssi);
          Serial.printf("  * Priėmimo klaidos:   %u\n", stats->n_recv_errors);

          JsonObject docTelemetry = (expectedSenderHash != 0) ? currentRepDoc["telemetry"].to<JsonObject>() : jsonDoc["telemetry"].to<JsonObject>();
          JsonObject docSignal    = (expectedSenderHash != 0) ? currentRepDoc["signal"].to<JsonObject>() : jsonDoc["signal"].to<JsonObject>();

          docSignal["rssi"] = rssi;
          docSignal["snr"]  = snr;

          if (settings.includeBattery) {
            JsonObject batt = docTelemetry["battery"].to<JsonObject>();
            batt["voltage_v"] = battVolts;
            batt["voltage_mv"] = stats->batt_milli_volts;
          }

          if (settings.includeUptime) {
            docTelemetry["uptime_seconds"] = stats->total_up_time_secs;
            docTelemetry["uptime_hours"] = stats->total_up_time_secs / 3600.0f;
          }

          if (settings.includePowerMetrics) {
            JsonObject power = docTelemetry["power"].to<JsonObject>();
            power["air_time_secs"] = stats->total_air_time_secs;
            power["packets_recv"]  = stats->n_packets_recv;
            power["packets_sent"]  = stats->n_packets_sent;
            power["noise_floor"]   = stats->noise_floor;
            power["rx_errors"]     = stats->n_recv_errors;
            power["tx_queue"]      = stats->curr_tx_queue_len;
          }
          hasValidTelemetry = true;
          if (expectedSenderHash != 0) currentRepHasData = true;
        }
      } else {
        // 3. Skenuojame CayenneLPP jutiklių telemetriją iš iššifruoto krovinio (TIK jei tai ne RepeaterStats!)
        JsonObject docTelemetry = (expectedSenderHash != 0) ? currentRepDoc["telemetry"].to<JsonObject>() : jsonDoc["telemetry"].to<JsonObject>();
        JsonObject docSignal    = (expectedSenderHash != 0) ? currentRepDoc["signal"].to<JsonObject>() : jsonDoc["signal"].to<JsonObject>();

        docSignal["rssi"] = rssi;
        docSignal["snr"]  = snr;

        for (int offset = 4; offset + 3 <= respLen; offset++) {
          uint8_t type = respPtr[offset + 1];

          // 0x02 arba 0x74 = Analog Input / Voltage (2 baitai, 0.01V)
          if (type == 0x02 || type == 0x74) {
            int16_t rawVal = (int16_t)((respPtr[offset + 2] << 8) | respPtr[offset + 3]);
            float v = rawVal * 0.01f;
            if (v >= 2.0f && v <= 6.0f) {
              uint8_t ch = respPtr[offset];
              Serial.printf(">>> [CayenneLPP TELEMETRIJA] Kanalo %u Baterijos Įtampa: %.2f V (%.0f mV) <<<\n", ch, v, v * 1000.0f);
              JsonObject batt = docTelemetry["battery"].to<JsonObject>();
              batt["voltage_v"] = v;
              batt["voltage_mv"] = (uint16_t)(v * 1000.0f);
              hasValidTelemetry = true;
              if (expectedSenderHash != 0) currentRepHasData = true;
            }
          }

          // 0x67 = Temperatūra (2 baitai, 0.1°C)
          if (type == 0x67) {
            int16_t rawTemp = (int16_t)((respPtr[offset + 2] << 8) | respPtr[offset + 3]);
            float tempC = rawTemp * 0.1f;
            if (tempC >= -20.0f && tempC <= 85.0f) {
              uint8_t ch = respPtr[offset];
              Serial.printf(">>> [CayenneLPP TELEMETRIJA] Kanalo %u MCU Temperatūra: %.1f °C <<<\n", ch, tempC);
              docTelemetry["temperature_c"] = tempC;
              hasValidTelemetry = true;
              if (expectedSenderHash != 0) currentRepHasData = true;
            }
          }
        }
      }
    } else {
      Serial.println("[MeshCore Kriptografija] Įspėjimas: MAC patikra nepavyko arba paketas skirtas kitam mazgui.");
    }
  }

  // Jei esame aktyvioje kartotuvo apklausoje (expectedSenderHash != 0),
  // duomenis kaupiame ir išsiųsime vienu konsoliduotu pranešimu ciklo gale:
  if (expectedSenderHash != 0) {
    return hasValidTelemetry;
  }

  // Jei paketas gautas fone (pvz. ADVERT skelbimas):
  if (hasValidTelemetry) {
    if (settings.includeRawPayload) {
      String rawHex = "";
      for (int i = 0; i < length; i++) {
        char b[3];
        snprintf(b, sizeof(b), "%02X", packetBuffer[i]);
        rawHex += b;
      }
      jsonDoc["raw_hex"] = rawHex;
    }
    sendToAPI(jsonDoc);
    return true;
  }

  return false;
}

// ================================================================================
// 🌐 WI-FI IR HTTP API SIUNTIMAS
// ================================================================================
void connectToWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.printf("[Wi-Fi] Jungiamasi prie tinklo '%s'...", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 25) {
    delay(400);
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

#if ARDUINOJSON_VERSION_MAJOR >= 7
void sendToAPI(const JsonDocument& doc) {
#else
void sendToAPI(const JsonDocument& doc) {
#endif
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[API] Wi-Fi neprisijungtas, praleidžiamas siuntimas.");
    return;
  }

  String jsonString;
  serializeJson(doc, jsonString);

  HTTPClient http;
  http.begin(API_URL);
  http.addHeader("Content-Type", "application/json");

  if (strlen(API_AUTH_KEY) > 0) {
    http.addHeader("Authorization", API_AUTH_KEY);
  }

  Serial.println("\n[API] Siunčiami telemetrijos duomenys į serverį:");
  serializeJsonPretty(doc, Serial);
  Serial.println();

  int httpCode = http.POST(jsonString);

  if (httpCode > 0) {
    Serial.printf("[API] Serverio atsakymo kodas: %d\n", httpCode);
  } else {
    Serial.printf("[API KLAIDA] Nepavyko išsiųsti užklausos: %s\n", http.errorToString(httpCode).c_str());
  }

  http.end();
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
