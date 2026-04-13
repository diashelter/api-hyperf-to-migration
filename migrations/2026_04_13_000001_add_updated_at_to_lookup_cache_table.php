<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddUpdatedAtToLookupCacheTable extends Migration
{
    public function up(): void
    {
        Schema::table('lookup_cache', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('lookup_cache', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });
    }
}
