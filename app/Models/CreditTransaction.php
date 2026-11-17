<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';

    public const CHARGE = 'charge';

    protected $guarded = [];
}
