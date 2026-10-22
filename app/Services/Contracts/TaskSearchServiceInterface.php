<?php

namespace App\Services\Contracts;

use App\Models\User;

interface TaskSearchServiceInterface
{
    /**
     * Searches the tasks the signed-in person may see: the organization owner every task of the organization, a headquarters
     * manager and a branch leader the tasks of their unit and of the units below it, a worker the tasks for them, for their
     * branch and (with the tasks they made) nothing else; the administrator every organization, read-only. A person without
     * a place in the structure (no panel, no organization) gets 403. The scope comes only from the account.
     *
     * @param  array<string, mixed>  $filters  q, status, priority, kind (recurring|ordinary), mine, from, to, page, per_page
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int, per_page: int}
     */
    public function search(User $actor, array $filters): array;
}
