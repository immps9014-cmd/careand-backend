<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // franchise_contracts
        Schema::create('franchise_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('franchisee_user_id');
            $table->string('contract_number', 50)->unique();
            $table->date('contract_start_date');
            $table->date('contract_end_date');

            $table->decimal('initial_franchise_fee', 12, 2)->comment('가맹비');
            $table->decimal('monthly_royalty_rate', 5, 4)->default(0.05)->comment('월매출 대비 비율');
            $table->decimal('monthly_marketing_fee', 10, 2)->nullable();
            $table->decimal('minimum_monthly_royalty', 10, 2)->nullable();

            $table->json('exclusive_region_codes')->nullable();

            $table->enum('status', ['draft', 'active', 'paused', 'terminated', 'expired'])->default('draft');
            $table->timestamp('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->string('contract_pdf_url', 500)->nullable();

            $table->timestamps();

            $table->unique('branch_id');
            $table->index('franchisee_user_id');
            $table->index('status');

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('franchisee_user_id')->references('id')->on('users');
        });

        // franchise_settlements
        Schema::create('franchise_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('franchise_contract_id');
            $table->integer('period_year');
            $table->integer('period_month');

            $table->decimal('gross_revenue', 12, 2)->default(0);
            $table->decimal('senior_revenue', 12, 2)->default(0);
            $table->decimal('postpartum_revenue', 12, 2)->default(0);
            $table->decimal('education_revenue', 12, 2)->default(0);

            $table->decimal('royalty_amount', 12, 2)->default(0);
            $table->decimal('marketing_fee_amount', 10, 2)->default(0);
            $table->decimal('other_charges', 10, 2)->default(0);
            $table->decimal('total_payable_to_hq', 12, 2)->default(0);

            $table->enum('payment_status', ['pending', 'paid', 'overdue', 'disputed'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('invoice_url', 500)->nullable();

            $table->timestamps();

            $table->unique(['franchise_contract_id', 'period_year', 'period_month'], 'uk_settle_contract_period');
            $table->index('payment_status');

            $table->foreign('franchise_contract_id')
                ->references('id')->on('franchise_contracts')->onDelete('cascade');
        });

        // franchise_applications
        Schema::create('franchise_applications', function (Blueprint $table) {
            $table->id();
            $table->string('applicant_name', 50);
            $table->binary('applicant_phone_encrypted');
            $table->string('applicant_email', 200)->nullable();
            $table->string('desired_region', 200)->nullable();
            $table->decimal('investment_capacity', 12, 2)->nullable();
            $table->text('business_experience')->nullable();
            $table->text('motivation')->nullable();
            $table->string('referral_source', 100)->nullable();

            // 심사
            $table->enum('status', [
                'submitted', 'reviewing', 'interview_scheduled',
                'approved', 'rejected', 'cancelled'
            ])->default('submitted');
            $table->unsignedBigInteger('reviewer_user_id')->nullable();
            $table->integer('review_score')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('interview_at')->nullable();

            $table->unsignedBigInteger('converted_contract_id')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('reviewer_user_id');

            $table->foreign('converted_contract_id')->references('id')->on('franchise_contracts');
        });

        // franchise_data_policies
        Schema::create('franchise_data_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('franchise_contract_id');

            // 공유 권한
            $table->boolean('can_view_caregiver_full_profile')->default(false);
            $table->boolean('can_view_client_pii')->default(false);
            $table->boolean('can_view_other_branch_data')->default(false);
            $table->boolean('can_export_data')->default(false);
            $table->boolean('can_use_ai_features')->default(true);

            // 제약
            $table->integer('daily_api_call_limit')->default(10000);
            $table->integer('monthly_data_export_mb')->default(100);

            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('franchise_contract_id');
            $table->foreign('franchise_contract_id')
                ->references('id')->on('franchise_contracts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_data_policies');
        Schema::dropIfExists('franchise_applications');
        Schema::dropIfExists('franchise_settlements');
        Schema::dropIfExists('franchise_contracts');
    }
};
