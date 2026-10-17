<?php

namespace App\Services;

use App\Services\Contracts\HeadquarterServiceInterface;
use App\Services\Contracts\NipServiceInterface;
use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\HeadquarterRepositoryInterface;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shop and warehouse headquarters (main locations) in the organization owner panel.
 *
 * Every operation works only on the logged-in owner's organization.
 */
class HeadquarterService implements HeadquarterServiceInterface
{
    public function __construct(
        private readonly HeadquarterRepositoryInterface $shops,
        private readonly HeadquarterRepositoryInterface $warehouses,
        private readonly NipServiceInterface $nipService,
        private readonly OrganizationInvitationRepositoryInterface $invitations,
    ) {
        //
    }

    public function repository(HeadquarterType $type): HeadquarterRepositoryInterface
    {
        return match ($type) {
            HeadquarterType::Shop => $this->shops,
            HeadquarterType::Warehouse => $this->warehouses,
        };
    }

    /**
     * @return Collection<int, ShopHeadquarter|WarehouseHeadquarter>
     */
    public function listFor(User $owner, HeadquarterType $type): Collection
    {
        return $this->repository($type)->forOrganization($this->organizationOf($owner)->id);
    }

    /**
     * The headquarters from the URL, provided it belongs to the logged-in user's organization. A missing one and
     * one of another organization look the same (404), so the existence of foreign units is not revealed.
     */
    public function findOwned(User $owner, HeadquarterType $type, int $id): ShopHeadquarter|WarehouseHeadquarter
    {
        $organization = $this->organizationOf($owner);

        $headquarter = $this->repository($type)->findForOrganization($organization->id, $id);

        abort_if($headquarter === null, 404);

        return $headquarter;
    }

    /**
     * Manager dashboard data: their headquarters and the people assigned to it.
     *
     * @return array{headquarter: array<string, mixed>|null, stats: array{people: int}, people: list<array<string, mixed>>}
     */
    public function dashboardData(User $manager): array
    {
        $headquarter = $manager->shop_headquarter_id ? $manager->shopHeadquarter : $manager->warehouseHeadquarter;

        // an account without an assigned headquarters (e.g. a demo one) sees an empty dashboard
        if ($headquarter === null) {
            return ['headquarter' => null, 'stats' => ['people' => 0], 'people' => []];
        }

        $people = $headquarter->users()->orderBy('name')->get();

        return [
            'headquarter' => [
                'id' => $headquarter->id,
                'name' => $headquarter->name,
                'barcode' => $headquarter->barcode,
                'NIP' => $headquarter->effectiveNip(),
                'organization' => $headquarter->organization->name,
            ],
            'stats' => ['people' => $people->count()],
            'people' => $people->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->label(),
                'last_login' => $user->last_login_local
                    ? $user->last_login_local->format('d.m.Y H:i').' ('.$user->last_login_local->getTimezone()->getName().')'
                    : null,
            ])->values()->all(),
        ];
    }

    /**
     * Headquarters type of the invited manager who has not created it yet; otherwise 403.
     */
    public function pendingSetupType(User $user): HeadquarterType
    {
        $type = HeadquarterType::forRole($user->role);

        abort_if($type === null || ! $user->organization_id, 403);
        abort_if($this->hasHeadquarter($user), 403, 'Masz już przypisaną siedzibę.');
        // only a manager invited to found a headquarters may do it (not one detached from a headquarters)
        abort_unless($this->invitations->hasAcceptedWithoutHeadquarters($user), 403, 'Nie masz zaproszenia do założenia siedziby.');

        return $type;
    }

    public function needsSetup(User $user): bool
    {
        return HeadquarterType::forRole($user->role) !== null
            && $user->organization_id !== null
            && ! $this->hasHeadquarter($user)
            && $this->invitations->hasAcceptedWithoutHeadquarters($user);
    }

    public function hasHeadquarter(User $user): bool
    {
        return (bool) ($user->shop_headquarter_id ?? $user->warehouse_headquarter_id);
    }

    /**
     * Creates the headquarters for the invited manager: organization_id comes from their account,
     * i.e. from the accepted invitation (foreign key), not from the form.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForInvitee(User $user, array $data): ShopHeadquarter|WarehouseHeadquarter
    {
        $type = $this->pendingSetupType($user);

        return DB::transaction(function () use ($user, $type, $data) {
            $headquarter = $this->create($user->organization, $type, $data);

            $user->forceFill([
                $type === HeadquarterType::Shop ? 'shop_headquarter_id' : 'warehouse_headquarter_id' => $headquarter->id,
            ])->save();

            // the invitation that led to the headquarters points to it
            OrganizationInvitation::query()
                ->where('organization_id', $user->organization_id)
                ->where('role', $user->role->value)
                ->where('email', strtolower($user->email))
                ->whereNotNull('accepted_at')
                ->whereNull('shop_headquarter_id')
                ->whereNull('warehouse_headquarter_id')
                ->update([$type === HeadquarterType::Shop ? 'shop_headquarter_id' : 'warehouse_headquarter_id' => $headquarter->id]);

            return $headquarter;
        });
    }

    /**
     * Adds a headquarters to the organization.
     *
     * The NIP may equal the organization's NIP or be different, but a different one must not already exist
     * in the system (neither in an organization nor in any headquarters).
     *
     * @param  array<string, mixed>  $data
     */
    private function create(Organization $organization, HeadquarterType $type, array $data): ShopHeadquarter|WarehouseHeadquarter
    {
        $repository = $this->repository($type);

        $data['name'] = trim($data['name']);

        if ($repository->nameExists($organization->id, $data['name'])) {
            throw ValidationException::withMessages([
                'name' => 'Twoja organizacja ma już siedzibę o tej nazwie.',
            ]);
        }

        $nip = $data['NIP'] ?? null;

        if (filled($nip)) {
            if (! $this->nipService->validateNip($nip)) {
                throw ValidationException::withMessages(['NIP' => 'Nieprawidłowy numer NIP (błędna suma kontrolna).']);
            }

            if ($nip !== $organization->NIP && $repository->nipInUse($nip)) {
                throw ValidationException::withMessages([
                    'NIP' => 'Ten NIP jest już używany przez inną organizację lub siedzibę.',
                ]);
            }
        }

        return $repository->create($organization->id, $data);
    }

    /**
     * The logged-in owner's organization; an account without an organization or with another role has no access.
     */
    public function organizationOf(User $owner): Organization
    {
        abort_unless($owner->role === UserRole::ORGANIZATION_OWNER && $owner->organization_id, 403);

        return $owner->organization;
    }
}
