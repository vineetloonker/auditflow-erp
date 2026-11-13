<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('llpin', 30)->nullable()->after('cin')->index();
        });

        Schema::table('compliance_deadlines', function (Blueprint $table) {
            $table->string('financial_year', 20)->nullable()->after('title');
            $table->string('srn', 60)->nullable()->after('description');
            $table->date('filing_date')->nullable()->after('srn');
            $table->string('acknowledgement_reference', 120)->nullable()->after('filing_date');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_deadlines', function (Blueprint $table) {
            $table->dropColumn(['financial_year', 'srn', 'filing_date', 'acknowledgement_reference']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('llpin');
        });
    }
};
