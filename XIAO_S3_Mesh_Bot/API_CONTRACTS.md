# MeshCore Telemetry Bot – API Kontraktai ir Specifikacija

Šiame dokumente pateikiami trys HTTP API endpoint'ai, į kuriuos Seeed Studio XIAO ESP32-S3 mazgas siunčia duomenis:
1. `POST /api/adverts` – Mazgų tapatybės ir būsenos skelbimai (ADVERT).
2. `POST /api/telemetry` – Kartotuvų telemetrijos ir veikimo būsenos duomenys (Telemetry / RepeaterStats).
3. `POST /api/raw` – Visas eteryje užfiksuotas neapdorotas LoRa srautas (RX ir TX) kartu su dešifravimo raktais.

Visi duomenys siunčiami JSON formatu su `Content-Type: application/json` antrašte.
Jei `config.h` faile nurodytas `API_AUTH_KEY`, užklausose pridedama antraštė:
`Authorization: <API_AUTH_KEY>`.

---

## 1. Skelbimų Endpoint'as (`POST /api/adverts`)

### Paskirtis
Priima visus eteryje pagautus **ADVERT** tipo paketus (tiek iš stebimų kartotuvų sąrašo, tiek iš bet kokių kitų eterio mazgų).

### JSON Kontrakto Struktūra

| Laukas | Tipas | Aprašymas |
| :--- | :--- | :--- |
| `protocol` | `string` | Visada `"MeshCore"` |
| `type` | `string` | Visada `"ADVERT"` |
| `advert_format` | `string` | `"A"` (su 64B Ed25519 parašu) arba `"B"` (kompaktiškas be parašo) |
| `public_key` | `string` | Mazgo Ed25519 viešasis raktas (64 hex simboliai / 32 baitai) |
| `public_id` | `string` | Mazgo viešasis raktas (sinonimas `public_key`) |
| `node_id` | `string` | Kartotuvo ID iš sąrašo (pvz. `"b9dd"`) arba trumpas 4 hex simbolių raktas |
| `node_name` | `string` | **Tikrasis mazgo vardas**, transliuojamas eteryje (pvz. `"LT-LM Tilžė B9DD"`) |
| `name` | `string` | Mazgo vardas (sinonimas `node_name`) |
| `in_monitored_list`| `boolean` | `true` – jei mazgas yra stebimų kartotuvų sąraše (`r.json`), `false` – jei bet koks kitas eterio mazgas |
| `timestamp` | `number` | Siuntimo Unix laiko žyma (sekundėmis) |
| `signal` | `object` | Signalo kokybės parametrai |
| `signal.rssi` | `number` | Gautas RSSI lygis dBm (pvz. `-92.0`) |
| `signal.snr` | `number` | Triukšmo ir signalo santykis SNR dB (pvz. `10.25`) |
| `flags` | `object` | Mazgo savybių ir tipo vėliavėlės |
| `flags.raw` | `number` | Neapdorotas appFlags baitas |
| `flags.type` | `number` | `0`=generic, `1`=chat, `2`=repeater, `3`=room |
| `flags.type_str` | `string` | Mazgo tipas tekstu: `"generic"`, `"chat"`, `"repeater"`, `"room"` |
| `flags.chat` | `boolean` | `true`, jei palaiko pokalbius |
| `flags.repeater` | `boolean` | `true`, jei tai kartotuvas (repeater) |
| `flags.room` | `boolean` | `true`, jei tai kambarys/kanalas |
| `flags.hasLocation`| `boolean`| `true`, jei pakete yra GPS koordinatės |
| `flags.hasFeat1` | `boolean` | `true`, jei pakete yra baterijos įtampa |
| `flags.hasName` | `boolean` | `true`, jei pakete nurodytas tekstinis vardas |
| `lat` | `string` | *(Neprivalomas)* Platuma (pvz. `"55.712345"`), pateikiama jei `hasLocation=true` |
| `lon` | `string` | *(Neprivalomas)* Ilguma (pvz. `"21.123456"`), pateikiama jei `hasLocation=true` |
| `battery_volts` | `number` | *(Neprivalomas)* Baterijos įtampa voltais (pvz. `4.18`) |
| `hops` | `number` | Kiek kartotuvų paketas peršoko iki pasiekė mus (0 = tiesioginis) |
| `path` | `array` | Kelio mazgų heksadecimalūs identifikatoriai, pvz. `["B9", "C2"]` |
| `raw_hex` | `string` | Visas neapdorotas LoRa paketas heksadecimaliu formatu |

### Pavyzdys (`POST /api/adverts`)
```json
{
  "protocol": "MeshCore",
  "type": "ADVERT",
  "advert_format": "B",
  "public_key": "b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db",
  "public_id": "b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db",
  "node_id": "b9dd",
  "node_name": "LT-LM Tilžė B9DD",
  "name": "LT-LM Tilžė B9DD",
  "in_monitored_list": true,
  "timestamp": 1790700120,
  "signal": {
    "rssi": -88.5,
    "snr": 9.75
  },
  "flags": {
    "raw": 160,
    "type": 0,
    "type_str": "generic",
    "chat": false,
    "repeater": false,
    "room": false,
    "hasLocation": false,
    "hasFeat1": false,
    "hasFeat2": true,
    "hasName": true
  },
  "hops": 0,
  "raw_hex": "1200B9DDD788B7A59EAB40EAA49A2534FA9E7FC0EF7D920EF4811139998CDDD631DB1E4FBD6A391AB38BB4377CC027E83B53BF9C467A3978D9700C65D74835FF8DD48670498C063FCB81E7827BCB96B1E78E57A08EDFC98E88AC099115F0132475C555FB610D9277FE510312A942014C542D4C4D2054696CC5BEC4972042394444"
}
```

---

## 2. Telemetrijos Endpoint'as (`POST /api/telemetry`)

### Paskirtis
Priima kartotuvo apklausos konsoliduotą telemetrijos ir būsenos ataskaitą (`RepeaterStats` ir `CayenneLPP`). Siunčiama periodinio kartotuvų tikrinimo ciklo metu arba užfiksavus telemetrijos paketą fone.

### JSON Kontrakto Struktūra

| Laukas | Tipas | Aprašymas |
| :--- | :--- | :--- |
| `protocol` | `string` | Visada `"MeshCore"` |
| `node_id` | `string` | Kartotuvo identifikatorius (pvz. `"b9dd"`) |
| `signal` | `object` | Paskutinio atsako radijo signalo metrika |
| `signal.rssi` | `number` | RSSI dBm (pvz. `-82.0`) |
| `signal.snr` | `number` | SNR dB (pvz. `10.5`) |
| `telemetry` | `object` | Jutiklių ir kartotuvo veikimo metrika |
| `telemetry.battery`| `object` | Baterijos rodmenys |
| `telemetry.battery.voltage_v` | `number` | Baterijos įtampa voltais (pvz. `4.12`) |
| `telemetry.battery.voltage_mv`| `number` | Baterijos įtampa milivoltais (pvz. `4120`) |
| `telemetry.temperature_c` | `number` | *(Neprivalomas)* MCU / aplinkos temperatūra °C |
| `telemetry.uptime_seconds` | `number` | Kartotuvo veikimo laikas (uptime) sekundėmis |
| `telemetry.uptime_hours` | `number` | Kartotuvo veikimo laikas valandomis |
| `telemetry.status` | `object` | Kartotuvo srauto ir eterio statistika |
| `telemetry.status.air_time_secs`| `number` | Bendras kartotuvo eterio laikas (sekundėmis) |
| `telemetry.status.packets_recv` | `number` | Gautų paketų skaičius |
| `telemetry.status.packets_sent` | `number` | Išsiųstų paketų skaičius |
| `telemetry.status.noise_floor` | `number` | Eterio triukšmo lygis dBm (pvz. `-104`) |
| `telemetry.status.rx_errors` | `number` | Priėmimo klaidų skaičius |
| `telemetry.status.tx_queue` | `number` | Dabartinis siuntimo eilės ilgis |

### Pavyzdys (`POST /api/telemetry`)
```json
{
  "protocol": "MeshCore",
  "node_id": "b9dd",
  "signal": {
    "rssi": -82.0,
    "snr": 10.5
  },
  "telemetry": {
    "battery": {
      "voltage_v": 4.12,
      "voltage_mv": 4120
    },
    "temperature_c": 21.4,
    "uptime_seconds": 86420,
    "uptime_hours": 24.0,
    "status": {
      "air_time_secs": 1420,
      "packets_recv": 5830,
      "packets_sent": 2190,
      "noise_floor": -104,
      "rx_errors": 12,
      "tx_queue": 0
    }
  }
}
```

---

## 3. RAW Trafiko Endpoint'as (`POST /api/raw`)

### Paskirtis
Priima **VISĄ** eteryje užfiksuotą radijo srautą be jokio dekodavimo:
- **RX** – Kiekvienas radijo bangomis sugautas paketas (ADVERT, REQ, RESPONSE, ANON_REQ, TXT_MSG ir kt.), nefiltruojant ir netikrinant ar jis skirtas mums.
- **TX** – Kiekvienas ESP32 mazgo išsiųstas paketas (Login, Path, Status užklausa, Telemetry užklausa).

Jei paketas yra ESP32 užklausa (TX) arba atsakymas iš žinomo kartotuvo (RX), pakete **automatiškai pridedamas bendras Curve25519 šifravimo raktas (`shared_secret_hex`)**, leidžiantis serveriui savarankiškai dešifruoti ir išanalizuoti krovinius.

### JSON Kontrakto Struktūra

| Laukas | Tipas | Privalomas | Aprašymas |
| :--- | :--- | :--- | :--- |
| `direction` | `string` | **Taip** | `"RX"` (priimtas iš eterio) arba `"TX"` (išsiųstas ESP32) |
| `timestamp` | `number` | **Taip** | Užfiksavimo Unix laiko žyma (sekundėmis) |
| `length` | `number` | **Taip** | Paketo dydis baitais |
| `raw_hex` | `string` | **Taip** | **Tikslus neapdorotas LoRa paketas** heksadecimaliu formatu |
| `rssi` | `number` | Tik RX | Priėmimo signalo galia dBm (pvz. `-89.5`) |
| `snr` | `number` | Tik RX | Priėmimo SNR dB (pvz. `10.2`) |
| `target_name` | `string` | Tik TX | Tikslinio kartotuvo vardas (pvz. `"b9dd"`) |
| `target_public_key` | `string` | Tik TX | Tikslinio kartotuvo viešasis raktas (64 hex simboliai) |
| `sender_name` | `string` | Tik RX | Kartotuvo vardas (jei atpažintas pagal `srcHash` arba `destHash`) |
| `sender_public_key` | `string` | Tik RX | Siuntėjo kartotuvo viešasis raktas (64 hex simboliai) |
| `my_public_key` | `string` | Pasirenkamas | ESP32 mazgo Ed25519 viešasis raktas (64 hex simboliai) |
| `shared_secret_hex` | `string` | Pasirenkamas | **32 baitų (64 hex) ECDH Curve25519 bendras šifravimo raktas** |
| `is_encrypted` | `boolean` | **Taip** | `true`, jei krovinys užšifruotas su `shared_secret_hex`, `false` kitu atveju |

### Pavyzdys 1: ESP32 išsiųsta užklausa (`TX`)
```json
{
  "direction": "TX",
  "timestamp": 1790700145,
  "length": 47,
  "raw_hex": "0100B9AACC416A329F1A405021F3841D80C68E2F96E6B97148E597B08B5DE913E0C1B8",
  "target_name": "b9dd",
  "target_public_key": "b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db",
  "my_public_key": "0bbd84b028bdf755edd8f30c6f67839c3f81846c9f0079f377ea32a1e93410f3",
  "shared_secret_hex": "3f8a91c0e2bd1987542da691ef02479e120894567ac83d1059fba023e174b890",
  "is_encrypted": true
}
```

### Pavyzdys 2: Eteryje priimtas atsakymas (`RX`)
```json
{
  "direction": "RX",
  "timestamp": 1790700146,
  "length": 58,
  "rssi": -84.0,
  "snr": 9.5,
  "raw_hex": "0500AAB95E609148E597B08B5DE913E0C1B875691A0142A981745E609148E597B08B5DE913E0C1B875691A0142A981745E609148E597B08B5DE913E0",
  "sender_name": "b9dd",
  "sender_public_key": "b9ddd788b7a59eab40eaa49a2534fa9e7fc0ef7d920ef4811139998cddd631db",
  "my_public_key": "0bbd84b028bdf755edd8f30c6f67839c3f81846c9f0079f377ea32a1e93410f3",
  "shared_secret_hex": "3f8a91c0e2bd1987542da691ef02479e120894567ac83d1059fba023e174b890",
  "is_encrypted": true
}
```

### Pavyzdys 3: Eteryje priimtas nežinomo mazgo paketas (`RX`)
```json
{
  "direction": "RX",
  "timestamp": 1790700150,
  "length": 24,
  "rssi": -98.0,
  "snr": 3.25,
  "raw_hex": "1100F2913A0EE1D96E657B41BE5BFD825827B97BFC5DAB70",
  "is_encrypted": false
}
```

---

## 4. Kaip dešifruoti `raw_hex` serveryje (Python pavyzdys)

Kai `is_encrypted: true` ir pateiktas `shared_secret_hex`, paketo krovinys dešifruojamas pagal MeshCore standartą:
- **Algoritmas:** AES-128-CBC
- **Autentifikavimas:** HMAC-SHA256 (paskutiniai 4 baitai)
- **Šifro raktas:** Pirmi 16 baitų iš `shared_secret` (`shared_secret[0..15]`)
- **MAC raktas:** Antri 16 baitų iš `shared_secret` (`shared_secret[16..31]`)
- **IV:** Fiksuotas 16 nulių (`b'\x00' * 16`)

```python
import hmac
import hashlib
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend

def decrypt_meshcore_payload(raw_hex: str, shared_secret_hex: str) -> bytes:
    raw = bytes.fromhex(raw_hex)
    secret = bytes.fromhex(shared_secret_hex)

    enc_key = secret[:16]
    mac_key = secret[16:32]

    # 1. Apeiname header ir path:
    header = raw[0]
    route_type = header & 0x03
    offset = 1
    if route_type in (0x00, 0x03):  # Transport flood / direct
        offset += 4
    
    path_len_byte = raw[offset]
    offset += 1
    hash_size = (path_len_byte >> 6) + 1
    hop_count = path_len_byte & 0x3F
    offset += hop_count * hash_size

    # 2. Payload pradžia: [dest_hash, src_hash, ciphertext..., mac (4B)]
    dest_hash = raw[offset]
    src_hash = raw[offset + 1]
    encrypted_block = raw[offset + 2 :]

    if len(encrypted_block) < 4:
        raise ValueError("Paketas per trumpas dešifravimui")

    ciphertext = encrypted_block[:-4]
    received_mac = encrypted_block[-4:]

    # 3. Tikriname HMAC-SHA256
    computed_mac = hmac.new(mac_key, ciphertext, hashlib.sha256).digest()[:4]
    if received_mac != computed_mac:
        raise ValueError("MAC patikra nepavyko! Neteisingas raktas arba pažeistas paketas.")

    # 4. Dešifruojame AES-128-CBC
    iv = b"\x00" * 16
    cipher = Cipher(algorithms.AES(enc_key), modes.CBC(iv), backend=default_backend())
    decryptor = cipher.decryptor()
    padded_plaintext = decryptor.update(ciphertext) + decryptor.finalize()

    return padded_plaintext
```

---

## 5. Serverio Atsako Rekomendacijos
Kadangi ESP32 naudoja neblokuojančią FreeRTOS eilę su 2.5 s HTTP timeout riba:
- Serveris turėtų grąžinti `HTTP 200 OK` arba `HTTP 204 No Content`.
- Visi ilgi apdorojimai (duomenų bazės įrašymas, analizė) turėtų vykti serverio fone, kad HTTP atsakymas būtų grąžinamas greičiau nei per 1 sekundę.
