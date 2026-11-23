
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $t->enum('status', [
                'pending',
                'approved',
                'rejected'
            ])->default('pending')->index();

            $t->text('note')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::table('messages', function (Blueprint $t) {
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_requests');

        Schema::table('messages', function (Blueprint $t) {
            $t->dropColumn(['file_path', 'file_name']);
        });
    }
};
