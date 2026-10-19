<?php

namespace Tests\Unit;

use App\DataObjects\RequestObjects\InventoryListFilter;
use App\DataObjects\RequestObjects\InventoryPageFilter;
use App\DataObjects\RequestObjects\InventorySerializedItemFilter;
use App\DataObjects\ResponseObjects\InventoryItemListPage;
use App\DataObjects\ResponseObjects\InventoryMovementListPage;
use App\DataObjects\ResponseObjects\InventorySerializedItemListPage;
use App\Http\Controllers\TenantModule\InventoryController;
use App\Services\TenantModule\InventoryService;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryReadEndpointTest extends TestCase
{
    public function test_summary_endpoint_preserves_zero_search_and_default_page_size(): void
    {
        // Mock the service boundary so controller verification never reads or migrates a database.
        $service = Mockery::mock(InventoryService::class);
        $service->shouldReceive('itemSummaryPage')->once()->with(Mockery::on(
            fn (InventoryListFilter $filter): bool => $filter->page === 1
                && $filter->perPage === 25 && $filter->search === '0',
        ))->andReturn(new InventoryItemListPage([], 1, 1, 25, 0));
        $response = (new InventoryController($service))->index(Request::create('/', 'GET', ['search' => '0']));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['items' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 0], $response->getData(true)['data']);
    }

    public function test_history_endpoint_passes_explicit_pagination(): void
    {
        $service = Mockery::mock(InventoryService::class);
        $service->shouldReceive('movementHistoryPage')->once()->with('ITEM-1', Mockery::on(
            fn (InventoryPageFilter $filter): bool => $filter->page === 2 && $filter->perPage === 100,
        ))->andReturn(new InventoryMovementListPage([], 2, 2, 100, 100));
        $response = (new InventoryController($service))->movements(Request::create('/', 'GET', ['page' => '2', 'per_page' => '100']), 'ITEM-1');
        $this->assertSame(2, $response->getData(true)['data']['current_page']);
    }

    public function test_serialized_filters_remain_separate_from_measurement_options(): void
    {
        $service = Mockery::mock(InventoryService::class);
        $service->shouldReceive('serializedItemsPage')->once()->with('ITEM-1', Mockery::on(
            fn (InventorySerializedItemFilter $filter): bool => $filter->search === '0'
                && $filter->locationCode === 'LOC-1' && $filter->availableOnly && $filter->perPage === 25,
        ))->andReturn(new InventorySerializedItemListPage([], 1, 1, 25, 0));
        $response = (new InventoryController($service))->serializedItems(Request::create('/', 'GET', [
            'search' => '0', 'location_code' => 'LOC-1', 'available_only' => '1',
        ]), 'ITEM-1');
        $this->assertSame(200, $response->getStatusCode());
    }

    #[DataProvider('invalidPagination')]
    public function test_invalid_pagination_never_calls_the_read_service(string $action, array $query): void
    {
        // An unexpected service invocation fails the mock, proving rejection at the HTTP boundary.
        $controller = new InventoryController(Mockery::mock(InventoryService::class));
        $request = Request::create('/', 'GET', $query);
        $response = $action === 'index' ? $controller->index($request) : $controller->$action($request, 'ITEM-1');
        $this->assertSame(422, $response->getStatusCode());
    }

    public static function invalidPagination(): array
    {
        $cases = [];
        foreach (['index', 'movements', 'serializedItems'] as $action) {
            foreach ([['page' => 0], ['page' => '1.5'], ['per_page' => 0], ['per_page' => 101]] as $query) {
                $cases[] = [$action, $query];
            }
        }
        return $cases;
    }
}
