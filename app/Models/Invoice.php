<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SERVER-OWNED LEGACY / PENDING SERVER CUTOVER.
 *
 * Invoice belongs to ops-server. Table kept as schema reference.
 * Do not drop until server cutover.
 */
class Invoice extends Model
{
    protected $fillable = [
        'order_id',
        'invoice_number',
        'total_amount',
        'pdf_path',
        'issued_at'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
