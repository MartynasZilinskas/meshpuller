<?php

namespace MeshCore\Contracts;

use MeshCore\MeshCoreDecoder;

interface NodeRepositoryInterface
{
    public function upsertNodeFromAdvert(array $advert, ?array $packetMeta = null): bool;
    public function touchNodeSeen(
        string $publicKeyOrNodeId,
        ?string $botId = null,
        ?float $batteryV = null,
        ?int $batteryMv = null,
        ?float $rssi = null,
        ?float $snr = null,
        bool $hasTelemetry = false,
        ?float $temperatureC = null,
        ?string $returnPath = null
    ): bool;
    public function getNodes(?string $type = null, int $limit = 100): array;
    public function getNodeStats(): array;
    public function backfillNodesFromRawPackets(MeshCoreDecoder $decoder): int;
    public function hasAnyNodes(): bool;
    public function updateNodeDetails(string $publicKeyOrNodeId, array $fields): bool;
    public function getFilteredNodes(
        array $filters = [],
        int $limit = 50,
        int $offset = 0,
        ?string $sortBy = null,
        ?string $sortDir = 'DESC'
    ): array;
}
