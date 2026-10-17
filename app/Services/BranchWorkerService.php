<?php

namespace App\Services;

use App\Services\Contracts\BranchWorkerServiceInterface;
use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\OrganizationInvitation;
use App\Models\ShopBranch;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Notifications\OrganizationInvitationNotification;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Branch workers: the branch leader invites workers. The invitation carries the full set of foreign keys
 * (organization → headquarters → branch) from the logged-in leader's account, never from the form.
 */
class BranchWorkerService implements BranchWorkerServiceInterface
{
    public function __construct(private readonly OrganizationInvitationRepositoryInterface $invitations)
    {
        //
    }

    public function typeOfLeader(User $leader): HeadquarterType
    {
        $type = HeadquarterType::forLeaderRole($leader->role);

        abort_if($type === null, 403);

        return $type;
    }

    /**
     * The leader's branch, provided it belongs to their organization and headquarters (the whole key chain matches).
     */
    public function branchOf(User $leader): ShopBranch|WarehouseBranch
    {
        $type = $this->typeOfLeader($leader);

        abort_unless(
            $leader->organization_id && $leader->{$type->headquarterColumn()} && $leader->{$type->branchColumn()},
            403,
        );

        $branch = $type === HeadquarterType::Shop ? $leader->shopBranch : $leader->warehouseBranch;

        abort_unless(
            $branch !== null
                && $branch->organization_id === $leader->organization_id
                && $branch->{$type->headquarterColumn()} === $leader->{$type->headquarterColumn()},
            403,
            'Ta placówka należy do innej organizacji lub siedziby.',
        );

        return $branch;
    }

    /**
     * Branch workers (accounts that accepted the invitation).
     *
     * @return Collection<int, User>
     */
    public function workers(User $leader): Collection
    {
        $type = $this->typeOfLeader($leader);

        return User::query()
            ->where($type->branchColumn(), $this->branchOf($leader)->id)
            ->where('role', $type->workerRole()->value)
            ->orderBy('name')
            ->get();
    }

    /**
     * Pending worker invitations to the branch.
     *
     * @return Collection<int, OrganizationInvitation>
     */
    public function pendingInvitations(User $leader): Collection
    {
        return $this->invitations->pendingForBranch($this->branchOf($leader), $this->typeOfLeader($leader)->workerRole());
    }

    /**
     * Emails the link and key of the worker invitation. A new invitation revokes previous,
     * unaccepted ones for the same address and the same branch.
     */
    public function invite(User $leader, string $email, ?string $phone = null): OrganizationInvitation
    {
        $branch = $this->branchOf($leader);
        $type = $this->typeOfLeader($leader);
        $role = $type->workerRole();
        $email = strtolower($email);

        if (strtolower($leader->email) === $email) {
            throw ValidationException::withMessages(['email' => 'Nie możesz zaprosić samego siebie.']);
        }

        $existing = User::where('email', $email)->first();

        if ($existing && (
            ! in_array($existing->role, [UserRole::Employee, $role], true)
            || ($existing->organization_id && $existing->organization_id !== $leader->organization_id)
            || ($existing->{$type->branchColumn()} && $existing->{$type->branchColumn()} !== $branch->id)
        )) {
            throw ValidationException::withMessages([
                'email' => 'Ten adres należy do konta, które nie może zostać pracownikiem tej placówki.',
            ]);
        }

        $this->invitations->revokePendingForBranch($branch, $role, $email, $leader);

        [$invitation, $verificationCode] = $this->invitations->issue(
            $leader->organization, $email, $leader, $role, null, $phone, $branch,
        );

        Notification::route('mail', $email)
            ->notify(new OrganizationInvitationNotification($invitation, $verificationCode));

        return $invitation;
    }

    /**
     * Cancels the worker invitation, provided it was sent from the logged-in leader's organization, headquarters and branch.
     */
    public function cancel(User $leader, OrganizationInvitation $invitation): void
    {
        $branch = $this->branchOf($leader);
        $type = $this->typeOfLeader($leader);

        // an invitation of another organization does not exist for the leader
        abort_unless($invitation->organization_id === $leader->organization_id, 404);
        abort_unless(
            $invitation->role === $type->workerRole()
                && $invitation->{$type->headquarterColumn()} === $leader->{$type->headquarterColumn()}
                && $invitation->{$type->branchColumn()} === $branch->id,
            403,
            'To zaproszenie należy do innej siedziby lub placówki.',
        );

        $invitation->revoke($leader);
    }
}
