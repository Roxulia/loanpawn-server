<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_suppliers', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('person_id')->constrained('tenant_people')->restrictOnDelete();
            $table->string('type', 24); $table->string('contact_name', 120)->nullable();
            $table->text('note')->nullable(); $table->boolean('is_active')->default(true);
            $table->timestamps(); $table->unique(['tenant_id', 'code']); $table->unique(['tenant_id', 'person_id']);
        });
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('supplier_id')->constrained('purchase_suppliers')->restrictOnDelete();
            $table->string('status', 20)->default('DRAFT'); $table->date('ordered_at')->nullable();
            $table->string('currency_code', 12); $table->text('note')->nullable(); $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(); $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->string('item_description', 255); $table->string('tracking_mode', 16);
            $table->string('unit_label', 80); $table->decimal('ordered_quantity', 15, 3); $table->decimal('unit_price', 15, 2);
            $table->timestamps(); $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'purchase_order_id']);
        });
        Schema::create('purchase_payments', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->date('paid_at'); $table->decimal('amount', 15, 2); $table->string('reference', 120)->nullable(); $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'purchase_order_id', 'paid_at']);
        });
        Schema::create('purchase_refunds', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('purchase_payment_id')->constrained('purchase_payments')->restrictOnDelete();
            $table->date('refunded_at'); $table->decimal('amount', 15, 2); $table->string('reference', 120)->nullable(); $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'purchase_payment_id', 'refunded_at']);
        });
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->date('received_at'); $table->text('note')->nullable(); $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(); $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'purchase_order_id', 'received_at']);
        });
        Schema::create('purchase_receipt_lines', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->decimal('received_quantity', 15, 3); $table->timestamps();
            $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'purchase_order_line_id']);
        });
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->date('returned_at'); $table->string('reason', 255)->nullable(); $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'purchase_order_id', 'returned_at']);
        });
        Schema::create('purchase_return_lines', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_receipt_line_id')->constrained('purchase_receipt_lines')->restrictOnDelete();
            $table->decimal('returned_quantity', 15, 3); $table->timestamps();
            $table->unique(['tenant_id', 'code']); $table->index(['tenant_id', 'purchase_receipt_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines'); Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_receipt_lines'); Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_refunds'); Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_order_lines'); Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_suppliers');
    }
};
