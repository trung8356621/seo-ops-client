<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SERVER-OWNED LEGACY / PENDING SERVER CUTOVER.
 *
 * Wallet belongs to ops-server (SaaS billing). Table kept as schema reference.
 * Do not reuse for client runtime. Do not drop until server cutover.
 */
class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance', 'currency'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
