<?php

namespace App\Services;

use App\Services\Contracts\TeamMembersServiceInterface;
use App\Services\Contracts\UnitMembershipServiceInterface;
use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Team in an organization and in a headquarters: accounts and pending invitations together with roles.
 *
 * Relations come from foreign keys (organization → headquarters → branch), not from the teams table.
 */
class TeamMembersService implements TeamMembersServiceInterface
{
    public function __construct(private readonly UnitMembershipServiceInterface $memberships)
    {
        //
    }

    /**
     * Role order in the listing: from the organizer to the workers.
     *
     * @var list<UserRole>
     */
    private const ROLE_ORDER = [
        UserRole::ORGANIZATION_OWNER,
        UserRole::SHOP_OWNER,
        UserRole::WAREHOUSE_OWNER,
        UserRole::SHOP_LEADER,
        UserRole::WAREHOUSE_LEADER,
        UserRole::SHOP_WORKER,
        UserRole::WAREHOUSE_WORKER,
    ];

    /**
     * Everyone in the owner's organization: organizers, headquarters managers and branch leaders.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrganization(User $owner): array
    {
        abort_unless($owner->role === UserRole::ORGANIZATION_OWNER && $owner->organization_id, 403);

        return $this->collect($owner->organization_id, null, $owner);
    }

    /**
     * Headquarters team: the organizer (organization owners) plus the headquarters managers and its branch leaders,
     * including invitees who have not accepted yet.
     *
     * @return list<array<string, mixed>>
     */
    public function forHeadquarter(User $manager): array
    {
        $type = HeadquarterType::forRole($manager->role);

        abort_unless($type !== null && $manager->organization_id && $manager->{$type->headquarterColumn()}, 403);

        return $this->collect($manager->organization_id, [$type, (int) $manager->{$type->headquarterColumn()}], $manager);
    }

    /**
     * @param  array{0: HeadquarterType, 1: int}|null  $headquarter  narrows to one headquarters (and the organizer)
     * @return list<array<string, mixed>>
     */
    private function collect(int $organizationId, ?array $headquarter, User $viewer): array
    {
        $users = User::query()
            ->where('organization_id', $organizationId)
            ->with(['shopHeadquarter:id,name', 'warehouseHeadquarter:id,name', 'shopBranch:id,name', 'warehouseBranch:id,name', 'deactivatedBy:id,name'])
            ->get()
            ->filter(fn (User $user) => $headquarter === null
                || $user->role === UserRole::ORGANIZATION_OWNER
                || $user->{$headquarter[0]->headquarterColumn()} === $headquarter[1]);

        $invitations = OrganizationInvitation::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with(['shopHeadquarter:id,name', 'warehouseHeadquarter:id,name', 'shopBranch:id,name', 'warehouseBranch:id,name'])
            ->get()
            ->filter(fn (OrganizationInvitation $invitation) => $headquarter === null
                || $invitation->{$headquarter[0]->headquarterColumn()} === $headquarter[1]);

        return $this->rows($users, $invitations, $viewer);
    }

    /**
     * Branch team: the branch leader and workers. The leader also sees worker invitations
     * that are awaiting acceptance; a worker sees only people who are already on the team.
     *
     * @return list<array<string, mixed>>
     */
    public function forBranch(User $member): array
    {
        $type = HeadquarterType::forLeaderRole($member->role) ?? HeadquarterType::forWorkerRole($member->role);

        abort_unless(
            $type !== null && $member->organization_id && $member->{$type->headquarterColumn()} && $member->{$type->branchColumn()},
            403,
        );

        $branchColumn = $type->branchColumn();
        $branchId = (int) $member->{$branchColumn};

        $users = User::query()
            ->where('organization_id', $member->organization_id)
            ->where($branchColumn, $branchId)
            ->whereIn('role', [$type->leaderRole()->value, $type->workerRole()->value])
            ->with(['shopBranch:id,name', 'warehouseBranch:id,name', 'deactivatedBy:id,name'])
            ->get();

        $invitations = $member->role === $type->leaderRole()
            ? OrganizationInvitation::query()
                ->where('organization_id', $member->organization_id)
                ->where($branchColumn, $branchId)
                ->where('role', $type->workerRole()->value)
                ->where('is_active', true)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->with(['shopBranch:id,name', 'warehouseBranch:id,name'])
                ->get()
            : new EloquentCollection;

        return $this->rows($users, $invitations, $member);
    }

    /**
     * @param  iterable<User>  $users
     * @param  iterable<OrganizationInvitation>  $invitations
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $users, iterable $invitations, User $viewer): array
    {
        return collect()
            ->merge(collect($users)->map(fn (User $user) => [
                'key' => 'user-'.$user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role->label(),
                'roleValue' => $user->role->value,
                'unit' => $this->userUnit($user),
                'userId' => $user->id,
                // the active switch: who may use it and what the account's state is
                'canToggle' => $this->memberships->canSetActive($viewer, $user),
                'status' => $user->is_active === false ? 'inactive' : 'active',
                'detail' => $user->is_active === false
                    ? 'wyłączone'.($user->deactivatedBy ? ' przez '.$user->deactivatedBy->name : '').($user->deactivated_at ? ' dnia '.$user->deactivated_at->format('d.m.Y H:i') : '')
                    : ($user->last_login_local ? 'ostatnie logowanie '.$user->last_login_local->format('d.m.Y H:i') : null),
                '_order' => $this->order($user->role),
            ]))
            ->merge(collect($invitations)->map(fn (OrganizationInvitation $invitation) => [
                'key' => 'invitation-'.$invitation->id,
                'name' => null,
                'email' => $invitation->email,
                'phone' => $invitation->phone,
                'role' => $invitation->role->label(),
                'roleValue' => $invitation->role->value,
                'unit' => $this->invitationUnit($invitation),
                'status' => $invitation->isLocked() ? 'locked' : 'invited',
                'detail' => 'ważne do '.$invitation->expires_at->format('d.m.Y H:i'),
                '_order' => $this->order($invitation->role),
            ]))
            ->sortBy([['_order', 'asc'], ['status', 'asc'], ['email', 'asc']])
            ->map(fn (array $row) => collect($row)->except('_order')->all())
            ->values()
            ->all();
    }

    private function order(UserRole $role): int
    {
        $index = array_search($role, self::ROLE_ORDER, true);

        return $index === false ? count(self::ROLE_ORDER) : $index;
    }

    private function userUnit(User $user): string
    {
        return match ($user->role) {
            UserRole::ORGANIZATION_OWNER => 'Organizacja',
            UserRole::SHOP_OWNER => $user->shopHeadquarter ? 'Siedziba sklepu: '.$user->shopHeadquarter->name : 'Siedziba sklepu',
            UserRole::WAREHOUSE_OWNER => $user->warehouseHeadquarter ? 'Siedziba hurtowni: '.$user->warehouseHeadquarter->name : 'Siedziba hurtowni',
            UserRole::SHOP_LEADER => $user->shopBranch ? 'Placówka sklepu: '.$user->shopBranch->name : 'Nowa placówka sklepu',
            UserRole::WAREHOUSE_LEADER => $user->warehouseBranch ? 'Placówka hurtowni: '.$user->warehouseBranch->name : 'Nowa placówka hurtowni',
            UserRole::SHOP_WORKER => $user->shopBranch ? 'Placówka sklepu: '.$user->shopBranch->name : 'Placówka sklepu',
            UserRole::WAREHOUSE_WORKER => $user->warehouseBranch ? 'Placówka hurtowni: '.$user->warehouseBranch->name : 'Placówka hurtowni',
            default => '—',
        };
    }

    private function invitationUnit(OrganizationInvitation $invitation): string
    {
        $headquarter = $invitation->shopHeadquarter ?? $invitation->warehouseHeadquarter;

        return match ($invitation->role) {
            UserRole::ORGANIZATION_OWNER => 'Organizacja',
            UserRole::SHOP_OWNER => $headquarter ? 'Siedziba sklepu: '.$headquarter->name : 'Nowa siedziba sklepu',
            UserRole::WAREHOUSE_OWNER => $headquarter ? 'Siedziba hurtowni: '.$headquarter->name : 'Nowa siedziba hurtowni',
            UserRole::SHOP_LEADER => 'Nowa placówka sklepu'.($headquarter ? ' ('.$headquarter->name.')' : ''),
            UserRole::WAREHOUSE_LEADER => 'Nowa placówka hurtowni'.($headquarter ? ' ('.$headquarter->name.')' : ''),
            UserRole::SHOP_WORKER => 'Placówka sklepu: '.($invitation->shopBranch?->name ?? '—'),
            UserRole::WAREHOUSE_WORKER => 'Placówka hurtowni: '.($invitation->warehouseBranch?->name ?? '—'),
            default => '—',
        };
    }
}
