<?php

namespace MeshCore\Contracts;

use MeshCore\MeshCoreDecoder;

interface TelemetryRepositoryInterface
{
    public function extractAndSaveTelemetry(int $rawPacketId, array $decoded, ?array $record = null): ?int;
    public function insertTelemetryRecord(array $data): ?int;
    public function getTelemetryHistory(string $nodeId, ?string $from = null, ?string $to = null, int $limit = 500): array;
    public function getTelemetryStats(): array;
    public function backfillTelemetryFromRawPackets(MeshCoreDecoder $decoder): int;
    public function getRepeatersWithLatestTelemetry(bool $repeatersOnly = true, int $limit = 500): array;
    public function cleanupInvalidTelemetry(): int;
    public function getFilteredTelemetry(
        array $filters = [],
        int $limit = 50,
        int $offset = 0,
        ?string $sortBy = null,
        ?string $sortDir = 'DESC'
    ): array;
    public function getNodeTelemetryHistory(string $nodeIdentifier, ?int $days = null): array;
}
