<?php

namespace App\Services;

use App\Repositories\Contracts\TaskRepositoryInterface;
use App\Services\Contracts\TaskServiceInterface;

/**
 * Tasks of the people of the platform (the same view for every role). The service is only wired to its
 * repository through the constructor for now.
 */
class TaskService implements TaskServiceInterface
{
    public function __construct(private readonly TaskRepositoryInterface $tasks)
    {
        //
    }
}
