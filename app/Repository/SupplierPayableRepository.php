<?php
namespace App\Repository;

use App\Models\PurchasingModule\PurchaseSupplier;
use App\Models\PurchasingModule\SupplierCredit;
use App\Models\PurchasingModule\SupplierCreditAllocation;
use App\Models\PurchasingModule\SupplierPayable;
use App\Models\PurchasingModule\SupplierPayableAdjustment;
use App\Models\PurchasingModule\SupplierPayablePayment;
use Illuminate\Database\Eloquent\Collection;

class SupplierPayableRepository
{
    public function lockSupplier(int $tenantId, int $supplierId): void
    {
        PurchaseSupplier::query()->where('tenant_id',$tenantId)->whereKey($supplierId)->lockForUpdate()->firstOrFail();
    }
    public function list(int $tenantId, ?string $orderCode = null): Collection
    {
        return SupplierPayable::query()->where('tenant_id',$tenantId)
            ->when($orderCode,fn($q)=>$q->whereHas('receipt.order',fn($o)=>$o->where('code',$orderCode)))
            ->with(['supplier.person','receipt.order','currency'])->orderByDesc('id')->limit(200)->get();
    }
    public function findByCode(int $tenantId,string $code,bool $lock=false): ?SupplierPayable
    {
        $query=SupplierPayable::query()->where('tenant_id',$tenantId)->where('code',$code);
        if($lock)$query->lockForUpdate();
        return $query->with(['supplier.person','receipt.order','currency'])->first();
    }
    public function forReceipt(int $tenantId,int $receiptId,bool $lock=false): ?SupplierPayable
    {
        $query=SupplierPayable::query()->where('tenant_id',$tenantId)->where('purchase_receipt_id',$receiptId);
        if($lock)$query->lockForUpdate();
        return $query->with(['supplier.person','receipt.order','currency'])->first();
    }
    public function createPayable(array $data): SupplierPayable { return SupplierPayable::query()->create($data)->load(['supplier.person','receipt.order','currency']); }
    public function updateBalance(SupplierPayable $payable,float $amount): SupplierPayable
    {
        $amount=round(max(0,$amount),2);
        $payable->update(['balance_amount'=>$amount,'status'=>$amount<=0?'SETTLED':($amount+0.001<(float)$payable->original_amount?'PARTIAL':'OPEN')]);
        return $payable->refresh()->load(['supplier.person','receipt.order','currency']);
    }
    public function createPayment(array $data): SupplierPayablePayment { return SupplierPayablePayment::query()->create($data); }
    public function updatePaymentAccounting(SupplierPayablePayment $payment,int $accountId,int $accountingId): SupplierPayablePayment
    { $payment->update(['financial_account_id'=>$accountId,'accounting_transaction_id'=>$accountingId]); return $payment->refresh(); }
    public function payments(int $tenantId,int $payableId): Collection
    { return SupplierPayablePayment::query()->where('tenant_id',$tenantId)->where('supplier_payable_id',$payableId)->orderBy('paid_at')->get(); }
    public function adjustmentExists(int $tenantId,string $type,string $sourceType,string $sourceCode): bool
    { return SupplierPayableAdjustment::query()->where('tenant_id',$tenantId)->where('adjustment_type',$type)->where('source_type',$sourceType)->where('source_code',$sourceCode)->exists(); }
    public function createAdjustment(array $data): SupplierPayableAdjustment { return SupplierPayableAdjustment::query()->create($data); }
    public function updateAdjustment(SupplierPayableAdjustment $adjustment,array $data): SupplierPayableAdjustment
    { $adjustment->update($data); return $adjustment->refresh(); }    public function availableCredits(int $tenantId,int $supplierId,int $currencyId,bool $lock=false): Collection
    {
        $query=SupplierCredit::query()->where('tenant_id',$tenantId)->where('supplier_id',$supplierId)->where('currency_id',$currencyId)->where('remaining_amount','>',0)->orderBy('id');
        if($lock)$query->lockForUpdate();
        return $query->get();
    }
    public function createCredit(array $data): SupplierCredit { return SupplierCredit::query()->create($data); }
    public function updateCreditBalance(SupplierCredit $credit,float $amount): SupplierCredit
    { $credit->update(['remaining_amount'=>round(max(0,$amount),2)]); return $credit->refresh(); }
    public function createCreditAllocation(array $data): SupplierCreditAllocation { return SupplierCreditAllocation::query()->create($data); }    public function updateCreditAllocation(SupplierCreditAllocation $allocation,array $data): SupplierCreditAllocation
    { $allocation->update($data); return $allocation->refresh(); }
}