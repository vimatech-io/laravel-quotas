<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Contracts\ManagesLocalSubscription;
use VimaTech\LaravelQuotas\Contracts\QuotaAware;
use VimaTech\LaravelQuotas\Traits\HasQuotas;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 */
class User extends Model implements ManagesLocalSubscription, QuotaAware
{
    use HasQuotas;

    protected $guarded = [];

    protected $table = 'users';
}
