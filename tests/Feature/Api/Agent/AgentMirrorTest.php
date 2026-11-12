<?php

namespace Tests\Feature\Api\Agent;

use App\Models\AgentPendingAction;
use App\Models\CheckIn;
use App\Models\Gym;
use App\Models\GymApiClient;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\AgentApi\MirrorService;
use App\Services\GymDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mirror mode: a gym fed by the studio export of another installation. The
 * "portal" in these tests is a second gym in the same database; its export is
 * what a studio owner downloads from Settings > data transfer.
 */
class AgentMirrorTest extends TestCase
{
    use RefreshDatabase;

    private Gym $portal;

    private Member $portalMember;

    private Membership $portalMembership;

    private Gym $mirror;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->portal = Gym::factory()->create(['name' => 'Studio Eins']);

        $plan = MembershipPlan::factory()->create([
            'gym_id' => $this->portal->id,
            'name' => 'Jahresvertrag',
            'price' => 29.90,
            'commitment_months' => 12,
            'cancellation_period' => 1,
            'cancellation_period_unit' => 'months',
        ]);

        $this->portalMember = Member::factory()->create([
            'gym_id' => $this->portal->id,
            'status' => 'active',
            'member_number' => 'S-0001',
            'email' => 'mia@example.org',
            'city' => 'Berlin',
        ]);

        $this->portalMembership = Membership::factory()->create([
            'member_id' => $this->portalMember->id,
            'membership_plan_id' => $plan->id,
            'start_date' => now()->subMonths(3)->toDateString(),
        ]);

        CheckIn::factory()->count(4)->create([
            'gym_id' => $this->portal->id,
            'member_id' => $this->portalMember->id,
            'check_in_time' => now()->subDays(3),
        ]);

        Member::factory()->count(2)->create(['gym_id' => $this->portal->id, 'status' => 'active']);

        $result = app(MirrorService::class)->import($this->export(), null, 'test', createIfMissing: true);

        $this->mirror = $result['gym'];
        $this->token = GymApiClient::issue($this->mirror, 'Spiegelagent', 'Tests', GymApiClient::ABILITIES)[1];
    }

    /** What the studio owner would download right now. */
    private function export(): array
    {
        $data = app(GymDataExportService::class)->exportGymData($this->portal->id);
        $data = json_decode(json_encode($data), true);
        // Another installation: the mirror must not collide with the portal gym.
        $data['export_metadata']['gym_slug'] = 'spiegel-studio-eins';

        return $data;
    }

    private function agent(?string $token = null): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json']);
    }

    private function url(string $path): string
    {
        return '/api/v1/agent/'.ltrim($path, '/');
    }

    private function mirrored(): Member
    {
        return Member::where('gym_id', $this->mirror->id)->where('member_number', 'S-0001')->firstOrFail();
    }

    #[Test]
    public function an_export_becomes_a_gym_with_the_same_members_contracts_and_visits(): void
    {
        $this->assertNotSame($this->portal->id, $this->mirror->id);
        $this->assertSame('spiegel-studio-eins', $this->mirror->slug);
        $this->assertSame(3, Member::where('gym_id', $this->mirror->id)->count());
        $this->assertSame(4, CheckIn::where('gym_id', $this->mirror->id)->count());

        $this->agent()->getJson($this->url('health'))->assertOk()->assertJsonPath('data.mode', 'mirror');

        $contract = $this->agent()->getJson($this->url('members/'.$this->mirrored()->id.'/contract'))->assertOk()->json('data.active_membership');

        $this->assertSame('Jahresvertrag', $contract['plan']['name']);
        // The mirror computes the same date the portal would.
        $this->assertSame($this->portalMembership->fresh()->nextPossibleCancellationDate(), $contract['cancellation']['next_possible_date']);
    }

    #[Test]
    public function visits_keep_their_local_time_of_day(): void
    {
        CheckIn::where('gym_id', $this->portal->id)->delete();
        CheckIn::factory()->create([
            'gym_id' => $this->portal->id,
            'member_id' => $this->portalMember->id,
            'check_in_time' => '2026-07-15 18:30:00',
            'check_out_time' => '2026-07-15 19:45:00',
        ]);

        app(MirrorService::class)->import($this->export(), $this->mirror, 'test');

        $visit = CheckIn::where('gym_id', $this->mirror->id)->firstOrFail();

        $this->assertSame('2026-07-15 18:30:00', (string) $visit->getRawOriginal('check_in_time'));
        $this->assertSame('2026-07-15 19:45:00', (string) $visit->getRawOriginal('check_out_time'));
    }

    #[Test]
    public function a_mirror_does_not_invent_access_blockers_the_export_cannot_know(): void
    {
        $this->agent()->getJson($this->url('members/'.$this->mirrored()->id.'/access'))
            ->assertOk()
            ->assertJsonPath('data.should_have_access', true)
            ->assertJsonPath('data.access_medium_known', false);
    }

    #[Test]
    public function a_confirmed_write_is_queued_and_the_mirror_stays_untouched(): void
    {
        $id = $this->mirrored()->id;

        $date = $this->agent()->postJson($this->url("members/{$id}/contract/cancellation"), ['reason' => 'move'])
            ->assertOk()->assertJsonPath('data.preview', true)->json('data.cancellation.effective_date');
        $this->assertSame(0, AgentPendingAction::count());

        $this->agent()->postJson($this->url("members/{$id}/contract/cancellation"), ['reason' => 'move', 'confirm' => true, 'expected_effective_date' => $date])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.action.type', 'cancel_contract')
            ->assertJsonPath('data.action.member_number', 'S-0001')
            ->assertJsonPath('data.action.portal_member_id', (string) $this->portalMember->id);

        $this->assertNull($this->mirrored()->memberships()->first()->cancellation_date);

        $this->agent()->patchJson($this->url("members/{$id}"), ['city' => 'Potsdam', 'confirm' => true])->assertStatus(202);
        $this->assertSame('Berlin', $this->mirrored()->city);

        $this->agent()->getJson($this->url('actions'))->assertOk()->assertJsonPath('data.count', 2);
    }

    #[Test]
    public function the_next_export_closes_actions_whose_effect_it_shows(): void
    {
        $id = $this->mirrored()->id;

        $date = $this->agent()->postJson($this->url("members/{$id}/contract/cancellation"))->json('data.cancellation.effective_date');
        $this->agent()->postJson($this->url("members/{$id}/contract/cancellation"), ['confirm' => true, 'expected_effective_date' => $date])->assertStatus(202);
        $this->agent()->patchJson($this->url("members/{$id}"), ['city' => 'Potsdam', 'confirm' => true])->assertStatus(202);

        // The owner carries out only the cancellation in the portal.
        $this->portalMembership->update(['cancellation_date' => now()->addMonths(10)->toDateString()]);

        $result = app(MirrorService::class)->import($this->export(), $this->mirror, 'test');

        $this->assertSame(1, $result['sync']->actions_closed);
        $this->assertSame(
            ['cancel_contract' => 'done', 'update_member' => 'open'],
            AgentPendingAction::orderBy('id')->pluck('status', 'type')->all(),
        );

        // Token and data survive the replace import.
        $this->agent()->getJson($this->url('members/lookup?member_number=S-0001'))->assertOk()->assertJsonPath('data.match', 'unique');
        $this->agent()->getJson($this->url('members/'.$this->mirrored()->id.'/contract'))
            ->assertOk()->assertJsonPath('data.active_membership.cancellation.is_cancelled', true);
        $this->agent()->getJson($this->url('mirror'))->assertOk()->assertJsonPath('data.open_actions', 1);
    }

    #[Test]
    public function an_action_can_be_ticked_off_by_hand_once(): void
    {
        $this->agent()->postJson($this->url('members/'.$this->mirrored()->id.'/notes'), ['text' => 'Rückruf', 'confirm' => true])->assertStatus(202);
        $action = AgentPendingAction::firstOrFail();

        $this->agent()->postJson($this->url("actions/{$action->id}/close"), ['status' => 'done'])
            ->assertOk()->assertJsonPath('data.action.closed_by', 'manual');
        $this->agent()->postJson($this->url("actions/{$action->id}/close"), ['status' => 'done'])->assertStatus(409);

        $other = GymApiClient::issue($this->portal, 'Fremd', 'Tests', GymApiClient::ABILITIES)[1];
        $this->agent($other)->getJson($this->url('actions?status=all'))->assertOk()->assertJsonPath('data.count', 0);
    }

    #[Test]
    public function an_export_can_be_pushed_over_the_api_with_the_mirror_ability(): void
    {
        Member::factory()->create(['gym_id' => $this->portal->id, 'status' => 'active', 'member_number' => 'S-0099']);

        $readOnly = GymApiClient::issue($this->mirror, 'Leser', 'Tests', GymApiClient::READ_ABILITIES)[1];
        $this->agent($readOnly)->postJson($this->url('mirror/import'), $this->export())->assertStatus(403);
        $this->agent()->postJson($this->url('mirror/import'), ['foo' => 'bar'])->assertStatus(422);

        $this->agent()->postJson($this->url('mirror/import'), $this->export())
            ->assertOk()->assertJsonPath('data.stats.members', 4);

        $this->assertSame(4, Member::where('gym_id', $this->mirror->id)->count());
    }

    #[Test]
    public function a_gym_with_members_of_its_own_is_never_overwritten(): void
    {
        $before = Member::where('gym_id', $this->portal->id)->count();

        try {
            app(MirrorService::class)->import($this->export(), $this->portal, 'test');
            $this->fail('The import should have been refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('kein Spiegel', $e->getMessage());
        }

        $this->assertSame($before, Member::where('gym_id', $this->portal->id)->count());

        // And the live gym keeps executing writes directly.
        $live = GymApiClient::issue($this->portal, 'Live', 'Tests', GymApiClient::ABILITIES)[1];
        $this->agent($live)->postJson($this->url("members/{$this->portalMember->id}/contract/cancellation"), ['confirm' => true, 'expected_effective_date' => $this->portalMembership->fresh()->nextPossibleCancellationDate()])
            ->assertOk()->assertJsonPath('data.preview', false);
        $this->assertNotNull($this->portalMembership->fresh()->cancellation_date);
    }

    #[Test]
    public function the_command_imports_a_file_and_reports_the_mirror(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gymexport').'.json';
        file_put_contents($file, json_encode($this->export()));

        $this->artisan('agent:mirror', ['action' => 'import', 'file' => $file])->assertSuccessful();
        $this->artisan('agent:mirror', ['action' => 'status', '--gym' => 'spiegel-studio-eins'])->assertSuccessful();
        $this->artisan('agent:mirror', ['action' => 'import', 'file' => '/nonexistent.json'])->assertFailed();

        unlink($file);
    }
}
