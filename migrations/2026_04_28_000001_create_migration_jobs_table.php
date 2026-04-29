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

class CreateMigrationJobsTable extends Migration
{
    public function up(): void
    {
        Schema::create('migration_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_db', 100)->index();
            $table->string('contract_id', 100)->index();
            $table->string('status', 50)->default('queued')->index();
            $table->string('current_entity', 100)->nullable();
            $table->jsonb('entity_progress')->nullable();
            $table->jsonb('totals')->nullable();
            $table->jsonb('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'status', 'created_at'], 'idx_migration_jobs_contract_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_jobs');
    }
}
