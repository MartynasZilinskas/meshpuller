<?php

namespace MeshCore\Contracts;

interface SettingsRepositoryInterface
{
    public function getAllSettings(): array;
    public function getSetting(string $key, $default = null);
    public function saveSettings(array $newSettings): array;
}
