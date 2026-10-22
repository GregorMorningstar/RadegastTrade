<?php

namespace App\Services;

use App\Services\Contracts\BranchServiceInterface;
use App\Services\Contracts\NipServiceInterface;
use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Services\Contracts\HeadquarterServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Shop and warehouse branches: the headquarters manager invites branch leaders, who then create the branches
 * after accepting the invitation. Foreign key chain: organization → headquarters → branch. The invitation and the branch
 * get the organization and headquarters ids from the logged-in manager's account, never from the form.
 */
class BranchService implements BranchServiceInterface
{
    public function __construct(
        private readonly BranchRepositoryInterface $shops,
        private readonly BranchRepositoryInterface $warehouses,
        private readonly OrganizationInvitationRepositoryInterface $invitations,
        private readonly NipServiceInterface $nipService,
        private readonly HeadquarterServiceInterface $headquarters,
    ) {
        //
    }

    public function repository(HeadquarterType $type): BranchRepositoryInterface
    {
        return match ($type) {
            HeadquarterType::Shop => $this->shops,
            HeadquarterType::Warehouse => $this->warehouses,
        };
    }

    /**
     * Type of branches managed by the headquarters manager; other roles get 403.
     */
    public function typeOfManager(User $manager): HeadquarterType
    {
        $type = HeadquarterType::forRole($manager->role);

        abort_if($type === null, 403);

        return $type;
    }

    /**
     * The manager's headquarters, provided its organization is the one the logged-in user belongs to.
     */
    public function headquarterOf(User $manager): ShopHeadquarter|WarehouseHeadquarter
    {
        $type = $this->typeOfManager($manager);

        abort_unless($manager->organization_id && $manager->{$type->headquarterColumn()}, 403);

        $headquarter = $type === HeadquarterType::Shop ? $manager->shopHeadquarter : $manager->warehouseHeadquarter;

        abort_unless($headquarter !== null && $headquarter->organization_id === $manager->organization_id, 403, 'Ta siedziba należy do innej organizacji.');

        return $headquarter;
    }

    /**
     * @return Collection<int, ShopBranch|WarehouseBranch>
     */
    public function listFor(User $manager): Collection
    {
        return $this->repository($this->typeOfManager($manager))->forHeadquarter($this->headquarterOf($manager)->id);
    }

    /**
     * Pending invitations to create a new branch in the manager's headquarters.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingInvitations(User $manager): Collection
    {
        return $this->invitations->pendingNewBranch($this->headquarterOf($manager), $this->typeOfManager($manager)->leaderRole());
    }

    /**
     * Emails the link and key of the invitation to create a branch; the invitee provides the branch details.
     */
    public function invite(User $manager, string $email, ?string $phone = null): OrganizationInvitation
    {
        return $this->issueForHeadquarter($manager, $this->typeOfManager($manager), $this->headquarterOf($manager), $email, $phone);
    }

    /**
     * The organization owner invites the leader of a new branch in one of the organization's headquarters of the
     * given type (403 for anyone else, for a missing or foreign headquarters).
     */
    public function inviteAsOwner(User $owner, HeadquarterType $type, int $headquarterId, string $email, ?string $phone = null): OrganizationInvitation
    {
        return $this->issueForHeadquarter($owner, $type, $this->headquarters->findOwned($owner, $type, $headquarterId), $email, $phone);
    }

    /**
     * Pending invitations to create a branch anywhere in the owner's organization, for the given type.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingInvitationsForOwner(User $owner, HeadquarterType $type): Collection
    {
        return $this->invitations->pendingNewBranchesOfOrganization($this->headquarters->organizationOf($owner), $type->leaderRole());
    }

    /**
     * The organization owner invites the leader of a shop or a warehouse that is founded straight under the organization,
     * with no main headquarters (the leader provides the details after accepting). Only the owner may do it.
     */
    public function inviteStandaloneAsOwner(User $owner, HeadquarterType $type, string $email, ?string $phone = null): OrganizationInvitation
    {
        $this->headquarters->organizationOf($owner);

        return $this->issueForHeadquarter($owner, $type, null, $email, $phone);
    }

    /**
     * Pending invitations of leaders of standalone branches of the given type.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingStandaloneInvitations(User $owner, HeadquarterType $type): Collection
    {
        return $this->invitations->pendingNewStandaloneBranches($this->headquarters->organizationOf($owner), $type->leaderRole());
    }

    /**
     * The shops or warehouses of the organization that belong to no headquarters.
     *
     * @return Collection<int, ShopBranch|WarehouseBranch>
     */
    public function standaloneBranches(User $owner, HeadquarterType $type): Collection
    {
        return $this->repository($type)->standalone($this->headquarters->organizationOf($owner)->id);
    }

    /**
     * Attaches standalone branches to a headquarters founded later: the branches (and their people and pending invitations)
     * become part of it, so its manager and the tasks and rights that follow the hierarchy cover them. Only the owner may do
     * it, only for the organization's own headquarters and branches without a headquarters, of the same type.
     *
     * @param  array<int, int|string>  $branchIds
     */
    public function attachToHeadquarter(User $owner, HeadquarterType $type, int $headquarterId, array $branchIds): int
    {
        $headquarter = $this->headquarters->findOwned($owner, $type, $headquarterId);
        $ids = array_values(array_unique(array_map('intval', $branchIds)));

        if ($ids === []) {
            throw ValidationException::withMessages(['branches' => 'Wybierz co najmniej jedną placówkę do dołączenia.']);
        }

        $branchModel = $type === HeadquarterType::Shop ? ShopBranch::class : WarehouseBranch::class;

        return DB::transaction(function () use ($owner, $type, $headquarter, $ids, $branchModel) {
            $branches = $branchModel::query()
                ->where('organization_id', $headquarter->organization_id)
                ->whereNull($type->headquarterColumn())
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            // anything that is not a standalone branch of this organization and type is refused, never skipped
            if ($branches->count() !== count($ids)) {
                throw ValidationException::withMessages(['branches' => 'Można dołączyć tylko placówki tej organizacji, które nie należą jeszcze do żadnej siedziby.']);
            }

            // the name of a branch is unique within its headquarters
            $taken = $this->repository($type)->forHeadquarter($headquarter->id)->pluck('name')->map(fn (string $name) => mb_strtolower($name))->all();

            foreach ($branches as $branch) {
                $name = mb_strtolower($branch->name);

                if (in_array($name, $taken, true)) {
                    throw ValidationException::withMessages(['branches' => 'Siedziba ma już placówkę o nazwie „'.$branch->name.'”. Zmień nazwę jednej z nich.']);
                }

                $taken[] = $name;
            }

            $column = $type->headquarterColumn();

            $branchModel::query()->whereIn('id', $ids)->update([$column => $headquarter->id]);

            // the people of the branches (leaders, workers) and the invitations still waiting follow the branch
            User::query()->where('organization_id', $headquarter->organization_id)->whereIn($type->branchColumn(), $ids)->update([$column => $headquarter->id]);
            OrganizationInvitation::query()->where('organization_id', $headquarter->organization_id)->whereIn($type->branchColumn(), $ids)->whereNull($column)->update([$column => $headquarter->id]);

            app(AuditLogger::class)->record($owner, $owner->organization_id, 'branch.attached', $headquarter, ['branches' => $ids]);

            return count($ids);
        });
    }

    /**
     * Sends the invitation to create a branch in the headquarters: the inviter is the headquarters manager or the
     * organization owner, the invitation carries the organization and the headquarters.
     */
    private function issueForHeadquarter(User $manager, HeadquarterType $type, ShopHeadquarter|WarehouseHeadquarter|null $headquarter, string $email, ?string $phone): OrganizationInvitation
    {
        $role = $type->leaderRole();
        $email = strtolower($email);

        if (strtolower($manager->email) === $email) {
            throw ValidationException::withMessages(['email' => 'Nie możesz zaprosić samego siebie.']);
        }

        $existing = User::where('email', $email)->first();

        if ($existing && (
            ! in_array($existing->role, [UserRole::Employee, $role], true)
            || ($existing->organization_id && $existing->organization_id !== $manager->organization_id)
            || $existing->{$type->branchColumn()}
        )) {
            throw ValidationException::withMessages([
                'email' => 'Ten adres należy do konta, które nie może zostać kierownikiem placówki.',
            ]);
        }

        // a new invitation replaces the earlier pending ones of the same address (in the headquarters, or standalone)
        $headquarter === null
            ? $this->invitations->revokePendingNewStandalone($manager->organization, $role, $email, $manager)
            : $this->invitations->revokePendingNewBranch($headquarter, $role, $email, $manager);

        [$invitation, $verificationCode] = $this->invitations->issue(
            $manager->organization, $email, $manager, $role, $headquarter, $phone,
        );

        Notification::route('mail', $email)
            ->notify(new OrganizationInvitationNotification($invitation, $verificationCode));

        return $invitation;
    }

    /**
     * Cancels the branch invitation, provided it was sent from the logged-in manager's organization and headquarters.
     */
    public function cancel(User $manager, OrganizationInvitation $invitation): void
    {
        $headquarter = $this->headquarterOf($manager);
        $type = $this->typeOfManager($manager);

        // an invitation of another organization does not exist for the manager
        abort_unless($invitation->organization_id === $manager->organization_id, 404);
        abort_unless(
            $invitation->role === $type->leaderRole() && $invitation->{$type->headquarterColumn()} === $headquarter->id,
            403,
            'To zaproszenie należy do innej siedziby.',
        );

        $invitation->revoke($manager);
    }

    /**
     * Branch type of the invited leader who has not created it yet; otherwise 403.
     */
    public function pendingSetupType(User $leader): HeadquarterType
    {
        $type = HeadquarterType::forLeaderRole($leader->role);

        abort_unless($type !== null && $leader->organization_id, 403);
        abort_if($leader->{$type->branchColumn()}, 403, 'Masz już przypisaną placówkę.');

        // the leader of a branch in a headquarters, or one invited to found a standalone branch
        abort_unless($leader->{$type->headquarterColumn()} || $this->invitations->hasAcceptedStandaloneWithoutBranch($leader), 403);

        return $type;
    }

    /**
     * Whether the invited branch leader (an account with an organization and headquarters) still has to create the branch.
     */
    public function needsSetup(User $leader): bool
    {
        $type = HeadquarterType::forLeaderRole($leader->role);

        return $type !== null
            && $leader->organization_id
            && ! $leader->{$type->branchColumn()}
            && ($leader->{$type->headquarterColumn()} || $this->invitations->hasAcceptedStandaloneWithoutBranch($leader));
    }

    /**
     * Dashboard data for a branch leader or worker: the branch, its headquarters and the organization with barcodes.
     * An account without an assigned branch (e.g. a demo one) gets an empty dashboard.
     *
     * @return array{branch: array<string, mixed>|null}
     */
    public function dashboardData(User $leader): array
    {
        $type = HeadquarterType::forLeaderRole($leader->role) ?? HeadquarterType::forWorkerRole($leader->role);
        $branch = $type === null
            ? null
            : ($type === HeadquarterType::Shop ? $leader->shopBranch : $leader->warehouseBranch);

        if ($branch === null) {
            return ['branch' => null];
        }

        return ['branch' => [
            'id' => $branch->id,
            'name' => $branch->name,
            'barcode' => $branch->barcode,
            'NIP' => $branch->NIP ?: ($branch->headquarter?->effectiveNip() ?? $branch->organization->NIP),
            'organization' => $branch->organization->name,
            // null for a standalone branch (founded straight under the organization)
            'headquarter' => $branch->headquarter === null ? null : [
                'name' => $branch->headquarter->name,
                'barcode' => $branch->headquarter->barcode,
            ],
        ]];
    }

    /**
     * Creates the branch for the invited leader: organization_id and headquarters come from their account,
     * i.e. from the accepted invitation (foreign key), not from the form.
     *
     * The NIP may equal the organization's or headquarters' NIP, but any other NIP must not already exist in the system.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForInvitee(User $leader, array $data): ShopBranch|WarehouseBranch
    {
        $type = $this->pendingSetupType($leader);
        $repository = $this->repository($type);

        // null for a leader invited to found a standalone branch
        $headquarter = $type === HeadquarterType::Shop ? $leader->shopHeadquarter : $leader->warehouseHeadquarter;

        abort_unless($headquarter === null || $headquarter->organization_id === $leader->organization_id, 403);

        $data['name'] = trim($data['name']);

        $nip = $data['NIP'] ?? null;

        if (filled($nip)) {
            if (! $this->nipService->validateNip($nip)) {
                throw ValidationException::withMessages(['NIP' => 'Nieprawidłowy numer NIP (błędna suma kontrolna).']);
            }

            $allowed = array_filter([$leader->organization->NIP, $headquarter?->effectiveNip()]);

            if (! in_array($nip, $allowed, true) && $repository->nipInUse($nip)) {
                throw ValidationException::withMessages([
                    'NIP' => 'Ten NIP jest już używany przez inną organizację, siedzibę lub placówkę.',
                ]);
            }
        }

        return DB::transaction(function () use ($leader, $type, $repository, $headquarter, $data) {
            // one organization row is the lock under which the uniqueness of the name is checked and the branch is written
            Organization::query()->whereKey($leader->organization_id)->lockForUpdate()->first();

            if ($repository->nameExists($headquarter?->id, $data['name'], $leader->organization_id)) {
                throw ValidationException::withMessages(['name' => $headquarter === null ? 'Twoja organizacja ma już placówkę bez siedziby o tej nazwie.' : 'Ta siedziba ma już placówkę o tej nazwie.']);
            }

            $branch = $repository->create($leader->organization_id, $headquarter?->id, $data);

            $leader->forceFill([$type->branchColumn() => $branch->id])->save();

            // the invitation that led to the branch points to it
            OrganizationInvitation::query()
                ->where('organization_id', $leader->organization_id)
                ->when($headquarter === null, fn ($query) => $query->whereNull($type->headquarterColumn()), fn ($query) => $query->where($type->headquarterColumn(), $headquarter->id))
                ->where('role', $leader->role->value)
                ->where('email', strtolower($leader->email))
                ->whereNotNull('accepted_at')
                ->whereNull($type->branchColumn())
                ->update([$type->branchColumn() => $branch->id]);

            return $branch;
        });
    }
}
