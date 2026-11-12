<?php

namespace App\Http\Controllers\Api\Agent;

use App\Events\ContractCancelled;
use App\Mail\CancellationConfirmationMail;
use App\Mail\Dispatching\MemberMailDispatcher;
use App\Models\AgentPendingAction;
use App\Models\Member;
use App\Services\AgentApi\MirrorService;
use App\Services\MembershipService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Write side of the Agent API.
 *
 * Every write is a two-step: without `"confirm": true` the request only
 * returns what would happen (`preview: true`) and changes nothing. An agent
 * can therefore show the member the exact effect before anything is stored.
 *
 * The rules themselves are not reimplemented here. Dates and eligibility come
 * from the same model and service code the portal and the member app use.
 */
class AgentWriteController extends AgentController
{
    private const CANCELLATION_REASONS = [
        'move' => 'Umzug',
        'financial' => 'Finanzielle Gründe',
        'health' => 'Gesundheitliche Gründe',
        'dissatisfied' => 'Unzufriedenheit',
        'no_time' => 'Zeitmangel',
        'other' => 'Sonstiges',
    ];

    public function updateMember(Request $request, string $memberId): JsonResponse
    {
        $member = $this->member($request, $memberId);

        if (! $member) {
            return $this->memberNotFound();
        }

        // The e-mail address is the login of the member app. Changing it here
        // would hand that login to whoever asked the agent, so it stays a task
        // for the portal.
        if ($request->has('email')) {
            return $this->fail('email_change_not_supported', 'The e-mail address is the member app login; change it in the portal.', 422);
        }

        $validated = $request->validate([
            'confirm' => 'nullable|boolean',
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:255',
            'address_addition' => 'sometimes|nullable|string|max:255',
            'postal_code' => 'sometimes|nullable|string|max:10',
            'city' => 'sometimes|nullable|string|max:100',
            'country' => 'sometimes|nullable|string|max:2',
            'emergency_contact_name' => 'sometimes|nullable|string|max:255',
            'emergency_contact_phone' => 'sometimes|nullable|string|max:20',
        ]);

        $changes = [];

        foreach (collect($validated)->except('confirm') as $field => $value) {
            if ((string) $member->{$field} !== (string) $value) {
                $changes[$field] = ['from' => $member->{$field}, 'to' => $value];
            }
        }

        if ($changes === []) {
            return $this->fail('nothing_to_change', 'The given values match what is stored.', 422);
        }

        if (! $this->confirmed($request)) {
            return $this->ok(['preview' => true, 'changes' => $changes]);
        }

        if ($queued = $this->queueIfMirror($request, $member, 'update_member', array_map(static fn (array $c) => $c['to'], $changes), ['changes' => $changes])) {
            return $queued;
        }

        $member->update(array_map(static fn (array $change) => $change['to'], $changes));

        $this->markChanged($request, 'member updated: '.implode(', ', array_keys($changes)), $changes);

        return $this->ok([
            'preview' => false,
            'changes' => $changes,
            'member' => $this->presentMember($member->fresh()),
        ]);
    }

    /**
     * Appends a dated note to the member, as staff would in the portal.
     */
    public function addNote(Request $request, string $memberId): JsonResponse
    {
        $member = $this->member($request, $memberId);

        if (! $member) {
            return $this->memberNotFound();
        }

        $validated = $request->validate([
            'confirm' => 'nullable|boolean',
            'text' => 'required|string|max:1000',
        ]);

        $line = now()->format('d.m.Y H:i').' ('.$this->client($request)->name.'): '.trim($validated['text']);

        if (! $this->confirmed($request)) {
            return $this->ok(['preview' => true, 'note' => $line]);
        }

        if ($queued = $this->queueIfMirror($request, $member, 'add_note', ['text' => trim($validated['text'])], ['note' => $line])) {
            return $queued;
        }

        $previous = $member->notes;
        $member->update(['notes' => ($previous ? $previous."\n" : '').$line]);

        $this->markChanged($request, 'note added to member', ['notes' => ['from' => $previous, 'to' => $member->notes]]);

        return $this->ok(['preview' => false, 'note' => $line]);
    }

    /**
     * Ordinary cancellation to the next reachable date, the same rule the
     * member app applies: it is always accepted and takes effect at the next
     * possible date.
     */
    public function cancel(
        Request $request,
        string $memberId,
        MembershipService $membershipService,
        MemberMailDispatcher $mailDispatcher,
    ): JsonResponse {
        $member = $this->member($request, $memberId);

        if (! $member) {
            return $this->memberNotFound();
        }

        $validated = $request->validate([
            'confirm' => 'nullable|boolean',
            'reason' => 'nullable|string|in:'.implode(',', array_keys(self::CANCELLATION_REASONS)),
            'note' => 'nullable|string|max:255',
            'send_confirmation' => 'nullable|boolean',
            'expected_effective_date' => 'nullable|date_format:Y-m-d',
        ]);

        $membership = $this->contractMembership($member);

        if (! $membership) {
            return $this->fail('no_cancellable_membership', 'This member has no membership that can be cancelled.', 422);
        }

        if ($membership->cancellation_date) {
            return $this->fail('already_cancelled', 'This membership is already cancelled.', 409, [
                'effective_date' => $this->dateString($membership->getRawOriginal('cancellation_date')),
            ]);
        }

        $effectiveDate = $membership->nextPossibleCancellationDate();

        if (! $effectiveDate) {
            return $this->fail('no_cancellation_date', 'No cancellation date can be derived for this membership.', 422);
        }

        $reasonKey = $validated['reason'] ?? 'other';
        $note = trim($validated['note'] ?? '');
        $reasonText = self::CANCELLATION_REASONS[$reasonKey]
            .' ('.($note !== '' ? $note : 'Ordentliche Kündigung über Agent-API').')';
        $sendConfirmation = $request->boolean('send_confirmation');

        $effect = [
            'membership_id' => $membership->id,
            'plan' => $membership->membershipPlan?->name,
            'effective_date' => $effectiveDate,
            'reason' => $reasonText,
            'confirmation_mail' => $sendConfirmation,
        ];

        if (! $this->confirmed($request)) {
            return $this->ok(['preview' => true, 'cancellation' => $effect]);
        }

        // Preview and confirmation are bound by the date. If it moved in between,
        // for instance because the notice period ended overnight, nothing is
        // stored and the new date comes back for a new decision.
        if (empty($validated['expected_effective_date'])) {
            return $this->fail('expected_effective_date_required', 'Confirm with expected_effective_date set to the effective_date of the preview.', 422, [
                'cancellation' => $effect,
            ]);
        }

        if ($validated['expected_effective_date'] !== $effectiveDate) {
            return $this->fail('effective_date_changed', 'The cancellation date is no longer the one of the preview.', 409, [
                'cancellation' => $effect,
            ]);
        }

        if ($queued = $this->queueIfMirror($request, $member, 'cancel_contract', ['reason' => $reasonKey, 'note' => $note, 'received_at' => now()->toIso8601String()], ['cancellation' => $effect])) {
            return $queued;
        }

        DB::transaction(function () use ($membership, $effectiveDate, $reasonText, $membershipService, $request) {
            $membership->update([
                'cancellation_date' => $effectiveDate,
                'cancellation_reason' => $reasonText,
                'cancellation_received_at' => now(),
                'notes' => ($membership->notes ? $membership->notes."\n" : '')
                    .'Gekündigt am '.now()->format('d.m.Y H:i').' zum '.Carbon::parse($effectiveDate)->format('d.m.Y')
                    .' über Agent-API ('.$this->client($request)->name.')',
            ]);

            $membershipService->cancelBookedAddons($membership, Carbon::parse($effectiveDate));
        });

        $membership = $membership->fresh();

        // After the commit, so a mail failure cannot undo a stored cancellation.
        if ($sendConfirmation) {
            $mailDispatcher->sendToMember(
                $member,
                new CancellationConfirmationMail($member, $membership, $member->gym),
            );
        }

        ContractCancelled::dispatch($member, $membership, $member->gym);

        $this->markChanged($request, 'membership '.$membership->id.' cancelled to '.$effectiveDate, [
            'cancellation_date' => ['from' => null, 'to' => $effectiveDate],
        ]);

        return $this->ok([
            'preview' => false,
            'cancellation' => $effect,
            'membership' => $this->presentMembership($membership->loadMissing('membershipPlan')),
        ]);
    }

    public function revokeCancellation(Request $request, string $memberId, MembershipService $membershipService): JsonResponse
    {
        $member = $this->member($request, $memberId);

        if (! $member) {
            return $this->memberNotFound();
        }

        $request->validate(['confirm' => 'nullable|boolean']);

        $membership = $member->memberships()
            ->whereNotNull('cancellation_date')
            ->whereIn('status', ['active', 'paused'])
            ->orderByDesc('start_date')
            ->first();

        if (! $membership) {
            return $this->fail('no_pending_cancellation', 'There is no cancellation that could still be revoked.', 422);
        }

        $effect = [
            'membership_id' => $membership->id,
            'revoked_effective_date' => $this->dateString($membership->getRawOriginal('cancellation_date')),
        ];

        if (! $this->confirmed($request)) {
            return $this->ok(['preview' => true, 'revocation' => $effect]);
        }

        if ($queued = $this->queueIfMirror($request, $member, 'revoke_cancellation', [], ['revocation' => $effect])) {
            return $queued;
        }

        $previousReason = $membership->cancellation_reason;

        DB::transaction(function () use ($membership, $membershipService, $request) {
            $membership->update([
                'cancellation_date' => null,
                'cancellation_reason' => null,
                'cancellation_received_at' => null,
                'notes' => ($membership->notes ? $membership->notes."\n" : '')
                    .'Kündigung zurückgenommen am '.now()->format('d.m.Y').' über Agent-API ('.$this->client($request)->name.')',
            ]);

            $membershipService->restoreAddonsCancelledWithMembership($membership);
        });

        $this->markChanged($request, 'cancellation of membership '.$membership->id.' revoked', [
            'cancellation_date' => ['from' => $effect['revoked_effective_date'], 'to' => null],
            'cancellation_reason' => ['from' => $previousReason, 'to' => null],
        ]);

        return $this->ok([
            'preview' => false,
            'revocation' => $effect,
            'membership' => $this->presentMembership($membership->fresh()->loadMissing('membershipPlan')),
        ]);
    }

    public function pause(Request $request, string $memberId, MembershipService $membershipService): JsonResponse
    {
        $member = $this->member($request, $memberId);

        if (! $member) {
            return $this->memberNotFound();
        }

        $validated = $request->validate([
            'confirm' => 'nullable|boolean',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after:start_date',
            'reason' => 'nullable|string|max:500',
        ]);

        $membership = $member->activeMembership();

        if (! $membership) {
            return $this->fail('no_active_membership', 'This member has no active membership.', 422);
        }

        // Pausing the free period would move its end and leave the paid
        // contract running, so the portal decides that case.
        if ($membership->is_free_trial) {
            return $this->fail('free_period_running', 'The member is in a free period; pause the contract in the portal.', 422);
        }

        $eligibility = $membershipService->checkPauseEligibility($membership);

        if (! $eligibility['eligible']) {
            return $this->fail('not_eligible', (string) $eligibility['reason'], 422);
        }

        $effect = [
            'membership_id' => $membership->id,
            'start_date' => Carbon::parse($validated['start_date'])->toDateString(),
            'end_date' => Carbon::parse($validated['end_date'])->toDateString(),
            'starts_today' => Carbon::parse($validated['start_date'])->isToday(),
        ];

        if (! $this->confirmed($request)) {
            return $this->ok(['preview' => true, 'pause' => $effect]);
        }

        if ($queued = $this->queueIfMirror($request, $member, 'pause_contract', ['start_date' => $effect['start_date'], 'end_date' => $effect['end_date'], 'reason' => $validated['reason'] ?? null], ['pause' => $effect])) {
            return $queued;
        }

        $previousEndDate = $this->dateString($membership->getRawOriginal('end_date'));

        $membership = $membershipService->pause(
            $membership,
            $validated['start_date'],
            $validated['end_date'],
            ($validated['reason'] ?? 'Pause').' (Agent-API: '.$this->client($request)->name.')',
        );

        $this->markChanged($request, 'membership '.$membership->id.' paused '.$effect['start_date'].' to '.$effect['end_date'], [
            'pause' => ['from' => null, 'to' => [$effect['start_date'], $effect['end_date']]],
            'end_date' => ['from' => $previousEndDate, 'to' => $this->dateString($membership->getRawOriginal('end_date'))],
        ]);

        return $this->ok([
            'preview' => false,
            'pause' => $effect,
            'membership' => $this->presentMembership($membership->fresh()->loadMissing('membershipPlan')),
        ]);
    }

    public function resume(Request $request, string $memberId, MembershipService $membershipService): JsonResponse
    {
        $member = $this->member($request, $memberId);

        if (! $member) {
            return $this->memberNotFound();
        }

        $request->validate(['confirm' => 'nullable|boolean']);

        $membership = $member->memberships()
            ->where(fn ($q) => $q->where('status', 'paused')->orWhereNotNull('pause_start_date'))
            ->orderByDesc('start_date')
            ->first();

        if (! $membership) {
            return $this->fail('not_paused', 'This member has no paused membership.', 422);
        }

        $eligibility = $membershipService->checkResumeEligibility($membership);

        if (! $eligibility['eligible']) {
            return $this->fail('not_eligible', (string) $eligibility['reason'], 422);
        }

        if (! $this->confirmed($request)) {
            return $this->ok(['preview' => true, 'resume' => ['membership_id' => $membership->id]]);
        }

        if ($queued = $this->queueIfMirror($request, $member, 'resume_contract', [], ['resume' => ['membership_id' => $membership->id]])) {
            return $queued;
        }

        $previousPause = [
            $this->dateString($membership->getRawOriginal('pause_start_date')),
            $this->dateString($membership->getRawOriginal('pause_end_date')),
        ];

        $membership = $membershipService->resume($membership);

        $this->markChanged($request, 'membership '.$membership->id.' resumed', [
            'pause' => ['from' => $previousPause, 'to' => null],
        ]);

        return $this->ok([
            'preview' => false,
            'resume' => ['membership_id' => $membership->id],
            'membership' => $this->presentMembership($membership->fresh()->loadMissing('membershipPlan')),
        ]);
    }

    /**
     * Only `"confirm": true` in a JSON body executes a write. A query string
     * never does, so a logged or repeated URL cannot change anything.
     */
    private function confirmed(Request $request): bool
    {
        return $request->isJson() && $request->json('confirm') === true;
    }

    /**
     * In a mirror nothing may be written: the next import would silently undo
     * it. The confirmed request becomes a pending action instead, which a
     * human carries out in the source portal.
     */
    private function queueIfMirror(Request $request, Member $member, string $type, array $payload, array $effect): ?JsonResponse
    {
        $mirror = $this->mirror($request);

        if (! $mirror) {
            return null;
        }

        $action = AgentPendingAction::create([
            'gym_id' => $member->gym_id,
            'gym_api_client_id' => $this->client($request)->id,
            'type' => $type,
            'member_number' => $member->member_number,
            'member_name' => trim($member->first_name.' '.$member->last_name),
            'portal_member_id' => ($mirror->portal_member_ids ?? [])[$member->member_number] ?? null,
            'payload' => $payload,
            'effect' => $effect,
        ]);

        $request->attributes->set('agent_summary', 'queued '.$type.' as action '.$action->id);

        return response()->json([
            'success' => true,
            'data' => ['preview' => false, 'queued' => true, 'action' => $this->presentAction($action)] + $effect,
        ], 202);
    }

    public function closeAction(Request $request, string $actionId): JsonResponse
    {
        $validated = $request->validate(['status' => 'required|in:done,dismissed']);

        $action = AgentPendingAction::where('gym_id', $this->gym($request)->id)->whereKey($actionId)->first();

        if (! $action) {
            return $this->fail('action_not_found', 'No such action in this gym.', 404);
        }

        if ($action->status !== AgentPendingAction::STATUS_OPEN) {
            return $this->fail('action_closed', 'This action is already closed.', 409);
        }

        $action->close($validated['status'], 'manual');
        $this->markChanged($request, 'action '.$action->id.' marked '.$validated['status']);

        return $this->ok(['action' => $this->presentAction($action->fresh())]);
    }

    /**
     * Takes a studio export (the JSON from Settings > data transfer) and
     * replaces the mirrored data with it.
     */
    public function importMirror(Request $request, MirrorService $mirrorService): JsonResponse
    {
        $data = $request->json()->all();

        if (! is_array($data) || ! isset($data['export_metadata'])) {
            return $this->fail('not_an_export', 'The body must be a gymportal studio export (JSON).', 422);
        }

        try {
            $result = $mirrorService->import($data, $this->gym($request), 'api:'.$this->client($request)->name);
        } catch (\InvalidArgumentException $e) {
            return $this->fail('import_refused', $e->getMessage(), 422);
        }

        $this->markChanged($request, 'mirror import '.$result['sync']->id);

        return $this->ok([
            'sync_id' => $result['sync']->id,
            'exported_at' => optional($result['sync']->exported_at)->toIso8601String(),
            'stats' => $result['sync']->stats,
            'actions_closed' => $result['sync']->actions_closed,
        ]);
    }

    private function markChanged(Request $request, string $summary, ?array $changes = null): void
    {
        $request->attributes->set('agent_changed_data', true);
        $request->attributes->set('agent_changes', $changes);
        $request->attributes->set('agent_summary', substr($summary, 0, 255));
    }
}
