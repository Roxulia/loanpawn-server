<?php

namespace Tests\Unit;

use App\DTOs\Ownership\Public\RequestObjects\OwnershipListFilter;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipItemPage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipLotPage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipMovementPage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipReceiptPage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipPiecePage;
use App\Exceptions\InvalidTenantRequest;
use App\Models\OwnershipModule\OwnedItem;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipResponseTestCase;

class OwnershipPageTest extends OwnershipResponseTestCase
{
    public function test_filter_uses_default_page_size(): void
    {
        // Ownership keeps the project's bounded pagination defaults and historical holdings.
        $filter = OwnershipListFilter::fromValidated([]);
        $this->assertSame(1, $filter->page);
        $this->assertSame(25, $filter->perPage);
        $this->assertSame('all', $filter->holding);
    }

    public function test_filter_accepts_maximum_page_size(): void
    {
        // Valid explicit limits must not be silently reduced to the default.
        $filter = OwnershipListFilter::fromValidated(['page' => 2, 'per_page' => 100]);
        $this->assertSame(2, $filter->page);
        $this->assertSame(100, $filter->perPage);
    }

    #[DataProvider('invalidLimits')]
    public function test_filter_rejects_invalid_limit(int $page, int $size): void
    {
        // Check the public factory boundary using already validated integer input types.
        $this->expectException(InvalidTenantRequest::class);
        OwnershipListFilter::fromValidated(['page' => $page, 'per_page' => $size]);
    }

    #[DataProvider('pageKinds')]
    public function test_page_uses_flat_metadata(string $class, string $kind): void
    {
        // Preserve typed rows and the exact outer page shape for every Ownership collection.
        $page = new $class([$this->row($kind)], 2, 3, 25, 51);
        $this->assertSame(['items' => [$this->expectedRow($kind)], 'current_page' => 2,
            'last_page' => 3, 'per_page' => 25, 'total' => 51], $page->toArray());
    }

    #[DataProvider('pageKinds')]
    public function test_empty_page_keeps_last_page_one(string $class, string $kind): void
    {
        // No records is a valid response, with no extra nested pagination object.
        $page = new $class([], 1, 1, 25, 0);
        $this->assertSame(['items' => [], 'current_page' => 1,
            'last_page' => 1, 'per_page' => 25, 'total' => 0], $page->toArray());
    }

    #[DataProvider('pageKinds')]
    public function test_page_rejects_raw_row_array(string $class, string $kind): void
    {
        // Raw arrays could carry internal IDs and bypass the typed public response contract.
        $this->expectException(InvalidArgumentException::class);
        new $class([['id' => 99]], 1, 1, 25, 1);
    }

    #[DataProvider('pageKinds')]
    public function test_page_rejects_raw_model(string $class, string $kind): void
    {
        // An unsaved model needs no database; pages must reject it before lazy relations can escape.
        $this->expectException(InvalidArgumentException::class);
        new $class([new OwnedItem()], 1, 1, 25, 1);
    }

    #[DataProvider('pageKinds')]
    public function test_page_rejects_wrong_row_type(string $class, string $kind): void
    {
        // Even another valid response DTO is invalid for the wrong collection.
        $row = $this->row($kind === 'item' ? 'lot' : 'item');
        $this->expectException(InvalidArgumentException::class);
        new $class([$row], 1, 1, 25, 1);
    }

    public function test_page_rejects_more_rows_than_page_size(): void
    {
        // A typed page must still enforce its requested row bound.
        $row = $this->summary();
        $this->expectException(InvalidArgumentException::class);
        new OwnershipItemPage([$row, $row], 1, 2, 1, 2);
    }

    #[DataProvider('invalidMetadata')]
    public function test_page_rejects_invalid_metadata(int $page, int $last, int $size, int $total): void
    {
        // Invalid response construction is a programming error, separate from public input errors.
        $this->expectException(InvalidArgumentException::class);
        new OwnershipItemPage([], $page, $last, $size, $total);
    }

    public static function invalidLimits(): array
    {
        // Keep both ends of the input bounds explicit.
        return ['page zero' => [0, 25], 'negative page' => [-1, 25], 'size zero' => [1, 0],
            'negative size' => [1, -1], 'oversized page' => [1, 101]];
    }

    public static function pageKinds(): array
    {
        // Supply class names only so missing production DTOs do not execute during data discovery.
        return [
            'items' => [OwnershipItemPage::class, 'item'], 'lots' => [OwnershipLotPage::class, 'lot'],
            'movements' => [OwnershipMovementPage::class, 'movement'],
            'receipts' => [OwnershipReceiptPage::class, 'receipt'], 'pieces' => [OwnershipPiecePage::class, 'piece'],
        ];
    }

    public static function invalidMetadata(): array
    {
        // Match the established Inventory page constructor's independent metadata checks.
        return ['page zero' => [0, 1, 25, 0], 'last page zero' => [1, 0, 25, 0],
            'size zero' => [1, 1, 0, 0], 'size over maximum' => [1, 1, 101, 0], 'negative total' => [1, 1, 25, -1]];
    }
}
