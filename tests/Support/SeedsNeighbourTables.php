<?php

namespace Goldnead\StatamicInsights\Tests\Support;

use Goldnead\StatamicInsights\Support\Neighbours;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sibling tables the reports read, built by hand.
 *
 * The suite has none of the siblings installed — pulling three packages into
 * `require-dev` for six reports would make this addon's tests depend on three
 * release cycles. So the tables are created here with the columns the reports
 * touch, named exactly as the siblings' migrations name them, and
 * {@see Neighbours::pretend()} declares the sibling present. If a sibling
 * renames a column, the playground run against the real schema is what
 * catches it; this suite catches the arithmetic.
 */
trait SeedsNeighbourTables
{
    protected function createPaymentsTables(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('product', 191)->nullable();
            $table->unsignedInteger('amount_cent')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 32)->default('initiated');
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('country', 2)->nullable();
            $table->unsignedInteger('refunded_cent')->default(0);
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->string('product', 191);
            $table->string('name', 191);
            $table->unsignedInteger('amount_cent');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('discount_cent')->default(0);
            $table->string('kind', 16)->default('primary');
            $table->timestamps();
        });

        Neighbours::pretend(Neighbours::PAYMENTS);
    }

    protected function createOffersTable(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('handle', 191);
            $table->string('name', 191);
            $table->string('product', 191);
            $table->json('products')->nullable();
            $table->unsignedInteger('amount_cent')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('slot', 32)->default('standalone');
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('shown_count')->default(0);
            $table->unsignedBigInteger('accepted_count')->default(0);
            $table->timestamps();
        });

        Neighbours::pretend(Neighbours::OFFERS);
    }

    protected function createEntitlementsTable(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0);
            $table->string('subject_type', 160)->default('user');
            $table->string('subject_id', 64)->default('1');
            $table->string('product_slug', 191);
            $table->string('status', 16)->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Neighbours::pretend(Neighbours::ENTITLEMENTS);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function payment(array $attributes = []): int
    {
        $paidAt = $attributes['paid_at'] ?? Carbon::now()->subDay();
        $status = $attributes['status'] ?? 'paid';

        return (int) DB::table('payments')->insertGetId(array_merge([
            'product' => 'kurs',
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => $status,
            'email' => 'wer@example.com',
            'country' => 'DE',
            'brand_id' => 1,
            'paid_at' => $status === 'paid' ? $paidAt : null,
            'created_at' => $attributes['created_at'] ?? ($paidAt instanceof Carbon ? $paidAt->copy()->subHour() : Carbon::now()->subDay()),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function item(int $paymentId, array $attributes = []): void
    {
        DB::table('payment_items')->insert(array_merge([
            'payment_id' => $paymentId,
            'product' => 'kurs',
            'name' => 'Kurs',
            'amount_cent' => 4900,
            'quantity' => 1,
            'kind' => 'primary',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function offer(array $attributes = []): void
    {
        DB::table('offers')->insert(array_merge([
            'handle' => 'bump-'.uniqid(),
            'name' => 'Bump',
            'product' => 'workbook',
            'amount_cent' => 900,
            'currency' => 'EUR',
            'slot' => 'bump',
            'active' => true,
            'shown_count' => 0,
            'accepted_count' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function entitlement(array $attributes = []): void
    {
        DB::table('entitlements')->insert(array_merge([
            'product_slug' => 'kurs-zugang',
            'status' => 'active',
            'starts_at' => Carbon::now()->subMonth(),
            'expires_at' => null,
            'grace_until' => null,
            'revoked_at' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    protected function forgetNeighbours(): void
    {
        Neighbours::forget();
    }
}
