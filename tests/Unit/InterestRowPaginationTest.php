<?php

namespace Tests\Unit;

use App\DataObjects\ResponseObjects\InterestBreakDown;
use App\DataObjects\ResponseObjects\InterestCalculationResult;
use PHPUnit\Framework\TestCase;

class InterestRowPaginationTest extends TestCase
{
    public function test_interest_calculation_pages_display_rows_and_keeps_all_versions(): void
    {
        $rows = array_map(
            fn (int $id): InterestBreakDown => InterestBreakDown::fromValues($id, $id + 10, 5.0),
            range(1, 23),
        );

        $result = InterestCalculationResult::fromValues('LS-1', 2, 3, '2026-09-12', $rows, page: 2, perPage: 10)->toArray();

        $this->assertCount(10, $result['interest_rows']['items']);
        $this->assertSame(2, $result['interest_rows']['current_page']);
        $this->assertSame(3, $result['interest_rows']['last_page']);
        $this->assertSame(23, $result['interest_rows']['total']);
        $this->assertCount(23, $result['interest_row_versions']);
        $this->assertSame(115.0, $result['total_interest_amount']);
    }
}
