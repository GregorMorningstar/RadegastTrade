<?php

namespace App\Repositories\Contracts;

use App\Models\ApiKey;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The data that the connectors of units send. Every method works on ONE unit, given by the API key it came with (or by
 * the unit reference of a person's own unit): there is no way to ask for the rows of "all units" or of a unit named by the
 * data itself.
 */
interface SyncedDataRepositoryInterface
{
    /**
     * Saves the rows of a table for the unit of the key: a row with the same source id is updated, others are added.
     * The organization and the unit come from the key.
     *
     * @param  list<array{external_id: string, data: array<string, mixed>}>  $rows
     * @return int number of rows saved
     */
    public function upsert(ApiKey $key, string $table, array $rows, ?CarbonInterface $syncedAt): int;

    /**
     * The tables the unit has sent, with the number of rows and the time of the last write.
     *
     * @return Collection<int, object{table_name: string, rows: int, synced_at: ?string}>
     */
    public function tables(int $organizationId, string $unitRef): Collection;

    /**
     * A page of the rows of one table of the unit (by source id).
     *
     * @return LengthAwarePaginator<int, \App\Models\SyncedRow>
     */
    public function rows(int $organizationId, string $unitRef, string $table, int $page, int $perPage): LengthAwarePaginator;
}
