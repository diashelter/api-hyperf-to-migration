<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMigrationIdMappingsTable extends Migration
{
    public function up(): void
    {
        Schema::create('migration_id_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('migration_batch_id')->nullable()->index();
            $table->string('entity', 100)->index();
            $table->string('legacy_id', 255)->index();
            $table->uuid('new_id')->index();
            $table->uuid('contract_id')->index();
            $table->timestamp('created_at')->nullable();

            $table->unique(['entity', 'legacy_id', 'contract_id'], 'uniq_entity_legacy_contract');

            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_id_mappings');
    }
}
