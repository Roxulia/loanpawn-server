<?php

namespace Tests\Unit;

use App\DataObjects\RequestObjects\FinancialAccountTransactionCreate;
use App\DataObjects\RequestObjects\FinancialAccountTransactionFilter;
use App\Enums\FinancialAccountTransactionType;
use App\Exceptions\InvalidTenantRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Repository\Accounting\FinancialAccountTransactionRepository;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialAccountTransactionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());
    }

    #[DataProvider('fixedDirectionOperations')]
    public function test_operation_helpers_record_expected_type_and_direction(string $method, FinancialAccountTransactionType $type, string $direction): void
    {
        $account = $this->account();
        $repository = Mockery::mock(FinancialAccountTransactionRepository::class);
        $this->expectBalancePosting($repository, $account, $direction === 'debit' ? 2500 : -2500);
        $repository->shouldReceive('create')->once()->with(Mockery::on(function (array $data) use ($type, $direction): bool {
            return $data['transaction_type'] === $type->value
                && $data['direction'] === $direction
                && $data['tenant_id'] === 41
                && $data['financial_account_id'] === 91;
        }))->andReturn(new FinancialAccountTransaction);

        (new FinancialAccountTransactionService($repository))->{$method}($account, 2500);
    }

    public function test_opening_balance_records_a_debit_and_ignores_non_positive_amounts(): void
    {
        $account = $this->account();
        $repository = Mockery::mock(FinancialAccountTransactionRepository::class);
        $this->expectBalancePosting($repository, $account, 5000);
        $repository->shouldReceive('create')->once()->with(Mockery::on(fn (array $data): bool => $data['transaction_type'] === FinancialAccountTransactionType::OpeningBalance->value
            && $data['direction'] === 'debit'
            && $data['note'] === 'Opening balance'))->andReturn(new FinancialAccountTransaction);
        $service = new FinancialAccountTransactionService($repository);

        $this->assertInstanceOf(FinancialAccountTransaction::class, $service->recordOpeningBalance($account, 5000, 7));
        $this->assertNull($service->recordOpeningBalance($account, 0, 7));
    }

    #[DataProvider('variableDirectionOperations')]
    public function test_variable_direction_helpers_preserve_explicit_direction(string $method, FinancialAccountTransactionType $type): void
    {
        $account = $this->account();
        $repository = Mockery::mock(FinancialAccountTransactionRepository::class);
        $this->expectBalancePosting($repository, $account, -2500);
        $repository->shouldReceive('create')->once()->with(Mockery::on(fn (array $data): bool => $data['transaction_type'] === $type->value && $data['direction'] === 'credit'))->andReturn(new FinancialAccountTransaction);

        (new FinancialAccountTransactionService($repository))->{$method}($account, 2500, 'credit');
    }

    public function test_helper_forwards_reference_and_audit_fields(): void
    {
        $repository = Mockery::mock(FinancialAccountTransactionRepository::class);
        $this->expectBalancePosting($repository, $this->account(), -1000);
        $repository->shouldReceive('create')->once()->with(Mockery::on(fn (array $data): bool => $data['reference_number'] === 'EXP-100'
            && $data['reference_type'] === 'TenantExpense'
            && $data['note'] === 'Office rent'
            && $data['created_by'] === 7
            && $data['related_transaction_id'] === 88))->andReturn(new FinancialAccountTransaction);

        (new FinancialAccountTransactionService($repository))->recordExpensePayment(
            $this->account(),
            1000,
            'EXP-100',
            'TenantExpense',
            'Office rent',
            7,
            88,
        );
    }

    public function test_record_rejects_tenant_mismatch_deleted_account_invalid_direction_and_non_positive_amount(): void
    {
        $repository = Mockery::mock(FinancialAccountTransactionRepository::class);
        $repository->shouldNotReceive('create');
        $service = new FinancialAccountTransactionService($repository);

        foreach ([
            new FinancialAccountTransactionCreate(99, $this->account(), FinancialAccountTransactionType::ExpensePayment, 100, 'credit'),
            new FinancialAccountTransactionCreate(41, $this->account(isDeleted: true), FinancialAccountTransactionType::ExpensePayment, 100, 'credit'),
            new FinancialAccountTransactionCreate(41, $this->account(), FinancialAccountTransactionType::Adjustment, 100, 'invalid'),
            new FinancialAccountTransactionCreate(41, $this->account(), FinancialAccountTransactionType::ExpensePayment, 0, 'credit'),
        ] as $request) {
            try {
                $service->record($request);
                $this->fail('Expected invalid financial account transaction request to be rejected.');
            } catch (InvalidTenantRequest) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_account_history_is_scoped_through_the_repository_and_serialized(): void
    {
        $account = $this->account();
        $filter = new FinancialAccountTransactionFilter(search: 'loan', direction: 'credit');
        $transaction = new FinancialAccountTransaction;
        $transaction->forceFill([
            'id' => 501,
            'transaction_type' => FinancialAccountTransactionType::PawnLoanCreation,
            'amount' => '2500.0000',
            'direction' => 'credit',
            'reference_number' => 'SLIP-1',
            'reference_type' => 'PawnLoanContractSlip',
            'note' => 'Loan issued',
            'related_transaction_id' => 88,
            'reversed_transaction_id' => null,
            'created_at' => now(),
        ]);
        $transaction->setRelation('creator', null);
        $paginator = new LengthAwarePaginator([$transaction], 1, 15, 1);
        $repository = Mockery::mock(FinancialAccountTransactionRepository::class);
        $repository->shouldReceive('paginateForAccount')->once()->with(41, 91, $filter)->andReturn($paginator);

        $page = (new FinancialAccountTransactionService($repository))->listForAccount(41, $account, $filter)->toArray();

        $this->assertSame(1, $page['total']);
        $this->assertSame('PAWN_LOAN_CREATION', $page['items'][0]['transaction_type']);
        $this->assertSame('SLIP-1', $page['items'][0]['reference_number']);
    }

    public function test_account_history_rejects_another_tenant_or_deleted_account(): void
    {
        $repository = Mockery::mock(FinancialAccountTransactionRepository::class);
        $repository->shouldNotReceive('paginateForAccount');
        $service = new FinancialAccountTransactionService($repository);

        foreach ([$this->account(), $this->account(isDeleted: true)] as $index => $account) {
            try {
                $service->listForAccount($index === 0 ? 99 : 41, $account, new FinancialAccountTransactionFilter);
                $this->fail('Expected unavailable account history to be rejected.');
            } catch (InvalidTenantRequest) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function fixedDirectionOperations(): array
    {
        return [
            ['recordPawnLoanCreation', FinancialAccountTransactionType::PawnLoanCreation, 'credit'],
            ['recordPawnInterestPayment', FinancialAccountTransactionType::PawnInterestPayment, 'debit'],
            ['recordPawnRedemption', FinancialAccountTransactionType::PawnRedemption, 'debit'],
            ['recordDebtCreation', FinancialAccountTransactionType::DebtCreation, 'credit'],
            ['recordDebtPayment', FinancialAccountTransactionType::DebtPayment, 'debit'],
            ['recordBusinessLoanReceipt', FinancialAccountTransactionType::BusinessLoanReceipt, 'debit'],
            ['recordBusinessLoanPayment', FinancialAccountTransactionType::BusinessLoanPayment, 'credit'],
            ['recordExpensePayment', FinancialAccountTransactionType::ExpensePayment, 'credit'],
            ['recordCapitalContribution', FinancialAccountTransactionType::CapitalContribution, 'debit'],
            ['recordCapitalWithdrawal', FinancialAccountTransactionType::CapitalWithdrawal, 'credit'],
            ['recordTransferFee', FinancialAccountTransactionType::TransferFee, 'credit'],
        ];
    }

    public static function variableDirectionOperations(): array
    {
        return [
            ['recordAccountTransfer', FinancialAccountTransactionType::AccountTransfer],
            ['recordAdjustment', FinancialAccountTransactionType::Adjustment],
            ['recordReversal', FinancialAccountTransactionType::Reversal],
        ];
    }

    private function account(bool $isDeleted = false): FinancialAccount
    {
        $account = new FinancialAccount;
        $account->forceFill([
            'id' => 91,
            'tenant_id' => 41,
            'is_deleted' => $isDeleted,
            'balance' => 0,
            'allow_negative_balance' => true,
        ]);

        return $account;
    }

    private function expectBalancePosting(FinancialAccountTransactionRepository $repository, FinancialAccount $account, float $expectedBalance): void
    {
        $repository->shouldReceive('lockAccount')->once()->with(41, 91)->andReturn($account);
        $repository->shouldReceive('updateBalance')->once()->with($account, $expectedBalance)->andReturn($account);
    }
}
