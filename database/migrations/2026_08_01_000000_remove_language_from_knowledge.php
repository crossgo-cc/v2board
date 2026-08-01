<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveLanguageFromKnowledge extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('v2_knowledge', 'language')) {
            Schema::table('v2_knowledge', function (Blueprint $table) {
                $table->dropColumn('language');
            });
        }
    }

    public function down()
    {
        if (!Schema::hasColumn('v2_knowledge', 'language')) {
            Schema::table('v2_knowledge', function (Blueprint $table) {
                $table->char('language', 5)->comment('語言');
            });
        }
    }
}
