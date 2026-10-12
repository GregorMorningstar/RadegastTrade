<?php

namespace App\Services;

use App\Services\Contracts\BranchServiceInterface;
use App\Services\Contracts\NipServiceInterface;
use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Notifications\OrganizationInvitationNotification;
use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
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
        $headquarter = $this->headquarterOf($manager);
        $type = $this->typeOfManager($manager);
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

        $this->invitations->revokePendingNewBranch($headquarter, $role, $email, $manager);

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

        abort_unless(
            $invitation->role === $type->leaderRole()
                && $invitation->organization_id === $manager->organization_id
                && $invitation->{$type->headquarterColumn()} === $headquarter->id,
            403,
            'To zaproszenie należy do innej organizacji lub siedziby.',
        );

        $invitation->revoke($manager);
    }

    /**
     * Branch type of the invited leader who has not created it yet; otherwise 403.
     */
    public function pendingSetupType(User $leader): HeadquarterType
    {
        $type = HeadquarterType::forLeaderRole($leader->role);

        abort_unless($type !== null && $leader->organization_id && $leader->{$type->headquarterColumn()}, 403);
        abort_if($leader->{$type->branchColumn()}, 403, 'Masz już przypisaną placówkę.');

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
            && $leader->{$type->headquarterColumn()}
            && ! $leader->{$type->branchColumn()};
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
            'NIP' => $branch->NIP ?: $branch->headquarter->effectiveNip(),
            'organization' => $branch->organization->name,
            'headquarter' => [
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

        $headquarter = $type === HeadquarterType::Shop ? $leader->shopHeadquarter : $leader->warehouseHeadquarter;

        abort_unless($headquarter->organization_id === $leader->organization_id, 403);

        $data['name'] = trim($data['name']);

        if ($repository->nameExists($headquarter->id, $data['name'])) {
            throw ValidationException::withMessages(['name' => 'Ta siedziba ma już placówkę o tej nazwie.']);
        }

        $nip = $data['NIP'] ?? null;

        if (filled($nip)) {
            if (! $this->nipService->validateNip($nip)) {
                throw ValidationException::withMessages(['NIP' => 'Nieprawidłowy numer NIP (błędna suma kontrolna).']);
            }

            $allowed = [$leader->organization->NIP, $headquarter->effectiveNip()];

            if (! in_array($nip, $allowed, true) && $repository->nipInUse($nip)) {
                throw ValidationException::withMessages([
                    'NIP' => 'Ten NIP jest już używany przez inną organizację, siedzibę lub placówkę.',
                ]);
            }
        }

        return DB::transaction(function () use ($leader, $type, $repository, $headquarter, $data) {
            $branch = $repository->create($leader->organization_id, $headquarter->id, $data);

            $leader->forceFill([$type->branchColumn() => $branch->id])->save();

            // the invitation that led to the branch points to it
            OrganizationInvitation::query()
                ->where('organization_id', $leader->organization_id)
                ->where($type->headquarterColumn(), $headquarter->id)
                ->where('role', $leader->role->value)
                ->where('email', strtolower($leader->email))
                ->whereNotNull('accepted_at')
                ->whereNull($type->branchColumn())
                ->update([$type->branchColumn() => $branch->id]);

            return $branch;
        });
    }
}
