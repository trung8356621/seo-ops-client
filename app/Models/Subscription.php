<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SERVER-OWNED LEGACY / PENDING SERVER CUTOVER.
 *
 * SaaS subscription belongs to ops-server. Table kept as schema reference.
 * Do not drop until server cutover.
 */
class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'plan_id', 'starts_at', 'ends_at', 'status'];

    public function plan()
    {
        return $this->belongsTo(ServicePlan::class, 'plan_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Kiểm tra xem subscription còn hạn không
    public function isValid()
    {
        return $this->status === 'active' && now()->lessThan($this->ends_at);
    }
}
