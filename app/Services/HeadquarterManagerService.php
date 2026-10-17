<?php

namespace App\Services;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\ShopBranch;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Repositories\Contracts\HeadquarterManagerRepositoryInterface;
use App\Services\Contracts\HeadquarterManagerServiceInterface;
use App\Services\Contracts\HeadquarterServiceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigning the managers of a headquarters and the leaders of a branch from the people of the organization.
 * Several managers / leaders are allowed (shift work), but a person works in at most one unit.
 */
class HeadquarterManagerService implements HeadquarterManagerServiceInterface
{
    public function __construct(
        private readonly HeadquarterManagerRepositoryInterface $managers,
        private readonly HeadquarterServiceInterface $headquarters,
    ) {
        //
    }

    public function candidates(User $owner, HeadquarterType $type, int $headquarterId): array
    {
        // 403 for anyone but the organization owner, 404 / 403 for a missing / foreign headquarters
        $headquarter = $this->headquarters->findOwned($owner, $type, $headquarterId);

        return $this->payload(
            $this->managers->managersOf($type, $headquarter->id),
            $this->managers->candidates($headquarter->organization_id, $type),
            $type,
            fn (User $user) => $user->role === $type->ownerRole() && $this->headquarterIdOf($user, $type) === $headquarter->id,
            'siedziby',
        );
    }

    public function assign(User $owner, HeadquarterType $type, int $headquarterId, int $userId): void
    {
        $headquarter = $this->headquarters->findOwned($owner, $type, $headquarterId);

        DB::transaction(function () use ($headquarter, $type, $userId) {
            $user = $this->candidate($headquarter->organization_id, $type, $userId);

            if ($user->role === $type->ownerRole() && $this->headquarterIdOf($user, $type) === $headquarter->id) {
                throw ValidationException::withMessages(['user_id' => 'Ta osoba jest już kierownikiem tej siedziby.']);
            }

            $this->ensureNotLastLeader($user, $type);

            $this->managers->addManager($type, $headquarter->id, $user);
        });
    }

    public function remove(User $owner, HeadquarterType $type, int $headquarterId, int $userId): void
    {
        $headquarter = $this->headquarters->findOwned($owner, $type, $headquarterId);

        DB::transaction(function () use ($headquarter, $type, $userId) {
            $user = $this->candidate($headquarter->organization_id, $type, $userId);

            if (! $this->managers->removeManager($type, $headquarter->id, $user)) {
                throw ValidationException::withMessages(['user_id' => 'Ta osoba nie jest kierownikiem tej siedziby.']);
            }
        });
    }

    public function demoteManager(User $owner, HeadquarterType $type, int $headquarterId, int $userId): void
    {
        $headquarter = $this->headquarters->findOwned($owner, $type, $headquarterId);

        DB::transaction(function () use ($headquarter, $type, $userId) {
            $user = $this->candidate($headquarter->organization_id, $type, $userId);

            if (! $this->managers->demoteManagerToWorker($type, $headquarter->id, $user)) {
                throw ValidationException::withMessages(['user_id' => 'Ta osoba nie jest kierownikiem tej siedziby.']);
            }
        });
    }

    public function branchCandidates(User $actor, HeadquarterType $type, int $branchId): array
    {
        $branch = $this->authorizedBranch($actor, $type, $branchId);

        return $this->payload(
            $this->managers->leadersOf($type, $branch->id),
            $this->managers->candidates($branch->organization_id, $type),
            $type,
            fn (User $user) => $user->role === $type->leaderRole() && $user->{$type->branchColumn()} === $branch->id,
            'placówki',
        );
    }

    public function assignBranchLeader(User $actor, HeadquarterType $type, int $branchId, int $userId): void
    {
        $branch = $this->authorizedBranch($actor, $type, $branchId);

        DB::transaction(function () use ($branch, $type, $userId) {
            $user = $this->candidate($branch->organization_id, $type, $userId);

            if ($user->role === $type->leaderRole() && $user->{$type->branchColumn()} === $branch->id) {
                throw ValidationException::withMessages(['user_id' => 'Ta osoba jest już kierownikiem tej placówki.']);
            }

            // moving the only leader of another branch here would leave that branch without a leader
            $this->ensureNotLastLeader($user, $type);

            $this->managers->addLeader($type, $branch, $user);
        });
    }

    public function removeBranchLeader(User $actor, HeadquarterType $type, int $branchId, int $userId): void
    {
        $branch = $this->authorizedBranch($actor, $type, $branchId);

        DB::transaction(function () use ($branch, $type, $userId) {
            $user = $this->candidate($branch->organization_id, $type, $userId);
            $this->ensureNotLastLeader($user, $type, $branch->id);

            if (! $this->managers->removeLeader($type, $branch->id, $user)) {
                throw ValidationException::withMessages(['user_id' => 'Ta osoba nie jest kierownikiem tej placówki.']);
            }
        });
    }

    public function demoteBranchLeader(User $actor, HeadquarterType $type, int $branchId, int $userId): void
    {
        $branch = $this->authorizedBranch($actor, $type, $branchId);

        DB::transaction(function () use ($branch, $type, $userId) {
            $user = $this->candidate($branch->organization_id, $type, $userId);
            $this->ensureNotLastLeader($user, $type, $branch->id);

            if (! $this->managers->demoteLeaderToWorker($type, $branch->id, $user)) {
                throw ValidationException::withMessages(['user_id' => 'Ta osoba nie jest kierownikiem tej placówki.']);
            }
        });
    }

    /**
     * The branch, provided the actor is the owner of its organization or the manager of its headquarters.
     */
    private function authorizedBranch(User $actor, HeadquarterType $type, int $branchId): ShopBranch|WarehouseBranch
    {
        abort_if($actor->organization_id === null, 403);

        // a branch of another organization does not exist for the actor (404, not 403)
        $branch = $this->managers->findBranch($actor->organization_id, $type, $branchId);
        abort_if($branch === null, 404);

        $ownsOrganization = $actor->role === UserRole::ORGANIZATION_OWNER
            && $actor->organization_id === $branch->organization_id;

        $managesHeadquarters = $actor->role === $type->ownerRole()
            && $actor->organization_id === $branch->organization_id
            && $this->headquarterIdOf($actor, $type) === $branch->{$type->headquarterColumn()};

        abort_unless($ownsOrganization || $managesHeadquarters, 403, 'Nie możesz zarządzać tą placówką.');

        return $branch;
    }

    /**
     * A branch always has at least one manager (leader): the only leader of a branch cannot be dismissed, demoted
     * or moved elsewhere until another one is added.
     *
     * @param  int|null  $onlyBranch  check this branch only (null: the branch the user leads, whichever it is)
     */
    private function ensureNotLastLeader(User $user, HeadquarterType $type, ?int $onlyBranch = null): void
    {
        $branchId = $user->{$type->branchColumn()};

        if ($user->role !== $type->leaderRole() || $branchId === null || ($onlyBranch !== null && $branchId !== $onlyBranch)) {
            return;
        }

        if ($this->managers->leadersOf($type, (int) $branchId)->count() <= 1) {
            throw ValidationException::withMessages(['user_id' => 'Placówka musi mieć co najmniej jednego kierownika. To jedyny kierownik placówki, najpierw dodaj drugiego.']);
        }
    }

    private function candidate(int $organizationId, HeadquarterType $type, int $userId): User
    {
        $user = $this->managers->findCandidate($organizationId, $type, $userId);

        if ($user === null) {
            throw ValidationException::withMessages(['user_id' => 'Tej osoby nie można przypisać w tej jednostce.']);
        }

        return $user;
    }

    private function headquarterIdOf(User $user, HeadquarterType $type): ?int
    {
        return $user->{$type->headquarterColumn()};
    }

    /**
     * @param  Collection<int, User>  $current
     * @param  Collection<int, User>  $candidates
     * @param  callable(User): bool  $isCurrent
     * @return array{current: list<array{id: int, name: string, email: string}>, candidates: list<array<string, mixed>>}
     */
    private function payload(Collection $current, Collection $candidates, HeadquarterType $type, callable $isCurrent, string $unit): array
    {
        // how many managers each headquarters and how many leaders each branch has: leaving the last one leaves the unit empty
        $managersPerHeadquarters = $candidates->filter(fn (User $user) => $user->role === $type->ownerRole() && $this->headquarterIdOf($user, $type))
            ->countBy(fn (User $user) => $this->headquarterIdOf($user, $type));
        $leadersPerBranch = $candidates->filter(fn (User $user) => $user->role === $type->leaderRole() && $user->{$type->branchColumn()})
            ->countBy(fn (User $user) => $user->{$type->branchColumn()});

        return [
            'current' => $current->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values()->all(),
            'candidates' => $candidates
                ->map(fn (User $user) => $this->describe($user, $type, $isCurrent($user), $unit, $managersPerHeadquarters, $leadersPerBranch))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int|string, int>  $managersPerHeadquarters
     * @param  Collection<int|string, int>  $leadersPerBranch
     * @return array{id: int, name: string, email: string, role: string, placement: string, current: bool, blocked: bool, warning: ?string}
     */
    private function describe(User $user, HeadquarterType $type, bool $current, string $unit, Collection $managersPerHeadquarters, Collection $leadersPerBranch): array
    {
        $isShop = $type === HeadquarterType::Shop;
        $headquarter = $isShop ? $user->shopHeadquarter : $user->warehouseHeadquarter;
        $branch = $isShop ? $user->shopBranch : $user->warehouseBranch;
        $role = $user->role->label();
        $target = $unit === 'siedziby' ? 'kierownika siedziby' : 'kierownika placówki';

        [$placement, $warning] = match (true) {
            $current => ["Kierownik tej {$unit}", null],
            $user->role === $type->ownerRole() && $headquarter !== null => [
                "Kierownik siedziby „{$headquarter->name}”",
                "Jest kierownikiem siedziby „{$headquarter->name}”. Po przypisaniu opuści tę siedzibę"
                    .(($managersPerHeadquarters[$headquarter->id] ?? 0) <= 1 ? ', a ona zostanie bez kierownika' : '')
                    .($unit === 'placówki' ? ' i zmieni rolę na kierownika placówki.' : '.'),
            ],
            $user->role === $type->ownerRole() => [
                'Kierownik bez siedziby',
                $unit === 'placówki' ? 'Zmieni rolę na kierownika placówki.' : null,
            ],
            $branch !== null => [
                "{$role}, placówka „{$branch->name}”",
                "Pracuje w placówce „{$branch->name}” ({$role}). Po przypisaniu opuści placówkę"
                    .($user->role === $type->leaderRole() && ($leadersPerBranch[$branch->id] ?? 0) <= 1 ? ', a ta zostanie bez kierownika' : '')
                    ."; zmieni też rolę na {$target}.",
            ],
            default => ["{$role}, bez przypisania", "Zmieni rolę na {$target}."],
        };

        // the only leader of a branch cannot be taken away from it: a branch has at least one manager
        $blocked = $user->role === $type->leaderRole() && $branch !== null && ($leadersPerBranch[$branch->id] ?? 0) <= 1 && ! $current;

        if ($blocked) {
            $warning = "Jest jedynym kierownikiem placówki „{$branch->name}”. Placówka musi mieć co najmniej jednego kierownika, więc najpierw dodaj tam drugiego.";
        }

        // a leader added to the branch he/she already leads needs no role change
        if ($unit === 'placówki' && $user->role === $type->leaderRole() && $branch === null) {
            $warning = null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role,
            'placement' => $placement,
            'current' => $current,
            'blocked' => $blocked,
            'warning' => $warning,
        ];
    }
}
