<?php

namespace MeshCore\Contracts;

use MeshCore\MeshCoreDecoder;

interface RawPacketRepositoryInterface
{
    public function saveRaw($data, ?string $sharedSecretHex = null): int;
    public function fetchNextForProcessing(): ?array;
    public function completeProcessing(int $id, array $decodedData, ?string $errorMessage = null): bool;
    public function failProcessing(int $id, string $errorMessage, ?array $partialDecoded = null): bool;
    public function processRecord(int $id, MeshCoreDecoder $decoder): array;
    public function processNext(MeshCoreDecoder $decoder): ?array;
    public function syncErrorsFromDecodedJson(int $limit = 500): int;
    public function updateDecodedJsonWithoutCompleting(int $id, array $data): void;
    public function getStats(): array;
    public function getFilteredRawPackets(
        array $filters = [],
        int $limit = 50,
        int $offset = 0,
        ?string $sortBy = null,
        ?string $sortDir = 'DESC'
    ): array;
    public function getRawPacketDetails(int $id): ?array;
}
