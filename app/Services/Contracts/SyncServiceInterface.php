<?php

namespace App\Services\Contracts;

use App\Models\ApiKey;

interface SyncServiceInterface
{
    /**
     * Saves the table that the connector of a unit sent. The data belongs to the unit (and the organization) of the API
     * key, whatever the data says: nothing in the payload can name another unit. A row is recognised by its "Id" column
     * (or, when the table has none, by a hash of the row); a row sent again is updated, not duplicated.
     *
     * @param  string  $endpoint  the table from the URL (dbo.Towary is dbo_Towary); has to match the "table" of the payload
     * @param  array{table: string, syncedAtUtc?: ?string, rowCount?: ?int, rows: list<array<string, mixed>>}  $payload
     * @return array{table: string, received: int}
     */
    public function receive(ApiKey $key, string $endpoint, array $payload): array;
}
