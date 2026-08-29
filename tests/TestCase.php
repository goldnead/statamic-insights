<?php

namespace Goldnead\StatamicInsights\Tests;

use Goldnead\StatamicInsights\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('statamic.system.multisite', false);

        // Without Pro, Statamic refuses a second user — and a permission test
        // needs two: one who may see the screen and one who may not.
        $app['config']->set('statamic.editions.pro', true);
    }

    /**
     * The payments tables, as this addon reads them.
     *
     * Built here rather than by requiring the sibling, and that is a deliberate
     * trade with a named cost. The gain: the report is a set of SQL aggregates
     * over columns, so a schema written out here **is** the contract, in one
     * readable place, and the suite runs without a CRM and a payment provider
     * installed. The cost: if the sibling renames a column, this stays green.
     *
     * That cost is paid twice over — once by `SiblingSchemaMatchesTest`, which
     * reads the sibling's own migrations wherever they are reachable, and once
     * by the studio playground, where all twenty-odd addons run together
     * against one database and the screen is looked at.
     */
    protected function createPaymentsSchema(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('provider_id', 191)->nullable();
            $table->string('product')->nullable();
            $table->unsignedInteger('amount_cent')->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('discount_cent')->default(0);
            $table->unsignedInteger('refunded_cent')->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->string('product')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('amount_cent')->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('discount_cent')->default(0);
            $table->string('kind', 32)->nullable();
            $table->timestamps();
        });
    }
}
