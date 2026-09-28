#ifndef MESH_CRYPTO_H
#define MESH_CRYPTO_H

#include <stdint.h>
#include <string.h>
#ifdef ARDUINO
#include <Arduino.h>
#endif
#include <mbedtls/aes.h>
#include <mbedtls/md.h>
#if __has_include("src/ed25519/ed_25519.h")
#include "src/ed25519/ed_25519.h"
#elif __has_include("ed25519/ed_25519.h")
#include "ed25519/ed_25519.h"
#else
#include "ed_25519.h"
#endif

#define CIPHER_MAC_SIZE 2
#define CIPHER_KEY_SIZE 16
#define PUB_KEY_SIZE    32
#define PRV_KEY_SIZE    64
#define SEED_SIZE       32

class MeshCrypto {
public:
  // 1. Generuoja Ed25519 raktų porą iš 32 baitų atsitiktinio seed
  static void generateKeypair(uint8_t pubKey[PUB_KEY_SIZE], uint8_t prvKey[PRV_KEY_SIZE], const uint8_t seed[SEED_SIZE]) {
    ed25519_create_keypair(pubKey, prvKey, seed);
  }

  // 2. Apskaičiuoja ECDH Curve25519 bendrą raktą (Shared Secret)
  static void calcSharedSecret(uint8_t sharedSecret[32], const uint8_t otherPubKey[32], const uint8_t myPrvKey[64]) {
    ed25519_key_exchange(sharedSecret, otherPubKey, myPrvKey);
  }

  // 3. AES-128 ECB šifravimas su 16 baitų nulinio papildymo (zero-pad)
  static int encryptAES(const uint8_t sharedSecret[32], uint8_t* dest, const uint8_t* src, int srcLen) {
    mbedtls_aes_context aes;
    mbedtls_aes_init(&aes);
    mbedtls_aes_setkey_enc(&aes, sharedSecret, 128); // 128-bit raktas

    int offset = 0;
    while (srcLen >= 16) {
      mbedtls_aes_crypt_ecb(&aes, MBEDTLS_AES_ENCRYPT, src + offset, dest + offset);
      offset += 16;
      srcLen -= 16;
    }

    if (srcLen > 0) {
      uint8_t tmp[16] = {0};
      memcpy(tmp, src + offset, srcLen);
      mbedtls_aes_crypt_ecb(&aes, MBEDTLS_AES_ENCRYPT, tmp, dest + offset);
      offset += 16;
    }

    mbedtls_aes_free(&aes);
    return offset; // Grąžina užšifruotų baitų kiekį (16 kartotinis)
  }

  // 4. AES-128 ECB dešifravimas
  static int decryptAES(const uint8_t sharedSecret[32], uint8_t* dest, const uint8_t* src, int srcLen) {
    if (srcLen % 16 != 0) return 0;

    mbedtls_aes_context aes;
    mbedtls_aes_init(&aes);
    mbedtls_aes_setkey_dec(&aes, sharedSecret, 128);

    for (int offset = 0; offset < srcLen; offset += 16) {
      mbedtls_aes_crypt_ecb(&aes, MBEDTLS_AES_DECRYPT, src + offset, dest + offset);
    }

    mbedtls_aes_free(&aes);
    return srcLen;
  }

  // 5. MeshCore Encrypt-Then-MAC (AES-128 + 2B HMAC-SHA256)
  static int encryptThenMAC(const uint8_t sharedSecret[32], uint8_t* dest, const uint8_t* src, int srcLen) {
    // Užšifruojame duomenis poslinkiu po MAC (dest + 2)
    int encLen = encryptAES(sharedSecret, dest + CIPHER_MAC_SIZE, src, srcLen);

    // Apskaičiuojame HMAC-SHA256 virš užšifruotų duomenų
    uint8_t hmacOutput[32];
    mbedtls_md_context_t mdCtx;
    mbedtls_md_init(&mdCtx);
    const mbedtls_md_info_t* mdInfo = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);
    mbedtls_md_setup(&mdCtx, mdInfo, 1); // 1 = su HMAC
    mbedtls_md_hmac_starts(&mdCtx, sharedSecret, 32);
    mbedtls_md_hmac_update(&mdCtx, dest + CIPHER_MAC_SIZE, encLen);
    mbedtls_md_hmac_finish(&mdCtx, hmacOutput);
    mbedtls_md_free(&mdCtx);

    // Pirmieji 2 baitai yra MeshCore MAC
    memcpy(dest, hmacOutput, CIPHER_MAC_SIZE);

    return CIPHER_MAC_SIZE + encLen;
  }

  // 6. MeshCore MAC-Then-Decrypt (Patikrina 2B HMAC ir iššifruoja)
  static int MACThenDecrypt(const uint8_t sharedSecret[32], uint8_t* dest, const uint8_t* src, int srcLen) {
    if (srcLen <= CIPHER_MAC_SIZE) return 0;

    int encLen = srcLen - CIPHER_MAC_SIZE;
    if (encLen % 16 != 0) return 0;

    // Apskaičiuojame kontrolinį HMAC
    uint8_t hmacOutput[32];
    mbedtls_md_context_t mdCtx;
    mbedtls_md_init(&mdCtx);
    const mbedtls_md_info_t* mdInfo = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);
    mbedtls_md_setup(&mdCtx, mdInfo, 1);
    mbedtls_md_hmac_starts(&mdCtx, sharedSecret, 32);
    mbedtls_md_hmac_update(&mdCtx, src + CIPHER_MAC_SIZE, encLen);
    mbedtls_md_hmac_finish(&mdCtx, hmacOutput);
    mbedtls_md_free(&mdCtx);

    // Patikriname ar pirmieji 2 baitai sutampa
    if (memcmp(src, hmacOutput, CIPHER_MAC_SIZE) != 0) {
      return 0; // MAC nesutampa, paketas suklastotas arba neteisingas raktas!
    }

    return decryptAES(sharedSecret, dest, src + CIPHER_MAC_SIZE, encLen);
  }
};

#endif // MESH_CRYPTO_H
