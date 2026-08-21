<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Traits\HasQuotas;

/**
 * A billable whose subscription is held by Cashier rather than by this package.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 */
class CashierUser extends Model
{
    use HasQuotas;

    protected $guarded = [];

    protected $table = 'users';

    /**
     * The subscription Cashier would return, injected by the test.
     */
    public ?FakeCashierSubscription $fakeSubscription = null;

    /**
     * The subscription type asked for, recorded so tests can assert it.
     */
    public ?string $requestedType = null;

    public function subscription(string $type = 'default'): ?FakeCashierSubscription
    {
        $this->requestedType = $type;

        return $this->fakeSubscription;
    }
}
