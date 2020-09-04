<?php

namespace Laravel\Cashier\Tests;

use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Coupon\RedeemedCoupon;
use Laravel\Cashier\Coupon\RedeemedCouponCollection;
use Laravel\Cashier\Events\MandateClearedFromBillable;
use Laravel\Cashier\SubscriptionBuilder\FirstPaymentSubscriptionBuilder;
use Laravel\Cashier\SubscriptionBuilder\MandatedSubscriptionBuilder;
use Laravel\Cashier\Tests\Fixtures\User;

class BillableTest extends BaseTestCase
{
    /** @test */
    public function testTaxPercentage()
    {
        $this->withPackageMigrations();
        $user = factory(User::class)->create([
            'tax_percentage' => 21.5,
        ]);

        $this->assertSame(21.5, $user->taxPercentage());
    }

    /** @test */
    public function returnsFirstPaymentSubscriptionBuilderIfMandateIdOnOwnerIsNull()
    {
        $this->withConfiguredPlans();
        $user = $this->getUser(false, ['mollie_mandate_id' => null]);

        $builder = $user->newSubscription('default', 'monthly-10-1');

        $this->assertInstanceOf(FirstPaymentSubscriptionBuilder::class, $builder);
    }

    /** @test */
    public function returnsFirstPaymentSubscriptionBuilderIfOwnerMandateIsInvalid()
    {
        $this->withConfiguredPlans();
        $this->withPackageMigrations();

        $revokedMandateId = 'mdt_MvfK2PRzNJ';

        $user = $this->getUser(false, ['mollie_mandate_id' => $revokedMandateId]);

        $builder = $user->newSubscription('default', 'monthly-10-1');

        $this->assertInstanceOf(FirstPaymentSubscriptionBuilder::class, $builder);
    }

    /** @test */
    public function returnsDefaultSubscriptionBuilderIfOwnerHasValidMandateId()
    {
        $this->withConfiguredPlans();
        $user = $this->getMandatedUser(false);

        $builder = $user->newSubscription('default', 'monthly-10-1');

        $this->assertInstanceOf(MandatedSubscriptionBuilder::class, $builder);
    }

    /** @test */
    public function canRetrieveRedeemedCoupons()
    {
        $this->withPackageMigrations();

        $user = factory(User::class)->create();

        $redeemedCoupons = $user->redeemedCoupons;
        $this->assertInstanceOf(RedeemedCouponCollection::class, $redeemedCoupons);
        $this->assertCount(0, $redeemedCoupons);
    }

    /** @test */
    public function canRedeemCouponForExistingSubscription()
    {
        $this->withPackageMigrations();
        $this->withConfiguredPlans();
        $this->withMockedCouponRepository(); // 'test-coupon'

        $user = $this->getMandatedUser();
        $subscription = $user->newSubscription('default', 'monthly-10-1')->create();
        $this->assertSame(0, $user->redeemedCoupons()->count());

        $user = $user->redeemCoupon('test-coupon', 'default', false);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $user->redeemedCoupons()->count());
        $this->assertSame(1, $subscription->redeemedCoupons()->count());
        $this->assertSame(0, $subscription->appliedCoupons()->count());
    }

    /** @test */
    public function canRedeemCouponAndRevokeOtherCoupons()
    {
        $this->withPackageMigrations();
        $this->withConfiguredPlans();
        $this->withMockedCouponRepository(); // 'test-coupon'

        $user = $this->getMandatedUser();
        $subscription = $user->newSubscription('default', 'monthly-10-1')->create();
        $subscription->redeemedCoupons()->saveMany(factory(RedeemedCoupon::class, 2)->make());
        $this->assertSame(2, $subscription->redeemedCoupons()->active()->count());
        $this->assertSame(0, $subscription->appliedCoupons()->count());

        $user = $user->redeemCoupon('test-coupon', 'default', true);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $user->redeemedCoupons()->active()->count());
        $this->assertSame(1, $subscription->redeemedCoupons()->active()->count());
        $this->assertSame(0, $subscription->appliedCoupons()->count());
    }

    /** @test */
    public function clearMollieMandate()
    {
        Event::fake();
        $this->withPackageMigrations();
        $user = $this->getUser(true, ['mollie_mandate_id' => 'foo-bar']);
        $this->assertSame('foo-bar', $user->mollieMandateId());

        $user->clearMollieMandate();

        $this->assertNull($user->mollieMandateId());
        Event::assertDispatched(MandateClearedFromBillable::class, function ($e) use ($user) {
            $this->assertSame('foo-bar', $e->oldMandateId);
            $this->assertTrue($e->owner->is($user));

            return true;
        });
    }

}
