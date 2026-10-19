<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thing_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code')->nullable()->unique();
            $table->string('name', 120);
            $table->foreignUlid('parent_id')->nullable()->constrained('thing_types')->restrictOnDelete();
            $table->json('schema')->nullable();
            $table->timestamps();
            $table->unique(['scope_id', 'name']);
        });
        Schema::create('things', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('thing_type_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('inventory_number')->nullable();
            $table->string('owner_kind', 24)->default('organization');
            $table->foreignUlid('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('owner_name')->nullable();
            $table->date('purchased_on')->nullable();
            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->text('registration_basis');
            $table->string('condition', 24)->default('working');
            $table->string('suitability', 24)->default('unassessed');
            $table->string('lifecycle', 24)->default('active');
            $table->json('characteristics')->nullable();
            $table->text('description')->nullable();
            $table->string('wms_product_id', 128)->nullable();
            $table->string('wms_inventory_unit', 128)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['scope_id', 'wms_inventory_unit']);
            $table->index(['scope_id', 'department_id', 'id']);
            $table->index(['scope_id', 'thing_type_id']);
            $table->index(['scope_id', 'condition']);
            $table->index(['scope_id', 'wms_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('things');
        Schema::dropIfExists('thing_types');
    }
};
