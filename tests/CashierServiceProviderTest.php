<?php

namespace Laravel\Cashier\Tests;

use Laravel\Cashier\Cashier;
use Laravel\Cashier\CashierServiceProvider;

class CashierServiceProviderTest extends BaseTestCase
{
    /** @test */
    public function canOptionallySetCurrencyInConfig()
    {
        $this->assertSame('INEXISTENT', config('cashier.currency', 'INEXISTENT'));

        $this->assertSame('€', Cashier::usesCurrencySymbol());
        $this->assertSame('eur', Cashier::usesCurrency());

        config(['cashier.currency' => 'usd']);
        $this->rebootCashierServiceProvider();

        $this->assertSame('usd', Cashier::usesCurrency());
        $this->assertSame('$', Cashier::usesCurrencySymbol());
    }

    /** @test */
    public function canOptionallySetCurrencyLocaleInConfig()
    {
        $this->assertSame('INEXISTENT', config('cashier.currency_locale', 'INEXISTENT'));
        $this->assertSame('de_DE', Cashier::usesCurrencyLocale());

        config(['cashier.currency_locale' => 'nl_NL']);
        $this->rebootCashierServiceProvider();

        $this->assertSame('nl_NL', Cashier::usesCurrencyLocale());
    }

    protected function rebootCashierServiceProvider()
    {
        tap(new CashierServiceProvider($this->app))->register()->boot();
    }
}
