<?php

namespace App\Repositories;

use App\Models\ApiKey;
use App\Models\SyncedRow;
use App\Repositories\Contracts\SyncedDataRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentSyncedDataRepository implements SyncedDataRepositoryInterface
{
    private const CHUNK = 500;

    public function upsert(ApiKey $key, string $table, array $rows, ?CarbonInterface $syncedAt): int
    {
        if ($rows === []) {
            return 0;
        }

        // the organization and the unit come from the key, never from the data
        $unit = ['organization_id' => $key->organization_id, 'unit_ref' => $key->unitRef()];
        foreach (['shop_headquarter_id', 'warehouse_headquarter_id', 'shop_branch_id', 'warehouse_branch_id'] as $column) {
            $unit[$column] = $key->getAttribute($column);
        }

        $now = now();

        DB::transaction(function () use ($rows, $table, $syncedAt, $unit, $now) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                SyncedRow::query()->upsert(
                    array_map(fn (array $row) => [
                        ...$unit,
                        'table_name' => $table,
                        'external_id' => $row['external_id'],
                        'data' => json_encode($row['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'synced_at' => $syncedAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk),
                    ['unit_ref', 'table_name', 'external_id'],
                    ['data', 'synced_at', 'updated_at'],
                );
            }
        });

        return count($rows);
    }

    public function tables(int $organizationId, string $unitRef): Collection
    {
        return SyncedRow::query()
            ->where('organization_id', $organizationId)
            ->where('unit_ref', $unitRef)
            ->selectRaw('table_name, count(*) as rows, max(updated_at) as synced_at')
            ->groupBy('table_name')
            ->orderBy('table_name')
            ->get()
            ->map(fn ($row) => (object) ['table_name' => $row->table_name, 'rows' => (int) $row->rows, 'synced_at' => $row->synced_at]);
    }

    public function rows(int $organizationId, string $unitRef, string $table, int $page, int $perPage): LengthAwarePaginator
    {
        return SyncedRow::query()
            ->where('organization_id', $organizationId)
            ->where('unit_ref', $unitRef)
            ->where('table_name', $table)
            ->orderBy('external_id')
            ->paginate(min($perPage, 100), ['*'], 'page', $page);
    }
}
