<?php

namespace Tests\Feature\Widget;

use App\Models\Gym;
use App\Models\GymLegalUrl;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\WidgetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A contract concluded in the widget keeps evidence of the legal texts the
 * customer confirmed: when, from where, and which documents were linked.
 */
class WidgetLegalAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private Gym $gym;

    private MembershipPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow('2026-11-22 10:15:00');

        $this->gym = Gym::factory()->create(['contracts_start_first_of_month' => false, 'widget_enabled' => true]);
        $this->plan = MembershipPlan::factory()->create(['gym_id' => $this->gym->id]);

        foreach ([
            GymLegalUrl::TYPE_TERMS_AND_CONDITIONS => 'https://studio.example/agb/',
            GymLegalUrl::TYPE_CANCELLATION_POLICY => 'https://studio.example/widerruf/',
            GymLegalUrl::TYPE_PRIVACY_POLICY => 'https://studio.example/datenschutz/',
        ] as $type => $url) {
            GymLegalUrl::create(['gym_id' => $this->gym->id, 'type' => $type, 'url' => $url]);
        }
    }

    private function register(array $overrides = []): Membership
    {
        $result = app(WidgetService::class)->processRegistration($this->gym, array_merge([
            'plan_id' => $this->plan->id,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'email' => 'max'.uniqid().'@example.com',
            'phone' => '0123456789',
            'birth_date' => '1990-01-01',
            'country' => 'DE',
            'payment_method' => 'cash',
            'widget_session' => 'sess-legal',
            'selected_addons' => [],
        ], $overrides));

        return Membership::findOrFail($result['membership']['id']);
    }

    #[Test]
    public function a_confirmed_checkout_stores_the_linked_documents(): void
    {
        $membership = $this->register(['legal_texts_acknowledged' => true]);

        $proof = $membership->metadata['legal_acknowledgement'] ?? null;

        $this->assertNotNull($proof);
        // App timezone is Europe/Berlin
        $this->assertSame('2026-11-22T10:15:00+01:00', $proof['acknowledged_at']);
        $this->assertSame('widget', $proof['source']);
        $this->assertSame('sess-legal', $proof['widget_session']);
        $this->assertSame([
            'cancellation_policy' => 'https://studio.example/widerruf/',
            'privacy_policy' => 'https://studio.example/datenschutz/',
            'terms_and_conditions' => 'https://studio.example/agb/',
        ], collect($proof['documents'])->sortKeys()->all());
    }

    #[Test]
    public function without_the_flag_nothing_is_recorded(): void
    {
        $membership = $this->register();

        $this->assertArrayNotHasKey('legal_acknowledgement', $membership->metadata ?? []);
    }

    #[Test]
    public function a_later_change_of_a_link_does_not_alter_the_evidence(): void
    {
        $membership = $this->register(['legal_texts_acknowledged' => true]);

        GymLegalUrl::where('gym_id', $this->gym->id)
            ->where('type', GymLegalUrl::TYPE_TERMS_AND_CONDITIONS)
            ->update(['url' => 'https://studio.example/agb-2027/']);

        $documents = $membership->fresh()->metadata['legal_acknowledgement']['documents'];

        $this->assertSame('https://studio.example/agb/', $documents['terms_and_conditions']);
    }

    private function postContract(string $session, array $overrides = [])
    {
        return $this->withHeaders([
            'X-API-Key' => $this->gym->api_key,
            'X-Studio-ID' => (string) $this->gym->id,
            'X-Widget-Session' => $session,
        ])->postJson('/api/widget/contracts', array_merge([
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'email' => 'max@example.com',
            'phone' => '0123456789',
            'birth_date' => '1990-01-01',
            'plan_id' => $this->plan->id,
            'payment_method' => 'cash',
        ], $overrides));
    }

    #[Test]
    public function the_contract_endpoint_passes_the_confirmation_on(): void
    {
        $this->postContract('sess-endpoint', ['legal_texts_acknowledged' => true])
            ->assertOk()
            ->assertJsonPath('success', true);

        $proof = Membership::sole()->metadata['legal_acknowledgement'] ?? null;

        $this->assertSame('sess-endpoint', $proof['widget_session'] ?? null);
        $this->assertSame('https://studio.example/agb/', $proof['documents']['terms_and_conditions'] ?? null);
    }

    #[Test]
    public function the_contract_endpoint_refuses_a_declined_confirmation(): void
    {
        $this->postContract('sess-declined', ['legal_texts_acknowledged' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['legal_texts_acknowledged'], 'errors');

        $this->assertSame(0, Membership::count());
    }
}
