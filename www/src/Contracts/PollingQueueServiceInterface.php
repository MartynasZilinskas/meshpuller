<?php

namespace MeshCore\Contracts;

interface PollingQueueServiceInterface
{
    public function setNodeMonitoring(string $publicKeyOrNodeId, ?string $password = null, ?bool $update = null): bool;
    public function getMonitoredRepeaters(?string $botId = null): array;
    public function syncFailedTelemetryAttempts(int $timeoutSeconds = 180): int;
    public function getNextRepeaterToRequest(?string $botId = null): ?array;
    public function getNodePollingQueue(?string $botId = null, bool $monitoredOnly = true, ?string $search = null): array;
    public function getAvailableBotSources(): array;
    public function resetNodeRequestedDate(string $identifier, bool $resetFailCount = true): bool;
    public function resetNodeFailCount(string $identifier): bool;
    public function resetAllFailCounts(?string $botId = null): int;
    public function resetAllRequestedDates(?string $botId = null, bool $monitoredOnly = true, bool $resetFailCount = false): int;
}
