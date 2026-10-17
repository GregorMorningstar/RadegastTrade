<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Repositories\Contracts\SyncedDataRepositoryInterface;
use App\Services\Contracts\SyncServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Receiving the tables that the connectors of units send. The unit is known only from the API key.
 */
class SyncService implements SyncServiceInterface
{
    /** Columns that may hold the id of a row in the source table (checked in this order). */
    private const ID_COLUMNS = ['Id', 'ID', 'id'];

    private const MAX_ROW_BYTES = 65535;

    private const MAX_ID_LENGTH = 191;

    public function __construct(private readonly SyncedDataRepositoryInterface $data)
    {
        //
    }

    public function receive(ApiKey $key, string $endpoint, array $payload): array
    {
        // the table of the URL and the table of the data have to be the same one (dbo.Towary is dbo_Towary)
        if (str_replace('.', '_', (string) ($payload['table'] ?? '')) !== $endpoint) {
            throw ValidationException::withMessages(['table' => 'Nazwa tabeli w danych nie zgadza się z adresem.']);
        }

        $rows = [];

        foreach ($payload['rows'] as $index => $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encoded === false || strlen($encoded) > self::MAX_ROW_BYTES) {
                throw ValidationException::withMessages(['rows.'.$index => 'Wiersz jest zbyt duży lub zawiera niepoprawne dane.']);
            }

            $id = $this->externalId($row, $encoded);

            // the same id twice in one request: the later row wins
            $rows[$id] = ['external_id' => $id, 'data' => $row];
        }

        $syncedAt = isset($payload['syncedAtUtc']) ? CarbonImmutable::parse($payload['syncedAtUtc']) : null;

        return ['table' => $endpoint, 'received' => $this->data->upsert($key, $endpoint, array_values($rows), $syncedAt)];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function externalId(array $row, string $encoded): string
    {
        foreach (self::ID_COLUMNS as $column) {
            if (isset($row[$column]) && is_scalar($row[$column]) && (string) $row[$column] !== '' && strlen((string) $row[$column]) <= self::MAX_ID_LENGTH) {
                return (string) $row[$column];
            }
        }

        // a table without an Id column: the row is recognised by its content
        return 'h:'.hash('sha256', $encoded);
    }
}
