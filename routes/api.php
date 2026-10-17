<?php

use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

// API of the connectors of units (the ApiConnector program on the computers of shops and warehouses). A connector is let
// in with the API key of its unit (Authorization: Bearer or X-API-KEY, see AuthenticateApiKey); the keys are managed in
// the settings of the people who manage the unit. The unit is known only from the key, never from the data.
Route::middleware(['api.key', 'throttle:connector'])->group(function () {
    // the connector checks the address and the key: it learns which unit it belongs to
    Route::get('ping', [ConnectorController::class, 'ping'])->name('api.ping');

    // one table of the connector's database per request (dbo.Towary is dbo_Towary)
    Route::post('sync/data/{endpoint}', [SyncController::class, 'store'])->where('endpoint', '[A-Za-z0-9_]{1,128}')->name('api.sync.data');
});
