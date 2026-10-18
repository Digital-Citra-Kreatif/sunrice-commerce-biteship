<?php

declare(strict_types=1);

namespace Workbench\App\Commerce;

use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Payments\Models\Payment;
use Sunrice\Commerce\Payments\PaymentMethodBase;
use Sunrice\Commerce\Payments\PaymentStart;

/** A manual method for tests: shows instructions with the amount. */
class TestTransfer extends PaymentMethodBase
{
    public static function key(): string
    {
        return 'test_transfer';
    }

    public function label(): string
    {
        return 'Test transfer';
    }

    public function settingsSchema(): array
    {
        return [['handle' => 'account', 'type' => 'text', 'label' => 'Account', 'translatable' => false]];
    }

    public function start(Order $order, Payment $payment): PaymentStart
    {
        return PaymentStart::instructions(['account' => $this->setting('account', '123'), 'amount' => $payment->amount]);
    }
}
