<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** "This product's opening balance at this location has been posted" (unique per company/location/product). */
class OpeningStockRegistration extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];
}
