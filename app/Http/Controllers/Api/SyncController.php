<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SyncDataRequest;
use App\Models\ApiKey;
use App\Services\Contracts\SyncServiceInterface;
use Illuminate\Http\JsonResponse;

/**
 * Tables sent by the connectors of units. Which unit the data is for follows only from the API key of the request.
 */
class SyncController extends Controller
{
    public function __construct(private readonly SyncServiceInterface $sync)
    {
        //
    }

    public function store(SyncDataRequest $request, string $endpoint): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        return response()->json($this->sync->receive($key, $endpoint, $request->validated()));
    }
}
