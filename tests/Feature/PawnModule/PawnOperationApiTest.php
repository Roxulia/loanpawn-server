<?php

namespace Tests\Feature\PawnModule;

use App\Models\CoreModule\ExpenseType;
use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\ItemCategoryType;
use App\Models\CoreModule\MaterialType;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PawnModule\PawnCollateralItem;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Models\TenantAccountingTransactions;
use Carbon\CarbonImmutable;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PawnOperationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackageSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_tenant_user_can_create_calculate_pay_and_redeem_slip_via_api(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 09:30:00'));

        [$tenant, $tenantUser] = $this->tenantUserContext();
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);
        $itemCategoryType = ItemCategoryType::query()->create([
            'tenant_id' => null,
            'code' => 'watches',
            'name' => 'Watches',
            'is_default' => true,
        ]);

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $created = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/loan-contract-slips', [
                'customer' => [
                    'name' => 'API Customer',
                    'phone' => '09900000001',
                ],
                'collateral_items' => [
                    [
                        'type' => 'Normal',
                        'name' => 'Laptop',
                        'item_category_type_id' => $itemCategoryType->id,
                        'estimated_value' => 900000,
                        'item_status' => 'pawned',
                        'quantity' => 1,
                        'minimum_retail_price' => 1000000,
                    ],
                ],
                'loan_amount' => 500000,
                'interest_rate' => 5,
                'interest_type_id' => $interestType->id,
                'expiry_quota' => 3,
                'expiry_quota_type' => 'Month',
            ]);

        $created->assertCreated()
            ->assertJsonPath('data.slip_no', 'LS202604api-tenant001');

        $slipNo = $created->json('data.slip_no');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson("/api/tenant/loan-contract-slips/{$slipNo}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'API Customer');

        $interest = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson("/api/tenant/interest-payments/{$slipNo}/calculate");

        $interest->assertOk()
            ->assertJsonPath('data.total_interest_amount', 25000);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson("/api/tenant/interest-payments/{$slipNo}/pay", [
                'slip_update_key' => $interest->json('data.slip_update_key'),
                'payment_amount' => 25000,
                'record_debt' => false,
                'interest_breakdown' => $interest->json('data.interest_breakdown'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson('/api/tenant/interest-payments?per_page=5')
            ->assertOk()
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.per_page', 5)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.slip_no', $slipNo)
            ->assertJsonPath('data.items.0.payment_amount', 25000);

        $redemptionResult = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson("/api/tenant/redemptions/{$slipNo}/calculate");

        $redemptionResult->assertOk()
            ->assertJsonPath('data.slip.slip_no', $slipNo)
            ->assertJsonPath('data.total_amount_to_pay', 500000);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/redemptions', [
                'slip_no' => $slipNo,
                'calculated_total' => 500000,
                'payment_amount' => 510000,
                'interests' => $redemptionResult->json('data.interest_payments') ?? [],
                'debts' => $redemptionResult->json('data.debts') ?? [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.net_amount', 500000)
            ->assertJsonPath('data.change_amount', 10000);

        $this->assertDatabaseHas('pawn_loan_contract_slips', [
            'tenant_id' => $tenant->id,
            'slip_no' => $slipNo,
            'status' => 'redeemed',
        ]);
        $this->assertDatabaseHas('pawn_collateral_items', [
            'tenant_id' => $tenant->id,
            'name' => 'Laptop',
            'item_category_type_id' => $itemCategoryType->id,
        ]);
        $this->assertDatabaseHas('tenant_accounting_transactions', [
            'tenant_id' => $tenant->id,
            'description' => 'Redemption Transaction',
            'transaction_direction' => 'incoming',
            'amount' => '510000.00',
            'reference_type' => 'App\\Models\\PawnModule\\PawnRedemption',
        ]);
        $this->assertDatabaseHas('tenant_accounting_transactions', [
            'tenant_id' => $tenant->id,
            'description' => 'Redemption Change Transaction',
            'transaction_direction' => 'outgoing',
            'amount' => '10000.00',
            'reference_type' => 'App\\Models\\PawnModule\\PawnRedemption',
        ]);
        $this->assertSame(
            2,
            TenantAccountingTransactions::query()
                ->where('tenant_id', $tenant->id)
                ->where('reference_type', 'App\\Models\\PawnModule\\PawnRedemption')
                ->count()
        );
    }

    public function test_backend_calculates_jewellery_minimum_retail_price_before_insert(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 09:30:00'));

        [$tenant, $tenantUser] = $this->tenantUserContext();
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);
        $materialType = MaterialType::query()->create([
            'tenant_id' => null,
            'code' => 'gold',
            'name' => 'Gold',
            'is_default' => true,
        ]);

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/loan-contract-slips', [
                'customer' => [
                    'name' => 'Jewellery Customer',
                    'phone' => '09900000004',
                ],
                'collateral_items' => [
                    [
                        'type' => 'Jewellery',
                        'name' => 'Gold Bracelet Set',
                        'brand_name' => 'None',
                        'material_type_id' => $materialType->id,
                        'material_price_per_kyat' => 1000000,
                        'kyat' => 1,
                        'pal' => 8,
                        'yway' => 0,
                        'item_status' => 'pawned',
                        'quantity' => 2,
                        'minimum_retail_price' => 1,
                    ],
                ],
                'loan_amount' => 1000000,
                'interest_rate' => 5,
                'interest_type_id' => $interestType->id,
                'expiry_quota' => 3,
                'expiry_quota_type' => 'Month',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('pawn_collateral_items', [
            'tenant_id' => $tenant->id,
            'type' => 'Jewellery',
            'name' => 'Gold Bracelet Set',
            'quantity' => 2,
            'minimum_retail_price' => '3000000.00',
        ]);
    }

    public function test_tenant_user_can_manage_finance_records_via_api(): void
    {
        [$tenant, $tenantUser] = $this->tenantUserContext();
        $expenseType = ExpenseType::query()->create([
            'tenant_id' => null,
            'code' => 'ops',
            'name' => 'Operations',
            'is_default' => true,
        ]);

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $expense = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/expenses', [
                'description' => 'Office rent',
                'amount' => 500000,
                'expense_type_id' => $expenseType->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.description', 'Office rent');

        $expenseId = $expense->json('data.id');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->putJson("/api/tenant/expenses/{$expenseId}", [
                'description' => 'Office rent April',
            ])
            ->assertOk()
            ->assertJsonPath('data.amount', '500000.00');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->putJson("/api/tenant/expenses/{$expenseId}", [
                'amount' => 550000,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['amount']]]);

        $debt = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/debts', [
                'description' => 'Manual debt',
                'amount' => 100000,
                'tag' => 'manual',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tag', 'manual');

        $debtId = $debt->json('data.id');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->putJson("/api/tenant/debts/{$debtId}", [
                'is_paid' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_paid', true);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson('/api/tenant/accounting')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/accounting', [
                'description' => 'Manual income',
                'transaction_type' => 'incoming',
                'amount' => 25000,
            ])
            ->assertMethodNotAllowed();

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->deleteJson('/api/tenant/accounting/1')
            ->assertNotFound();
    }

    public function test_tenant_user_can_attach_replace_remove_and_view_expense_reference_image(): void
    {
        Storage::fake('local');
        [$tenant, $tenantUser] = $this->tenantUserContext();
        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $created = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->post('/api/tenant/expenses', [
                'description' => 'Receipt-backed expense',
                'amount' => 1000,
                'image_reference' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.has_image_reference', true);

        $expenseCode = $created->json('data.code');
        $expenseId = $created->json('data.id');
        $storedPath = \App\Models\CoreModule\TenantExpense::query()->findOrFail($expenseId)->image_reference;
        Storage::disk('local')->assertExists($storedPath);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson("/api/tenant/expenses/{$expenseCode}")
            ->assertOk()
            ->assertJsonPath('data.has_image_reference', true)
            ->assertJsonPath('data.creator_name', 'API User')
            ->assertJsonStructure(['data' => ['image_reference_url', 'image_reference_url_expires_at']])
            ->assertJsonMissingPath('data.image_reference');

        $replacement = UploadedFile::fake()->image('replacement.png');
        $updated = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->post("/api/tenant/expenses/{$expenseCode}", [
                '_method' => 'PUT',
                'description' => 'Updated receipt-backed expense',
                'update_key' => 0,
                'image_reference' => $replacement,
            ])
            ->assertOk()
            ->assertJsonPath('data.amount', '1000.00');

        Storage::disk('local')->assertMissing($storedPath);
        $replacementPath = \App\Models\CoreModule\TenantExpense::query()->findOrFail($expenseId)->image_reference;
        Storage::disk('local')->assertExists($replacementPath);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->post("/api/tenant/expenses/{$expenseCode}", [
                '_method' => 'PUT',
                'update_key' => $updated->json('data.update_key'),
                'remove_image_reference' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('data.has_image_reference', false);

        Storage::disk('local')->assertMissing($replacementPath);
    }

    public function test_tenant_user_can_create_slip_with_private_collateral_reference_image(): void
    {
        Storage::fake('local');
        [$tenant, $tenantUser] = $this->tenantUserContext();
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'image-monthly',
            'name' => 'Image Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);
        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $created = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->withHeader('Idempotency-Key', 'slip-with-collateral-image')
            ->post('/api/tenant/loan-contract-slips', [
                'customer' => [
                    'name' => 'Image Customer',
                    'phone' => '09900000002',
                ],
                'collateral_items' => [[
                    'type' => 'Normal',
                    'name' => 'Camera-backed laptop',
                    'estimated_value' => 900000,
                    'quantity' => 1,
                    'minimum_retail_price' => 1000000,
                    'image_reference' => UploadedFile::fake()->image('collateral.webp'),
                ]],
                'loan_amount' => 500000,
                'interest_rate' => 5,
                'interest_type_id' => $interestType->id,
                'expiry_quota' => 3,
                'expiry_quota_type' => 'Month',
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.image_url', null)
            ->assertJsonPath('data.items.0.has_image_reference', true);

        $slipNo = $created->json('data.slip_no');
        $item = PawnCollateralItem::query()->where('loan_contract_id', $created->json('data.id'))->firstOrFail();
        Storage::disk('local')->assertExists($item->image_url);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson("/api/tenant/collateral-items/{$item->code}")
            ->assertOk()
            ->assertJsonPath('data.has_image_reference', true)
            ->assertJsonStructure(['data' => ['image_url', 'image_url_expires_at']])
            ->assertJsonMissing(['image_url' => $item->image_url]);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->deleteJson("/api/tenant/loan-contract-slips/{$slipNo}")
            ->assertOk();

        Storage::disk('local')->assertExists($item->image_url);
    }

    public function test_loan_contract_create_replays_same_idempotency_key_without_duplicate_slip(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 09:30:00'));

        [$tenant, $tenantUser] = $this->tenantUserContext();
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $payload = [
            'customer' => [
                'name' => 'Idempotent Customer',
                'phone' => '09900000002',
            ],
            'collateral_items' => [
                [
                    'type' => 'Normal',
                    'name' => 'Phone',
                    'estimated_value' => 300000,
                    'item_status' => 'pawned',
                    'quantity' => 1,
                    'minimum_retail_price' => 350000,
                ],
            ],
            'loan_amount' => 200000,
            'interest_rate' => 5,
            'interest_type_id' => $interestType->id,
            'expiry_quota' => 2,
            'expiry_quota_type' => 'Month',
        ];

        $first = $this->withHeaders([
            'X-Tenant-Code' => $tenant->tenant_code,
            'Idempotency-Key' => 'loan-slip-create-test-key',
        ])->postJson('/api/tenant/loan-contract-slips', $payload);

        $first->assertCreated()
            ->assertJsonPath('data.slip_no', 'LS202604api-tenant001');

        $second = $this->withHeaders([
            'X-Tenant-Code' => $tenant->tenant_code,
            'Idempotency-Key' => 'loan-slip-create-test-key',
        ])->postJson('/api/tenant/loan-contract-slips', $payload);

        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertExactJson($first->json());

        $this->assertDatabaseCount('pawn_loan_contract_slips', 1);
        $this->assertDatabaseCount('tenant_idempotency_keys', 1);
    }

    public function test_slip_expiry_must_be_longer_than_interest_duration(): void
    {
        [$tenant, $tenantUser] = $this->tenantUserContext();
        $dailyInterestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'duration-validation-daily',
            'name' => 'Daily',
            'duration_in_days' => 1,
            'is_default' => true,
        ]);
        $monthlyInterestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'duration-validation-monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $payload = [
            'customer' => [
                'name' => 'Duration Validation Customer',
                'phone' => '09900000021',
            ],
            'collateral_items' => [
                [
                    'type' => 'Normal',
                    'name' => 'Duration Validation Item',
                ],
            ],
            'loan_amount' => 100000,
            'interest_rate' => 5,
            'interest_type_id' => $dailyInterestType->id,
            'expiry_quota' => 1,
            'expiry_quota_type' => 'Day',
        ];

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 09:30:00'));

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/loan-contract-slips', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('data.code', 'INVALID_SLIP_EXPIRY_DURATION')
            ->assertJsonPath(
                'message',
                'Expiry duration must be longer than the selected interest duration. Please rechoose the interest type or expiry quota.'
            );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-04 09:30:00'));
        $payload['interest_type_id'] = $monthlyInterestType->id;
        $payload['expiry_quota_type'] = 'Month';

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/loan-contract-slips', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('data.code', 'INVALID_SLIP_EXPIRY_DURATION');

        $this->assertDatabaseCount('pawn_loan_contract_slips', 0);
    }

    public function test_idempotency_key_reuse_with_different_payload_is_rejected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 09:30:00'));

        [$tenant, $tenantUser] = $this->tenantUserContext();
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $payload = [
            'customer' => [
                'name' => 'Conflict Customer',
                'phone' => '09900000003',
            ],
            'collateral_items' => [
                [
                    'type' => 'Normal',
                    'name' => 'Tablet',
                    'estimated_value' => 300000,
                    'item_status' => 'pawned',
                    'quantity' => 1,
                    'minimum_retail_price' => 350000,
                ],
            ],
            'loan_amount' => 200000,
            'interest_rate' => 5,
            'interest_type_id' => $interestType->id,
            'expiry_quota' => 2,
            'expiry_quota_type' => 'Month',
        ];

        $this->withHeaders([
            'X-Tenant-Code' => $tenant->tenant_code,
            'Idempotency-Key' => 'loan-slip-conflict-test-key',
        ])->postJson('/api/tenant/loan-contract-slips', $payload)
            ->assertCreated();

        $payload['loan_amount'] = 250000;

        $this->withHeaders([
            'X-Tenant-Code' => $tenant->tenant_code,
            'Idempotency-Key' => 'loan-slip-conflict-test-key',
        ])->postJson('/api/tenant/loan-contract-slips', $payload)
            ->assertConflict()
            ->assertJsonPath('data.code', 'IDEMPOTENCY_KEY_CONFLICT');

        $this->assertDatabaseCount('pawn_loan_contract_slips', 1);
    }

    protected function tenantUserContext(): array
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'API Owner',
            'email' => 'api-owner@example.com',
            'phone' => '09999999999',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $tenant = Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'API Tenant',
            'tenant_code' => 'api-tenant',
            'subdomain' => 'api-subdomain',
            'status' => 'active',
        ]);

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'PAWNAPITEST00001',
            'plan_type' => 'basic',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'activated_at' => now(),
        ]);

        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'API Role',
            'description' => 'API role',
            'is_default' => false,
            'permissions' => ['access_all'],
        ]);

        $tenantUser = TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'API001',
            'name' => 'API User',
            'nrc' => '12/PaTaNa(N)000001',
            'email' => 'api-user@example.com',
            'phone' => '0955555555',
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        return [$tenant, $tenantUser];
    }
}
