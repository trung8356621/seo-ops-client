<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SERVER-OWNED LEGACY / PENDING SERVER CUTOVER.
 *
 * BILLING usage only: subscription_id + metric_key + current_usage + limit_value.
 * Do NOT reuse this table for future daily client telemetry.
 * Table kept as schema reference. Do not drop until server cutover.
 */
class UsageLog extends Model
{
    protected $fillable = ['subscription_id', 'metric_key', 'current_usage', 'limit_value'];

    // Kiểm tra xem đã vượt hạn mức chưa
    public function isExceeded()
    {
        return $this->current_usage >= $this->limit_value;
    }
}
