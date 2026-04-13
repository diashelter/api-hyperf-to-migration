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

class CreateLookupCacheTable extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_cache', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity', 64);
            $table->string('external_id', 64);
            $table->string('label', 255)->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('environment', 32);
            $table->timestamp('created_at')->nullable();

            $table->unique(['entity', 'external_id', 'environment'], 'uniq_lookup_entity_external_env');
            $table->index(['entity', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_cache');
    }
}
