<?php

namespace App\Models;

use App\Core\Permissions\SeoRoleAssignment;
use App\Models\Concerns\UsesCoreDatabaseConnection;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;
    use UsesCoreDatabaseConnection;

    /** Spatie guard — independent from Core users.role account type. */
    protected string $guard_name = 'web';

    const ROLE_ADMIN = 'admin';

    const ROLE_OWNER = 'owner';

    /**
     * @deprecated Core no longer has organizational Manager. Kept for legacy reads / migration.
     */
    const ROLE_MANAGER = 'manager';

    const ROLE_STAFF = 'staff';

    /** Short SEO rank codes — Spatie SSOT is seo.manager / seo.planner / seo.content_manager */
    const SEO_ROLE_MANAGER = 'manager';

    const SEO_ROLE_PLANNER = 'planner';

    const SEO_ROLE_CONTENT_MANAGER = 'content_manager';

    const STATUS_NORMAL = 'normal';

    const STATUS_BLOCK = 'block';

    const STATUS_PENDING = 'pending';

    /**
     * parent_id = Owner FK (legacy column name; semantics = owner_id / account scope).
     * manager_id = deprecated org-hierarchy artifact (no longer written by Core UI).
     */
    protected $fillable = [
        'parent_id',
        'manager_id',
        'role',
        'status',
        'is_system',
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
    ];

    /**
     * Compatibility alias of `name` for older UI call sites.
     * Canonical editable display name is `users.name` — not user_meta.nickname.
     *
     * @var list<string>
     */
    protected $appends = ['display_name'];

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->isSystemUser()) {
                throw new \RuntimeException('System user cannot be deleted.');
            }
        });

        static::forceDeleting(function (User $user): void {
            if ($user->isSystemUser()) {
                throw new \RuntimeException('System user cannot be deleted.');
            }
        });
    }

    public function isSystemUser(): bool
    {
        return (bool) ($this->is_system ?? false)
            || strcasecmp((string) ($this->email ?? ''), \App\Services\Users\SeoOpsSystemUser::EMAIL) === 0;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    /**
     * @deprecated Organizational manager removed; always false for new data.
     */
    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isSystemUser()) {
            return false;
        }

        if ((string) ($this->status ?? '') === self::STATUS_BLOCK) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => in_array((string) $this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true),
            'tools' => (string) ($this->status ?? '') !== self::STATUS_BLOCK,
            'seeding' => (string) ($this->status ?? '') !== self::STATUS_BLOCK,
            'seo', 'seo-main' => $this->canAccessSeoPanel(),
            default => false,
        };
    }

    /**
     * Whether this user may enter the SEO Filament panel.
     * Owner: full account access (no addon role required).
     * Staff: must belong to an owner (parent_id) and hold an SEO Spatie role.
     */
    public function canAccessSeoPanel(): bool
    {
        if ((string) ($this->status ?? '') === self::STATUS_BLOCK) {
            return false;
        }

        if ((string) $this->role === self::ROLE_OWNER) {
            return true;
        }

        if (! $this->isStaff() || (int) $this->parent_id <= 0) {
            return false;
        }

        try {
            return app(SeoRoleAssignment::class)->resolveShortRank($this) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_system' => 'boolean',
    ];

    /**
     * Owner of this Staff (column parent_id = account scope).
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /**
     * @deprecated Use owner() — parent_id means Owner.
     */
    public function parent(): BelongsTo
    {
        return $this->owner();
    }

    /**
     * @deprecated Core Manager hierarchy removed. Relation kept for legacy column reads.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @deprecated Core Manager hierarchy removed.
     */
    public function managers(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id')->where('role', self::ROLE_MANAGER);
    }

    /**
     * Staff belonging to this Owner (parent_id). Preferred team listing.
     */
    public function teamStaff(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id')->where('role', self::ROLE_STAFF);
    }

    /**
     * @deprecated Use teamStaff(). Previously meant staff under manager_id.
     */
    public function staffMembers(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id')->where('role', self::ROLE_STAFF);
    }

    /**
     * @deprecated Manager hierarchy removed; equivalent to teamStaff().
     */
    public function directStaffMembers(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id')
            ->where('role', self::ROLE_STAFF)
            ->whereNull('manager_id');
    }

    /**
     * All users with parent_id = this user. Legacy alias.
     */
    public function staffs(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    /**
     * Owner account id for scoping (Owner self, or parent_id for Staff).
     * Legacy role=manager also resolves via parent_id.
     */
    public function accountOwnerId(): ?int
    {
        if (in_array((string) $this->role, [self::ROLE_STAFF, self::ROLE_MANAGER], true)
            && (int) $this->parent_id > 0
        ) {
            return (int) $this->parent_id;
        }

        if ((string) $this->role === self::ROLE_OWNER) {
            return (int) $this->id;
        }

        return null;
    }

    public function sites()
    {
        return $this->hasMany(Site::class);
    }

    public function seoConnections()
    {
        return $this->belongsToMany(
            SeoDatabaseConnection::class,
            'seo_connection_users',
            'user_id',
            'connection_id',
        )->withTimestamps();
    }

    /**
     * Lịch sử duyệt bài SEO (cross-DB query — bảng trên connection omi_seo_ai).
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\Content\Models\SeoArticleReview>
     */
    public function articleReviews()
    {
        return \Omnichannel\Addons\Content\Models\SeoArticleReview::query()
            ->where('reviewer_id', (int) $this->id);
    }

    public function meta(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserMeta::class, 'user_id');
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        $row = $this->meta()->where('meta_key', $key)->first();

        return $row?->meta_value ?? $default;
    }

    public function setMeta(string $key, mixed $value): static
    {
        $this->meta()->updateOrCreate(
            ['meta_key' => $key],
            ['meta_value' => $value],
        );

        return $this;
    }

    /**
     * Canonical application display name (`users.name`).
     *
     * Legacy `user_meta.nickname` is not a live display source.
     */
    public function getDisplayNameAttribute(): string
    {
        return (string) ($this->attributes['name'] ?? '');
    }
}
