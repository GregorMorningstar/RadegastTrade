<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Contracts\ApiKeyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a connector may ask the platform for. The key of the request (set by AuthenticateApiKey) tells which unit it is.
 */
class ConnectorController extends Controller
{
    public function __construct(private readonly ApiKeyServiceInterface $keys)
    {
        //
    }

    public function ping(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String(), ...$this->keys->describe($key)]);
    }
}
