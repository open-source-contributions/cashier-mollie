<?php

namespace Laravel\Cashier\Tests;

use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\CashierServiceProvider;
use Laravel\Cashier\Order\Order;
use Laravel\Cashier\Order\OrderItem;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\Tests\Fixtures\User;

class CashierTest extends BaseTestCase
{
    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withPackageMigrations();
        $this->withConfiguredPlans();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        Cashier::useCurrencyLocale('de_DE');
        Cashier::useCurrency('eur');
    }

    /** @test */
    public function testRunningCashierProcessesOpenOrderItems()
    {
        $user = $this->getMandatedUser(true, ['id' => 1]);

        $user->orderItems()->save(factory(OrderItem::class)->states('unlinked', 'processed')->make());
        $user->orderItems()->save(factory(OrderItem::class)->states('unlinked', 'unprocessed')->make());

        $this->assertSame(0, $user->orders()->count());
        $this->assertOrderItemCounts($user, 1, 1);

        Cashier::run();

        $this->assertSame(1, $user->orders()->count());
        $this->assertOrderItemCounts($user, 2, 0);
    }

    /** @test */
    public function testRunningCashierProcessesUnprocessedOrderItemsAndSchedulesNext()
    {
        $user1 = $this->getMandatedUser(true, ['id' => 1]);
        $user2 = $this->getMandatedUser(true, ['id' => 2]);

        $subscription1 = $user1->subscriptions()->save(factory(Subscription::class)->make());
        $subscription2 = $user2->subscriptions()->save(factory(Subscription::class)->make());

        $subscription1->orderItems()->save(
            factory(OrderItem::class)->states(['unprocessed', 'EUR'])->make([
                'owner_id' => 1,
                'owner_type' => User::class,
                'process_at' => now()->addHour(),
            ]) // should NOT process this (future)
        );

        $subscription1->orderItems()->saveMany(
            factory(OrderItem::class, 2)->states(['unprocessed', 'EUR'])->make([
                'owner_id' => 1,
                'owner_type' => User::class,
                'process_at' => now()->subHour(),
            ])
        ); // should process these two

        $subscription1->orderItems()->save(
            factory(OrderItem::class)->states('processed')->make()
        ); // should NOT process this (already processed)

        $subscription2->orderItems()->save(
            factory(OrderItem::class)->states('unprocessed')->make([
                'owner_id' => 2,
                'owner_type' => User::class,
                'process_at' => now()->subHours(2),
            ])
        ); // should process this one

        $this->assertSame(0, Order::count());
        $this->assertOrderItemCounts($user1, 1, 3);
        $this->assertOrderItemCounts($user2, 0, 1);

        Cashier::run();

        $this->assertSame(1, $user1->orders()->count());
        $this->assertSame(1, $user2->orders()->count());
        $this->assertOrderItemCounts($user1, 3, 3); // processed 3, scheduled 3
        $this->assertOrderItemCounts($user2, 1, 1); // processed 1, scheduled 1
    }

    /** @test */
    public function canSwapSubscriptionPlan()
    {
        $this->withTestNow('2019-01-01');
        $user = $this->getMandatedUser(true);

        $subscription = $user->newSubscription('default', 'monthly-20-1')->create();

        $this->assertOrderItemCounts($user, 0, 1);

        Cashier::run();

        $subscription = $subscription->fresh();
        $this->assertSame(1, $user->orders()->count());
        $this->assertOrderItemCounts($user, 1, 1);
        $processedOrderItem = $user->orderItems()->processed()->first();
        $scheduledOrderItem = $subscription->scheduledOrderItem;

        // Downgrade after two weeks
        $this->withTestNow(now()->copy()->addWeeks(2));
        $subscription = $subscription->swap('monthly-10-1');

        $this->assertSame('monthly-10-1', $subscription->plan);

        // Swapping results in a new Order being created
        $this->assertSame(2, $user->orders()->count());

        // Added one processed OrderItem for crediting surplus
        // Added one processed OrderItem for starting the new subscription cycle
        // Removed one unprocessed OrderItem for previous plan
        // Added one unprocessed OrderItem for scheduling next subscription cycle
        $this->assertOrderItemCounts($user, 3, 1);

        $this->assertNull($scheduledOrderItem->fresh());
        $this->assertNotNull($processedOrderItem->fresh());

        // Fast-forward eight days
        $this->withTestNow(now()->addMonth());

        Cashier::run();

        // Assert that an Order for this month was created
        $this->assertSame(3, $user->orders()->count());

        // Processed one unprocessed OrderItem
        // Scheduled one unprocessed OrderItem for next billing cycle
        $this->assertOrderItemCounts($user, 4, 1);
    }

    /** @test */
    public function testFormatAmount()
    {
        $this->assertSame('1.000,00 €', Cashier::formatAmount(money(100000, 'EUR')));
        $this->assertSame('-9.123,45 €', Cashier::formatAmount(money(-912345, 'EUR')));
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $user
     * @param int $processed
     * @param int $unprocessed
     */
    protected function assertOrderItemCounts(Model $user, int $processed, int $unprocessed)
    {
        $this->assertSame(
            $processed,
            $user->orderItems()->processed()->count(),
            'Unexpected amount of processed orderItems.'
        );
        $this->assertSame(
            $unprocessed,
            $user->orderItems()->unprocessed()->count(),
            'Unexpected amount of unprocessed orderItems.'
        );
        $this->assertSame(
            $processed + $unprocessed,
            $user->orderItems()->count(),
            'Unexpected total amount of orderItems.'
        );
    }

    /** @test */
    public function canOverrideDefaultCurrencySymbol()
    {
        $this->assertSame('€', Cashier::usesCurrencySymbol());
        $this->assertSame('eur', Cashier::usesCurrency());

        Cashier::useCurrency('usd');

        $this->assertSame('usd', Cashier::usesCurrency());
        $this->assertSame('$', Cashier::usesCurrencySymbol());
    }

    /** @test */
    public function canOverrideDefaultCurrencyLocale()
    {
        $this->assertSame('de_DE', Cashier::usesCurrencyLocale());

        Cashier::useCurrencyLocale('nl_NL');

        $this->assertSame('nl_NL', Cashier::usesCurrencyLocale());
    }

    /** @test */
    public function canOverrideFirstPaymentWebhookUrl()
    {
        $this->assertSame('mandate-webhook', Cashier::firstPaymentWebhookUrl());

        config(['cashier.first_payment.webhook_url' => 'https://www.example.com/webhook/mollie']);

        $this->assertSame('webhook/mollie', Cashier::firstPaymentWebhookUrl());

        config(['cashier.first_payment.webhook_url' => 'webhook/cashier']);

        $this->assertSame('webhook/cashier', Cashier::firstPaymentWebhookUrl());
    }

    /** @test */
    public function canOverrideWebhookUrl()
    {
        $this->assertSame('webhook', Cashier::webhookUrl());

        config(['cashier.webhook_url' => 'https://www.example.com/webhook/mollie']);

        $this->assertSame('webhook/mollie', Cashier::webhookUrl());

        config(['cashier.webhook_url' => 'webhook/cashier']);

        $this->assertSame('webhook/cashier', Cashier::webhookUrl());
    }
}
