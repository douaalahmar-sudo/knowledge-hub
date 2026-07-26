<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_requests', function (Blueprint $table) {
            $table->id();

            // Tenant isolation (matches the app-wide BelongsToTenant convention).
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Requesting employee.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Request classification & content.
            $table->enum('type', [
                'PAYSLIP',
                'WORK_CERTIFICATE',
                'LEAVE_REQUEST',
                'CUSTOM',
            ]);
            $table->string('title');
            $table->text('description')->nullable();

            // Leave-request date range (nullable for other types).
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Employee-supplied supporting files (array of public URLs).
            $table->json('attachments')->nullable();

            // HR processing workflow.
            $table->enum('status', [
                'PENDING',
                'IN_PROGRESS',
                'APPROVED',
                'REJECTED',
                'READY_FOR_DOWNLOAD',
            ])->default('PENDING');
            $table->text('admin_note')->nullable();

            // Generated document uploaded by HR (e.g. the payslip PDF).
            $table->string('pdf_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_requests');
    }
};
