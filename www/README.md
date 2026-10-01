# MeshCore LoRa RAW Packet Decoder (PHP)

Šis katalogas (`www/`) skirtas serveryje priimti ir dekoduoti neapdorotus (RAW) MeshCore LoRa paketus bei dešifruoti užklausas/atsakymus pagal bendrąjį Curve25519 raktą (`shared_secret_hex`).

---

## 📁 Katalogo Struktūra

```text
www/
├── composer.json            # Composer konfigūracija (PSR-4 autoloading)
├── src/
│   └── MeshCoreDecoder.php  # Pagrindinė dekodavimo ir dešifravimo klasė
├── public/
│   └── index.php            # HTTP Webhook API endpoint'as (/api/raw, /api/adverts, /api/telemetry)
├── test_decode.php          # CLI testavimo skriptas su realiais pavyzdžiais
└── README.md                # Ši dokumentacija
```

---

## 🚀 Reikalavimai ir Įdiegimas Serveryje

### Reikalavimai:
- **PHP >= 7.4** arba **PHP 8.x**
- PHP plėtiniai: `ext-openssl`, `ext-json`, `ext-mbstring`

### Įdiegimas per Composer:
Serveryje, `www/` kataloge įvykdykite:
```bash
composer install
```
Jei papildomų išorinių bibliotekų nereikia, Composer sugeneruos optimizuotą PSR-4 autoloaderį (`vendor/autoload.php`).

---

## 💻 Kaip naudoti klasę PHP kode

### 1. Pagrindinis pavyzdys (Dekodavimas su raktu)

```php
require_once __DIR__ . '/vendor/autoload.php';

use MeshCore\MeshCoreDecoder;

$decoder = new MeshCoreDecoder();

// A) Dekoduojame tiesiogiai iš raw_hex ir shared_secret_hex:
$rawHex = "0100B9AACC416A329F1A405021F3841D80C68E2F96E6B97148E597B08B5DE913E0C1B8";
$sharedSecretHex = "3f8a91c0e2bd1987542da691ef02479e120894567ac83d1059fba023e174b890";

$result = $decoder->decodeRawPacket($rawHex, $sharedSecretHex);

print_r($result);
```

### 2. Užklausos iš `/api/raw` dekodavimas

```php
// B) Dekoduojame visą JSON masyvą, atsiųstą iš ESP32 į /api/raw:
$apiPayload = [
    'direction'         => 'TX',
    'timestamp'         => 1790700050,
    'length'            => 47,
    'raw_hex'           => '0100B9AACC...',
    'target_name'       => 'b9dd',
    'shared_secret_hex' => '3f8a91c0e2bd1987542da691ef02479e120894567ac83d1059fba023e174b890',
    'is_encrypted'      => true
];

$result = $decoder->decodeApiPayload($apiPayload);

if ($result['encryption']['decrypted']) {
    echo "Iššifruota sėkmingai!\n";
    print_r($result['encryption']['parsed_data']);
}
```

---

## 🔍 Ką iškoduoja ši klasė?

1. **MeshCore Antraštę (Header):**
   - Protokolo versiją
   - Paketo tipą (`ADVERT`, `REQ`, `RESPONSE`, `ANON_REQ`, `PATH`, `TXT_MSG`, `ACK`)
   - Maršrutą (`FLOOD`, `DIRECT`, `TRANSPORT_FLOOD`, `TRANSPORT_DIRECT`)
   - Transport kodus (jei taikoma)

2. **Kelio (Path) informaciją:**
   - Šuolių skaičių (`hop_count`)
   - Maršrutizatorių hash sąrašą (`hops: ["B9", "C2"]`)

3. **ADVERT tapatybės skelbimus:**
   - Mazgo viešąjį raktą (`public_key`)
   - Siuntimo laiką (`timestamp`)
   - Tikrąjį vardą (`node_name`), pvz. `"LT-LM Tilžė B9DD"`
   - GPS koordinates (`latitude`, `longitude`)
   - Baterijos įtampą (`voltage_v`, `voltage_mv`)
   - Mazgo vėliavėles (`chat`, `repeater`, `room`)

4. **Šifruotus krovinius (kai pateiktas `shared_secret_hex`):**
   - HMAC-SHA256 parašo patikrą (atmetami neteisingi/sugadinti paketai)
   - AES-128-CBC dešifravimą
   - `LOGIN_REQUEST`: Prisijungimo slaptažodį
   - `LOGIN_SUCCESS`: Prieigos teises
   - `REQ`: Užklausos tipą (`GET_STATUS`, `GET_TELEMETRY_DATA` ir kt.)
   - `REPEATER_STATS`: Visą kartotuvo veikimo statistiką (baterija, eterio laikas, paketai, triukšmo lygis, klaidos)
   - `CAYENNE_LPP_TELEMETRY`: Jutiklių rodmenis (įtampą, temperatūrą, drėgmę, apšviestumą)

---

## 🧪 Kaip išbandyti vietoje (CLI testas)

Serveryje arba kompiuteryje su PHP galite paleisti:
```bash
php www/test_decode.php
```
Šis testas patikrina realų eteryje užfiksuotą ADVERT paketą ir sugeneruotą šifruotą užklausą su raktu.
