# MeshCore LoRa RAW Packet Decoder & PostgreSQL Storage (PHP)

Šis katalogas (`www/`) skirtas priimti visą neapdorotą (RAW) MeshCore LoRa srautą per vienintelį endpointą `/api/raw`, išsaugoti visus įrašus PostgreSQL duomenų bazėje, valdyti jų apdorojimo eilę ir dešifruoti/iškoduoti paketų turinį serveryje.

> **Svarbus pakeitimas:** Atskiri `/api/adverts` ir `/api/telemetry` endpointai panaikinti. Visas ESP32 mazgo girdimas eterio srautas (RX) bei paties mazgo siunčiami paketai (TX) siunčiami tiesiai į `/api/raw` ir pilnai apdorojami serveryje.

---

## 📁 Katalogo Struktūra

```text
www/
├── composer.json               # Composer konfigūracija (PSR-4 autoloading)
├── src/
│   ├── MeshCoreDecoder.php     # Protokolo dekodavimo ir dešifravimo klasė
│   ├── RawPacketRepository.php # PostgreSQL duomenų bazės ir eiliškumo valdymo klasė
│   └── app.php                 # Pagrindinis endpointas (/api/raw)
├── public/
│   └── index.php               # Maršrutizatorius (nukreipia į src/app.php)
├── raw/
│   └── index.php               # Tiesioginis kreipinys į /raw
├── process_queue.php           # CLI / Cron fono darbininkas paketų apdorojimui
├── test_decode.php             # CLI testavimo skriptas su realiais pavyzdžiais
└── README.md                   # Ši dokumentacija
```

---

## 🗄️ Duomenų Bazės Konfigūracija ir Lentelė

- **Duomenų bazė:** `u236217820_telemerty`
- **Vartotojas:** `u236217820_telemerty`
- **Slaptažodis:** `telemerty-A1`
- **Host / Port:** `localhost:5432` (PostgreSQL)

### Lentelės `raw_packets` struktūra:
- `id` (BIGSERIAL PRIMARY KEY)
- `direction` (VARCHAR(16) - 'RX' arba 'TX')
- `raw_hex` (TEXT - pilnas neapdorotas heksadecimalus paketas)
- `length` (INTEGER - paketo dydis baitais)
- `rssi` (NUMERIC(6, 2) - signalo stiprumas)
- `snr` (NUMERIC(6, 2) - signalo/triukšmo santykis)
- `target_name`, `target_public_key`, `sender_name`, `sender_public_key`, `my_public_key`
- `shared_secret_hex` (VARCHAR(128) - 32 baitų Curve25519 bendrasis raktas)
- `is_encrypted` (BOOLEAN - ar paketas šifruotas)
- `payload_json` (JSONB - pilnas gautas POST užklausos turinys)
- `decoded_json` (JSONB - iškoduota telemetrija, skelbimai, statistika)
- `error_message` (TEXT - klaidos tekstas, jei dekodavimas nepavyko)
- `processed` (BOOLEAN DEFAULT false - **pagal nutylėjimą false**)
- `created_at` (TIMESTAMP DEFAULT CURRENT_TIMESTAMP - **įrašo įrašymo data**)
- `processed_at` (TIMESTAMP NULL - **data, kuri įrašoma kai įrašas paimamas procesinimui**)
- `completed_at` (TIMESTAMP NULL - data, kada sėkmingai pasibaigė procesinimas)

---

## ⏱️ Rūšiavimo ir Eiliškumo Taisyklė

Pagal reikalavimą, neapdorotų paketų paėmimas vykdomas griežta tvarka:
1. **Prioritetas įrašams su tuščia apdorojimo data** (`processed_at IS NULL`) – naujai atėję paketai apdorojami pirmi.
2. **Vėliausia apdorojimo data su `processed = false`** (`processed_at DESC, id ASC`) – pakartotiniai ar nebaigti apdoroti įrašai.

SQL užklausa:
```sql
SELECT * FROM raw_packets
WHERE processed = false
ORDER BY 
    CASE WHEN processed_at IS NULL THEN 0 ELSE 1 END ASC,
    processed_at DESC,
    id ASC
LIMIT 1
FOR UPDATE SKIP LOCKED;
```

---

## 💻 Naudojimas PHP kode (`RawPacketRepository`)

```php
require_once __DIR__ . '/vendor/autoload.php';

use MeshCore\MeshCoreDecoder;
use MeshCore\RawPacketRepository;

$repository = new RawPacketRepository();
$decoder = new MeshCoreDecoder();

// 1. Sukurti lentelę, jei dar nėra
$repository->createTableIfNotExists();

// 2. Išsaugoti naują atėjusį RAW įrašą (processed = false, processed_at = NULL)
$recordId = $repository->saveRaw([
    'direction'         => 'RX',
    'raw_hex'           => '0100B9AACC416A329F1A405021F3841D80C68E2F96E6B97148E597B08B5DE913E0C1B8',
    'shared_secret_hex' => '3f8a91c0e2bd1987542da691ef02479e120894567ac83d1059fba023e174b890',
    'rssi'              => -82.0,
    'snr'               => 9.25
]);

// 3. Pasiimti sekantį įrašą procesinimui (jam automatiškai užsideda processed_at = NOW())
$item = $repository->fetchNextForProcessing();

// 4. Iškoduoti ir uždėti processed = true
if ($item) {
    $decoded = $repository->processRecord((int)$item['id'], $decoder);
}
```

---

## ⚙️ Foninis Darbininkas (Worker / Cron)

Serveryje galima periodiškai arba nuolat fone leisti `process_queue.php`:

```bash
# Apdoroti iki 50 paketų partiją (pvz. per cron kas 1 min):
php www/process_queue.php

# Paleisti kaip nuolatinį demoną:
php www/process_queue.php --daemon
```
