<?php

namespace App\Services\AgentApi;

use App\Models\AgentMirrorSync;
use App\Models\AgentPendingAction;
use App\Models\Gym;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\GymDataImportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Keeps a gym in step with the studio export of another gymportal
 * installation (Settings > data transfer > export).
 *
 * Each import replaces the member data of the mirrored gym with the export.
 * Tokens, the audit log and pending actions survive, because they belong to
 * the mirror and not to the export.
 */
class MirrorService
{
    public function __construct(private GymDataImportService $importService) {}

    public function isMirror(int $gymId): bool
    {
        return AgentMirrorSync::where('gym_id', $gymId)->exists();
    }

    /**
     * @return array{gym: Gym, sync: AgentMirrorSync, created: bool}
     */
    public function import(array $data, ?Gym $gym, ?string $source = null, bool $createIfMissing = false): array
    {
        $validation = $this->importService->validateImportData($data);

        if (! ($validation['valid'] ?? false)) {
            throw new \InvalidArgumentException('Export ungültig: '.implode('; ', $validation['errors'] ?? ['unbekannter Fehler']));
        }

        $created = false;

        if (! $gym) {
            if (! $createIfMissing) {
                throw new \InvalidArgumentException('Kein Studio angegeben.');
            }

            $gym = $this->createMirrorGym($data);
            $created = true;
        } elseif (! $this->isMirror($gym->id) && Member::where('gym_id', $gym->id)->exists()) {
            // A replace import wipes the members. Never do that to a gym that
            // holds data of its own.
            throw new \InvalidArgumentException(
                'Dieses Studio hat eigene Mitglieder und ist kein Spiegel. Der Import würde sie ersetzen.'
            );
        }

        // The portal derives the slug from the name whenever a gym is saved. A
        // mirror keeps the slug it was set up with, tokens and scripts refer to it.
        $slug = $gym->slug;

        $stats = DB::transaction(fn () => $this->importService->importGymData($gym->id, $this->withLocalTimes($data), 'replace'));

        Gym::whereKey($gym->id)->where('slug', '!=', $slug)->toBase()->update(['slug' => $slug]);

        $portalIds = [];

        foreach ($data['members'] ?? [] as $member) {
            if (! empty($member['member_number']) && ! empty($member['export_id'])) {
                $portalIds[$member['member_number']] = Str::after((string) $member['export_id'], 'member_');
            }
        }

        $exportedAt = $data['export_metadata']['exported_at'] ?? null;

        $sync = AgentMirrorSync::create([
            'gym_id' => $gym->id,
            'source' => $source,
            'content_hash' => hash('sha256', json_encode($data)),
            'exported_at' => $exportedAt ? Carbon::parse($exportedAt)->setTimezone(config('app.timezone')) : null,
            'stats' => $stats,
            'portal_member_ids' => $portalIds,
        ]);

        $sync->update(['actions_closed' => $this->reconcile($gym->id)]);

        return ['gym' => $gym->fresh(), 'sync' => $sync->fresh(), 'created' => $created];
    }

    /**
     * Closes every open action whose effect the fresh data already shows.
     */
    public function reconcile(int $gymId): int
    {
        $closed = 0;

        $open = AgentPendingAction::where('gym_id', $gymId)->where('status', AgentPendingAction::STATUS_OPEN)->get();

        foreach ($open as $action) {
            $member = $action->member_number
                ? Member::where('gym_id', $gymId)->where('member_number', $action->member_number)->first()
                : null;

            if ($member && $this->effectVisible($action, $member)) {
                $action->close(AgentPendingAction::STATUS_DONE, 'sync');
                $closed++;
            }
        }

        return $closed;
    }

    private function effectVisible(AgentPendingAction $action, Member $member): bool
    {
        $memberships = $member->memberships()->get();
        $payload = $action->payload ?? [];

        return match ($action->type) {
            'cancel_contract' => $memberships->contains(fn ($m) => $m->cancellation_date !== null),
            'revoke_cancellation' => $memberships->isNotEmpty()
                && ! $memberships->contains(fn ($m) => $m->cancellation_date !== null && in_array($m->status, ['active', 'paused'], true)),
            'pause_contract' => $memberships->contains(
                fn ($m) => $m->getRawOriginal('pause_start_date') !== null
                    && substr((string) $m->getRawOriginal('pause_start_date'), 0, 10) === ($payload['start_date'] ?? null)
            ),
            'resume_contract' => $memberships->isNotEmpty()
                && ! $memberships->contains(fn ($m) => $m->status === 'paused' || $m->getRawOriginal('pause_start_date') !== null),
            'update_member' => $payload !== [] && collect($payload)->every(
                fn ($value, $field) => mb_strtolower((string) $member->{$field}) === mb_strtolower((string) $value)
            ),
            'add_note' => isset($payload['text']) && str_contains((string) $member->notes, (string) $payload['text']),
            default => false,
        };
    }

    /**
     * The export writes visit times in UTC ("...Z"), the import stores them as
     * they are, which the application then reads as local time: every visit
     * would move by one or two hours. Convert them to local time up front.
     */
    private function withLocalTimes(array $data): array
    {
        $timezone = config('app.timezone');

        foreach ($data['members'] ?? [] as $m => $member) {
            foreach ($member['check_ins'] ?? [] as $c => $checkIn) {
                foreach (['check_in_time', 'check_out_time'] as $field) {
                    if (! empty($checkIn[$field])) {
                        $data['members'][$m]['check_ins'][$c][$field] = Carbon::parse($checkIn[$field])
                            ->setTimezone($timezone)
                            ->format('Y-m-d H:i:s');
                    }
                }
            }
        }

        return $data;
    }

    private function createMirrorGym(array $data): Gym
    {
        $settings = $data['gym_settings'] ?? [];
        $name = $settings['name'] ?? ($data['export_metadata']['gym_name'] ?? 'Spiegel');
        $slug = $data['export_metadata']['gym_slug'] ?? Str::slug($name);

        if (Gym::where('slug', $slug)->exists()) {
            throw new \InvalidArgumentException('Ein Studio mit dem Kürzel "'.$slug.'" gibt es schon. Bitte --gym angeben.');
        }

        // Nobody logs in as this user; a gym just cannot exist without an owner.
        $owner = User::create([
            'first_name' => 'Spiegel',
            'last_name' => $name,
            'email' => 'mirror+'.$slug.'@agent-api.invalid',
            'password' => Hash::make(Str::random(64)),
            // The least privileged role there is; a blocked account on top.
            'role_id' => Role::firstOrCreate(['slug' => 'mirror'], ['name' => 'Spiegel'])->id,
            'is_blocked' => true,
            'blocked_reason' => 'Technischer Besitzer eines Spiegels, keine Anmeldung.',
        ]);

        $gym = Gym::create([
            'name' => $name,
            'slug' => $slug,
            'owner_id' => $owner->id,
            // Required columns; the import overwrites them from the export right after.
            'address' => $settings['address'] ?? '-',
            'city' => $settings['city'] ?? '-',
            'postal_code' => $settings['postal_code'] ?? '-',
            'country' => $settings['country'] ?? 'DE',
            'phone' => $settings['phone'] ?? '-',
            'email' => $settings['email'] ?? 'mirror+'.$slug.'@agent-api.invalid',
            'scanner_secret_key' => Str::random(64),
        ]);

        Gym::whereKey($gym->id)->toBase()->update(['slug' => $slug]);

        return $gym->fresh();
    }
}
