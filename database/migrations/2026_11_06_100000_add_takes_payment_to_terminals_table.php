<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "This counter has a cash drawer and takes payment". Off: the counter prints the bill
     * and the main machine settles it.
     */
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->boolean('takes_payment')->default(false)->after('counter_no');
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn('takes_payment');
        });
    }
};
