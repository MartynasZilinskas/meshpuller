<?php

namespace MeshCore\Services;

/**
 * Class ApiCacheService
 *
 * Atsakinga už viešų API endpointų atsakymų saugojimą spartinančiojoje atmintinėje (failų keše).
 * Užtikrina greitą atsakymą į dažnas užklausas be nuolatinio DB apkrovimo pagal nustatytą TTL (minutėmis).
 */
class ApiCacheService
{
    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?: dirname(__DIR__, 2) . '/cache';
        $this->ensureCacheDirExists();
    }

    /**
     * Užtikrina, kad kešo katalogas egzistuotų ir būtų apsaugotas nuo tiesioginės prieigos per naršyklę.
     */
    private function ensureCacheDirExists(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $htaccessFile = $this->cacheDir . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            @file_put_contents($htaccessFile, "# Apsauga: neleisti tiesioginio failų siuntimo per HTTP\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
    }

    /**
     * Suformuoja saugų failo kelią pagal raktą.
     */
    private function getFilePath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.json';
    }

    /**
     * Nuskaito duomenis iš kešo, jei jie nepasenę pagal nurodytą TTL (minutėmis).
     * Jei TTL <= 0 arba failas neegzistuoja / pasenęs, grąžina null.
     */
    public function get(string $key, int $ttlMinutes): ?string
    {
        if ($ttlMinutes <= 0) {
            return null; // Kešavimas išjungtas
        }

        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return null;
        }

        $mtime = @filemtime($filePath);
        if (!$mtime) {
            return null;
        }

        $ageSeconds = time() - $mtime;
        $maxAgeSeconds = $ttlMinutes * 60;

        if ($ageSeconds > $maxAgeSeconds) {
            return null; // Kešas pasenęs
        }

        $content = @file_get_contents($filePath);
        return ($content !== false && $content !== '') ? $content : null;
    }

    /**
     * Grąžina likusį kešo gyvavimo laiką sekundėmis (HTTP Cache-Control max-age antraštei).
     */
    public function getRemainingTtl(string $key, int $ttlMinutes): int
    {
        if ($ttlMinutes <= 0) {
            return 0;
        }

        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return 0;
        }

        $mtime = (int)@filemtime($filePath);
        $ageSeconds = time() - $mtime;
        $maxAgeSeconds = $ttlMinutes * 60;

        return max(0, $maxAgeSeconds - $ageSeconds);
    }

    /**
     * Išsaugo duomenis į kešą.
     */
    public function set(string $key, string $data): bool
    {
        $this->ensureCacheDirExists();
        $filePath = $this->getFilePath($key);
        return @file_put_contents($filePath, $data, LOCK_EX) !== false;
    }

    /**
     * Išvalo visą kešą arba failus pagal šabloną.
     * Grąžina ištrintų failų skaičių.
     */
    public function clear(?string $prefix = null): int
    {
        if (!is_dir($this->cacheDir)) {
            return 0;
        }

        $count = 0;
        $files = glob($this->cacheDir . '/*.json');
        if (!$files) {
            return 0;
        }

        foreach ($files as $file) {
            $basename = basename($file, '.json');
            if ($prefix === null || strpos($basename, $prefix) === 0) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Grąžina kešo statistiką.
     */
    public function getCacheStats(): array
    {
        if (!is_dir($this->cacheDir)) {
            return ['file_count' => 0, 'total_size_bytes' => 0];
        }

        $files = glob($this->cacheDir . '/*.json') ?: [];
        $totalBytes = 0;
        foreach ($files as $f) {
            $totalBytes += @filesize($f) ?: 0;
        }

        return [
            'file_count'       => count($files),
            'total_size_bytes' => $totalBytes,
        ];
    }
}
