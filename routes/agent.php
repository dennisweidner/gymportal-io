<?php

use App\Http\Controllers\Api\Agent\AgentReadController;
use App\Http\Controllers\Api\Agent\AgentWriteController;
use Illuminate\Support\Facades\Route;

/*
| Agent API
|
| For AI agents and other automations acting for one gym. Authentication is a
| Bearer token (`php artisan agent:token create`), the gym comes from the
| token. Each route names the ability it needs. Loaded inside the v1 group of
| routes/api.php, so every path below lives under /api/v1/agent and every
| route name starts with v1.agent.
*/
Route::get('health', [AgentReadController::class, 'health'])->middleware('agent.auth')->name('health');

Route::get('plans', [AgentReadController::class, 'plans'])->middleware('agent.auth:plans.read')->name('plans');
Route::get('occupancy', [AgentReadController::class, 'occupancy'])->middleware('agent.auth:stats.read')->name('occupancy');
Route::get('stats', [AgentReadController::class, 'stats'])->middleware('agent.auth:stats.read')->name('stats');
Route::get('insights/inactive-members', [AgentReadController::class, 'inactiveMembers'])->middleware('agent.auth:stats.read')->name('insights.inactive-members');

// Mirror mode: data arrives as a studio export, confirmed writes wait as actions
Route::get('mirror', [AgentReadController::class, 'mirrorStatus'])->middleware('agent.auth')->name('mirror.status');
Route::post('mirror/import', [AgentWriteController::class, 'importMirror'])->middleware('agent.auth:mirror.write')->name('mirror.import');
Route::get('actions', [AgentReadController::class, 'actions'])->middleware('agent.auth:actions.read')->name('actions.index');
Route::post('actions/{actionId}/close', [AgentWriteController::class, 'closeAction'])->where(['actionId' => '[0-9]+'])->middleware('agent.auth:actions.write')->name('actions.close');

Route::get('members', [AgentReadController::class, 'members'])->middleware('agent.auth:members.read')->name('members.index');
Route::get('members/lookup', [AgentReadController::class, 'lookup'])->middleware('agent.auth:members.read')->name('members.lookup');

Route::prefix('members/{memberId}')->where(['memberId' => '[0-9]+'])->name('members.')->group(function () {
    Route::get('/', [AgentReadController::class, 'showMember'])->middleware('agent.auth:members.read')->name('show');
    Route::get('contract', [AgentReadController::class, 'contract'])->middleware('agent.auth:contracts.read')->name('contract');
    Route::get('access', [AgentReadController::class, 'access'])->middleware('agent.auth:access.read')->name('access');
    Route::get('check-ins', [AgentReadController::class, 'checkIns'])->middleware('agent.auth:access.read')->name('check-ins');
    Route::get('payments', [AgentReadController::class, 'payments'])->middleware('agent.auth:payments.read')->name('payments');

    // Writes: preview unless the body carries "confirm": true
    Route::patch('/', [AgentWriteController::class, 'updateMember'])->middleware('agent.auth:members.write')->name('update');
    Route::post('notes', [AgentWriteController::class, 'addNote'])->middleware('agent.auth:members.write')->name('notes.store');
    Route::post('contract/cancellation', [AgentWriteController::class, 'cancel'])->middleware('agent.auth:contracts.write')->name('contract.cancel');
    Route::delete('contract/cancellation', [AgentWriteController::class, 'revokeCancellation'])->middleware('agent.auth:contracts.write')->name('contract.revoke-cancellation');
    Route::post('contract/pause', [AgentWriteController::class, 'pause'])->middleware('agent.auth:contracts.write')->name('contract.pause');
    Route::post('contract/resume', [AgentWriteController::class, 'resume'])->middleware('agent.auth:contracts.write')->name('contract.resume');
});
