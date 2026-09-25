<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Traits\HasQuotas;

/**
 * A billable that holds no subscription of its own: its owners do.
 *
 * @property int $id
 * @property string $name
 */
class Team extends Model
{
    use HasQuotas;

    protected $guarded = [];

    protected $table = 'teams';

    /** @var array<int, CashierUser> */
    public array $owners = [];
}
