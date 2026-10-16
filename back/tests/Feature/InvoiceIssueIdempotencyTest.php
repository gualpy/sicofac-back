<?php

namespace Tests\Feature;

use App\DTOs\Billing\InvoicePipelineData;
use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\DocumentSequenceService;
use App\Services\Billing\InvoiceDraftService;
use App\Services\Billing\InvoiceEmissionService;
use App\Services\Billing\InvoiceIssueService;
use App\Services\Billing\InvoiceTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * G1 (idempotent create+emit) and G2 (fiscal number taken at emission).
 */
class InvoiceIssueIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create(['name' => 'Svc', 'email' => 'svc@example.com', 'password' => 'password']);
        $this->company = $this->makeCompany('0999999999001', $this->user);
        Sanctum::actingAs($this->user);
    }

    private function makeCompany(string $ruc, User $owner, string $role = 'owner'): Company
    {
        $company = Company::query()->create([
            'name' => "Gym {$ruc}",
            'trade_name' => 'Gym',
            'ruc' => $ruc,
            'environment' => 'test',
            'sri_signing_enabled' => false,
            'sri_submission_enabled' => false,
        ]);
        $owner->companies()->attach($company->id, [
            'role' => $role,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        return $company;
    }

    /** @return array<string, mixed> */
    private function payload(string $reference = 'fitos:sale:1', array $override = []): array
    {
        return array_replace([
            'external_reference' => $reference,
            'document_code' => '01',
            'buyer' => [
                'identification_type' => '05',
                'identification_number' => '0912345678',
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
            ],
            'items' => [
                ['code' => 'PLAN-1', 'name' => 'Plan Mensual', 'quantity' => 2, 'unit_price' => 20, 'discount' => 0, 'tax_rate' => 15],
            ],
            'payment_methods' => [['method' => 'tarjeta_credito', 'value' => 46]],
        ], $override);
    }

    private function issue(array $payload, ?string $key = 'key-0000-0001', ?Company $company = null)
    {
        $company ??= $this->company;

        return $this->postJson(
            "/api/v1/companies/{$company->id}/invoices/issue",
            $payload,
            $key === null ? [] : ['Idempotency-Key' => $key],
        );
    }

    private function draftPayload(): array
    {
        return [
            'items' => [['code' => 'X', 'name' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 15]],
        ];
    }

    // ---------------------------------------------------------------- G2

    public function test_draft_has_no_sequential_and_consumes_no_number(): void
    {
        $response = $this->postJson("/api/companies/{$this->company->id}/invoices", $this->draftPayload())->assertCreated();

        $this->assertNull($response->json('sequential'));
        $this->assertSame(0, DocumentSequence::query()->count());
    }

    public function test_abandoned_and_deleted_drafts_leave_no_gap_in_numbering(): void
    {
        $base = "/api/companies/{$this->company->id}/invoices";
        $abandoned = $this->postJson($base, $this->draftPayload())->json('id');
        $deleted = $this->postJson($base, $this->draftPayload())->json('id');
        $first = $this->postJson($base, $this->draftPayload())->json('id');
        $second = $this->postJson($base, $this->draftPayload())->json('id');

        $this->deleteJson("{$base}/{$deleted}")->assertNoContent();
        $this->postJson("{$base}/{$second}/emit")->assertOk(); // emitted out of creation order
        $this->postJson("{$base}/{$first}/emit")->assertOk();

        $this->assertSame(1, Invoice::query()->findOrFail($second)->sequential);
        $this->assertSame(2, Invoice::query()->findOrFail($first)->sequential);
        $this->assertNull(Invoice::query()->findOrFail($abandoned)->sequential);
    }

    public function test_numbers_are_tracked_per_establishment_and_emission_point(): void
    {
        $base = "/api/companies/{$this->company->id}/invoices";
        $a = $this->postJson($base, $this->draftPayload())->json('id');
        $b = $this->postJson($base, $this->draftPayload() + ['emission_point' => '002'])->json('id');

        $this->postJson("{$base}/{$a}/emit")->assertOk();
        $this->postJson("{$base}/{$b}/emit")->assertOk();

        $this->assertSame(1, Invoice::query()->findOrFail($a)->sequential);
        $this->assertSame(1, Invoice::query()->findOrFail($b)->sequential);
    }

    public function test_re_emitting_does_not_consume_another_number(): void
    {
        $base = "/api/companies/{$this->company->id}/invoices";
        $id = $this->postJson($base, $this->draftPayload())->json('id');
        $this->postJson("{$base}/{$id}/emit")->assertOk();

        $this->postJson("{$base}/{$id}/emit")->assertStatus(409);

        $this->assertSame(1, Invoice::query()->findOrFail($id)->sequential);
        $this->assertSame(2, (int) DocumentSequence::query()->firstOrFail()->next_number);
    }

    // ---------------------------------------------------------------- G1

    public function test_issue_creates_and_emits_with_contract_shape(): void
    {
        $this->issue($this->payload())
            ->assertStatus(202)
            ->assertJsonPath('data.external_reference', 'fitos:sale:1')
            ->assertJsonPath('data.status', 'authorized') // sync queue + dummy SRI driver
            ->assertJsonPath('data.sequential', 1)
            ->assertJsonPath('data.document_code', '01')
            ->assertJsonPath('data.totals.subtotal', 40)
            ->assertJsonPath('data.totals.tax', 6)
            ->assertJsonPath('data.totals.total', 46)
            ->assertJsonPath('data.error', null)
            ->assertJsonStructure(['data' => ['id', 'access_key', 'authorization_number', 'authorized_at', 'ride_url']]);

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_same_key_and_body_returns_the_same_invoice_without_duplicating(): void
    {
        $first = $this->issue($this->payload())->assertStatus(202);

        // Same body with keys in a different order must hash identically.
        $second = $this->issue(array_reverse($this->payload(), true))->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, Invoice::query()->firstOrFail()->sequential);
        $this->assertSame(2, (int) DocumentSequence::query()->firstOrFail()->next_number);
    }

    public function test_same_key_with_a_different_body_is_rejected(): void
    {
        $this->issue($this->payload())->assertStatus(202);

        $changed = $this->payload('fitos:sale:1', ['items' => [
            ['code' => 'PLAN-1', 'name' => 'Plan Mensual', 'quantity' => 3, 'unit_price' => 20, 'discount' => 0, 'tax_rate' => 15],
        ]]);

        $this->issue($changed)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_same_external_reference_under_another_key_is_a_conflict(): void
    {
        $this->issue($this->payload(), 'key-0000-0001')->assertStatus(202);

        $this->issue($this->payload(), 'key-0000-0002')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'external_reference_conflict');

        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_missing_or_malformed_idempotency_key_is_rejected(): void
    {
        $this->issue($this->payload(), null)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->issue($this->payload(), 'short')->assertStatus(422);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_invalid_body_uses_the_contract_error_format(): void
    {
        $this->issue(['external_reference' => 'fitos:sale:9', 'items' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['items']]]);
    }

    public function test_missing_buyer_means_consumidor_final(): void
    {
        $payload = $this->payload();
        unset($payload['buyer']);

        $this->issue($payload)->assertStatus(202);

        $this->assertNull(Invoice::query()->firstOrFail()->customer_id);
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_existing_buyer_is_reused_not_duplicated(): void
    {
        $this->issue($this->payload('fitos:sale:1'), 'key-0000-0001')->assertStatus(202);
        $this->issue($this->payload('fitos:sale:2'), 'key-0000-0002')->assertStatus(202);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame([1, 2], Invoice::query()->orderBy('id')->pluck('sequential')->all());
    }

    public function test_totals_mismatch_issues_nothing_and_burns_no_number(): void
    {
        $payload = $this->payload('fitos:sale:1', ['expected_totals' => ['subtotal' => 40, 'tax' => 4.8, 'total' => 44.8]]);

        $this->issue($payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'totals_mismatch')
            ->assertJsonPath('error.details.tax.calculated', 6);

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, DocumentSequence::query()->count());
    }

    public function test_matching_expected_totals_are_accepted(): void
    {
        $payload = $this->payload('fitos:sale:1', ['expected_totals' => ['subtotal' => 40, 'tax' => 6, 'total' => 46]]);

        $this->issue($payload)->assertStatus(202);
    }

    public function test_retry_resumes_a_draft_left_behind_by_a_crash_between_create_and_emit(): void
    {
        $crashing = new class(
            app(InvoiceTotalsCalculator::class),
            app(AuditLogger::class),
            app(DocumentSequenceService::class),
        ) extends InvoiceEmissionService {
            public function dispatch(Invoice $invoice): InvoicePipelineData
            {
                throw new RuntimeException('worker died');
            }
        };

        $payload = $this->payload();
        $this->company->refresh(); // load DB defaults (establishment_code, emission_point), as route binding does
        $service = new InvoiceIssueService(app(InvoiceDraftService::class), $crashing);

        try {
            $service->issue($this->company, $payload, 'key-0000-0001');
            $this->fail('expected the simulated crash');
        } catch (RuntimeException) {
        }

        $draft = Invoice::query()->firstOrFail();
        $this->assertSame(InvoiceStatus::Draft, $draft->status);
        $this->assertNull($draft->sequential);
        $this->assertSame(0, DocumentSequence::query()->count()); // crash burned no number

        $retry = $this->issue($payload)->assertOk();

        $this->assertSame($draft->id, $retry->json('data.id'));
        $this->assertSame('authorized', $retry->json('data.status'));
        $this->assertSame(1, $retry->json('data.sequential'));
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_keys_and_references_are_scoped_per_company(): void
    {
        $other = $this->makeCompany('0888888888001', $this->user);

        $a = $this->issue($this->payload(), 'key-0000-0001', $this->company)->assertStatus(202);
        $b = $this->issue($this->payload(), 'key-0000-0001', $other)->assertStatus(202);

        $this->assertNotSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, $a->json('data.sequential'));
        $this->assertSame(1, $b->json('data.sequential'));
    }

    public function test_by_reference_returns_the_invoice_for_reconciliation(): void
    {
        $created = $this->issue($this->payload())->assertStatus(202);

        $this->getJson("/api/v1/companies/{$this->company->id}/invoices/by-reference/".rawurlencode('fitos:sale:1'))
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'))
            ->assertJsonPath('data.status', 'authorized');

        $this->getJson("/api/v1/companies/{$this->company->id}/invoices/by-reference/unknown")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'invoice_not_found');
    }

    public function test_non_members_and_sellers_cannot_issue_or_read(): void
    {
        $stranger = User::query()->create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'password']);
        $seller = User::query()->create(['name' => 'S', 'email' => 's@example.com', 'password' => 'password']);
        $this->company->users()->attach($seller->id, [
            'role' => CompanyMembershipRole::Seller->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);
        $this->issue($this->payload())->assertStatus(202);

        foreach ([$stranger, $seller] as $actor) {
            Sanctum::actingAs($actor);
            $this->issue($this->payload('fitos:sale:2'), 'key-0000-0002')->assertForbidden();
        }

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/companies/{$this->company->id}/invoices/by-reference/".rawurlencode('fitos:sale:1'))
            ->assertForbidden();

        $this->assertSame(1, Invoice::query()->count());
    }
}
