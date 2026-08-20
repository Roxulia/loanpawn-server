<?php

namespace Tests\Unit;

use App\Exceptions\InvalidTenantRequest;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use Tests\TestCase;

class PostedTransactionAccountLockTest extends TestCase
{
    public function test_it_rejects_changing_the_account_of_a_posted_transaction(): void
    {
        $this->expectException(InvalidTenantRequest::class);
        $this->expectExceptionMessage('cannot be changed');

        app(MultiAccountManagement::class)->resolvePostedTransactionAccount(10, 11);
    }

    public function test_it_rejects_updating_a_posted_transaction_without_a_stored_account(): void
    {
        $this->expectException(InvalidTenantRequest::class);
        $this->expectExceptionMessage('has no financial account');

        app(MultiAccountManagement::class)->resolvePostedTransactionAccount(null, null);
    }
}
