<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SERVER-OWNED LEGACY / PENDING SERVER CUTOVER.
 *
 * Wallet ledger belongs to ops-server. Table kept as schema reference.
 * Do not drop until server cutover.
 */
class Transaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'before_balance',
        'after_balance',
        'status',
        'reference_id',
        'description'
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
