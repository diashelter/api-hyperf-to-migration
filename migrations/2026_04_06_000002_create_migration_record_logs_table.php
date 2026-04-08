<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMigrationRecordLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('migration_record_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();
            $table->string('contract_id', 100)->index();
            $table->string('entity', 100)->index();
            $table->string('legacy_id', 255)->nullable()->index();
            $table->uuid('new_id')->nullable()->index();
            $table->string('status', 50)->index();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['contract_id', 'entity', 'legacy_id'], 'idx_migration_record_contract_entity_legacy');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_record_logs');
    }
}
