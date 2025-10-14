<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zip_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status')->default('queued'); // queued|processing|completed|failed
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('document_ids');
            $table->string('disk')->default('s3');
            $table->string('archive_disk')->nullable();
            $table->string('result_path')->nullable();
            $table->string('result_filename')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zip_jobs');
    }
};
