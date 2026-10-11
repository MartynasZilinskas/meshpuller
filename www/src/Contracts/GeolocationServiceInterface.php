<?php

namespace MeshCore\Contracts;

interface GeolocationServiceInterface
{
    public function isPointInLithuania(?float $lat, ?float $lon, ?array $settings = null): bool;
    public function filterNodesByGeolocationSettings(array $nodes): array;
    public function filterNodesByTelemetryAgeSettings(array $nodes, ?array $settings = null): array;
    public function getGeolocationFilterStats(): array;
}
