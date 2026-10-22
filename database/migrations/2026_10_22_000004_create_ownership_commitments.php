<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve tenant-scoped ownership commitments with exact quantities and restrictive history references.
        Schema::create('ownership_commitments', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('owned_item_id')->constrained('owned_items')->restrictOnDelete();
            $table->foreignId('acquisition_lot_id')->constrained('acquisition_lots')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('source_type', 120);
            $table->string('source_code', 120);
            $table->decimal('quantity', 14, 3);
            $table->decimal('remaining_quantity', 14, 3);
            $table->timestamps();
            $table->unique(['tenant_id', 'acquisition_lot_id', 'kind', 'source_type', 'source_code'], 'own_commit_source_uq');
            $table->index(['tenant_id', 'source_type', 'source_code', 'id'], 'own_commit_source_idx');
        });

        // Preserve tenant-scoped ownership piece commitments with exact quantities and restrictive history references.
        Schema::create('ownership_piece_commitments', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ownership_commitment_id')->constrained('ownership_commitments')->restrictOnDelete();
            $table->unsignedBigInteger('ownership_piece_membership_id');
            $table->foreign('ownership_piece_membership_id', 'own_piece_commit_membership_fk')
                ->references('id')->on('ownership_piece_memberships')->restrictOnDelete();
            $table->dateTime('ended_at', 6)->nullable();
            // A piece can be reserved for sale or pledged, never committed twice at once.
            $table->unsignedBigInteger('current_membership_id')
                ->nullable()->storedAs('CASE WHEN ended_at IS NULL THEN ownership_piece_membership_id ELSE NULL END');
            $table->timestamps();
            $table->unique('current_membership_id', 'own_piece_commit_current_uq');
            $table->index(['tenant_id', 'ownership_commitment_id', 'id'], 'own_piece_commit_source_idx');
        });
    }

    public function down(): void
    {
        // Drop dependent piece commitments before their source commitments.
        Schema::dropIfExists('ownership_piece_commitments');
        Schema::dropIfExists('ownership_commitments');
    }
};
