<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMigrationAuditLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('migration_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->string('contract_id', 100)->index();
            $table->string('entity', 100)->index();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->integer('total_received')->default(0);
            $table->integer('total_valid')->default(0);
            $table->integer('total_invalid')->default(0);
            $table->integer('total_inserted')->default(0);
            $table->integer('total_failed')->default(0);
            $table->integer('total_skipped')->default(0);
            $table->string('status', 50)->index();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->jsonb('validation_errors')->nullable();
            $table->jsonb('insert_errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('processing_time_ms')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'entity', 'started_at'], 'idx_migration_audit_contract_entity_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_audit_logs');
    }
}
