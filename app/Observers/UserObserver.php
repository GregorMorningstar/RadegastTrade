<?php

namespace App\Observers;

use App\Models\User;
use App\Repositories\Contracts\UnitMembershipRepositoryInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the unit_memberships table in step with the unit columns and the role of a user, whichever part of the
 * application changes them (invitation acceptance, founding a headquarters or branch, assigning a manager, seeders).
 */
class UserObserver
{
    /** Whether the table exists: false while the migrations run, because an earlier migration creates accounts. */
    private static ?bool $tableExists = null;

    public function __construct(private readonly UnitMembershipRepositoryInterface $memberships)
    {
        //
    }

    public function saved(User $user): void
    {
        self::$tableExists ??= Schema::hasTable('unit_memberships');

        if (! self::$tableExists) {
            // the table is created later and backfills itself from the existing accounts
            self::$tableExists = null;

            return;
        }

        if ($user->wasRecentlyCreated || $user->wasChanged(['role', 'organization_id', 'shop_headquarter_id', 'warehouse_headquarter_id', 'shop_branch_id', 'warehouse_branch_id'])) {
            $this->memberships->syncFromUser($user);
        }
    }
}
