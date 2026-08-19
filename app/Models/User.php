<?php

namespace App\Models;


use App\Enums\Activity;
use App\Enums\Ask;
use App\Models\Address;
use App\Models\Scopes\BranchScope;
use App\Traits\MultiTenantModelTrait;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;


class User extends Authenticatable implements HasMedia
{
    use InteractsWithMedia;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use MultiTenantModelTrait;
    use Notifiable;
    use SoftDeletes;


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */

    protected $table = "users";
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'branch_id',
        'country_code',
        'is_guest',
        'status',
        'email_verified_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id'                => 'integer',
        'name'              => 'string',
        'email'             => 'string',
        'password'          => 'string',
        'username'          => 'string',
        'phone'             => 'string',
        'branch_id'         => 'integer',
        'country_code'      => 'string',
        'is_guest'          => 'integer',
        'status'            => 'integer',
        'email_verified_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];


    protected $appends = ['myrole'];

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());

        // [Sprint 2B / DEL-4] users.phone is now NOT NULL (see
        // 2026_05_16_140100_make_user_phone_required migration). Several
        // legacy code paths create User rows without supplying a phone
        // (admin tooling, walk-in customer, console commands, sentinel
        // factories). To preserve backward compat without weakening the
        // NOT NULL invariant, we backfill an empty phone with a stable
        // `PENDING_CREATE_<rand>` sentinel that fails App\Rules\ValidPhone
        // (non-digit prefix). DELIVERY orders made by such users are
        // rejected by OrderRequest::validateAuthenticatedUserPhoneForDelivery
        // until they update their profile.
        //
        // We intentionally do NOT use `PENDING_<id>` here because the row
        // does not have an id yet at `creating`; the random suffix is
        // unique enough to never collide with another sentinel. The
        // migration backfill uses `PENDING_<id>` for already-existing rows.
        static::creating(function ($user) {
            if (empty($user->phone) || $user->phone === null) {
                $sentinel = 'PENDING_CREATE_' . bin2hex(random_bytes(6));
                // [Sprint 5A Z9-P0-02] Make sentinel injection auditable. The
                // NOT NULL migration is otherwise decorative because legacy
                // call sites (admin tooling, console, factories) don't supply
                // a phone. Logging the inject + caller lets ops surface
                // sentinel-polluted users for follow-up (the DELIVERY path
                // already rejects them via OrderRequest + ValidPhone).
                \Illuminate\Support\Facades\Log::warning('User created without phone — sentinel injected', [
                    'name'     => $user->name ?? null,
                    'email'    => $user->email ?? null,
                    'sentinel' => $sentinel,
                ]);
                $user->phone = $sentinel;
            }
        });

        // [GOAL-J2-HEAL-01 2026-05-24] Phase J-ADV-7 HC-001 P0:
        // hardcoded id===1 super-admin auto-restore was a security
        // back-door. Compromised credentials remained usable after
        // disablement attempt (status forced back to ACTIVE on every
        // save). Critical vector for insider attack OR account-takeover
        // persistence. Removed — admins must be properly disabled via
        // the standard disable flow.
        //
        // Original intent: prevent accidental lockout of root admin.
        // Better defense: separate seed migration that creates a
        // recovery user + documented recovery procedure in runbook.
        // NO automatic status re-activation in model lifecycle.
        //
        // Sentinel: tests/Feature/Security/UserSuperAdminDisableHardenedSentinelTest.php
        static::updating(function ($user) {
            // intentionally empty — see comment above
        });
    }

    public function getMyRoleAttribute()
    {
        return $this->roles->pluck('id', 'id')->first();
    }

    public function getrole(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Role::class, 'id', 'myrole');
    }

    public function addresses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function getFirstNameAttribute(): string
    {
        $name = explode(' ', $this->name, 2);
        return $name[0];
    }

    public function getLastNameAttribute(): string
    {
        $name = explode(' ', $this->name, 2);
        return !empty($name[1]) ? $name[1] : '';
    }

    public function getImageAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('profile'))) {
            return asset($this->getFirstMediaUrl('profile'));
        }
        return asset('images/default/profile.png');
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MessageHistory::class, 'user_id', 'id')->where('is_read', Ask::NO);
    }

    /**
     * [FIDÉLITÉ 2026-08-19] EST-CE UN COMPTE DE CLIENT, ET NON DU PERSONNEL ?
     *
     * ── POURQUOI CETTE RÈGLE VIT ICI ─────────────────────────────────────────────────────────
     * Deux outils de fidélité en avaient besoin le même jour : le vérificateur de santé et la
     * fusion des comptes en double. Écrite deux fois, elle a immédiatement divergé — le premier
     * annonçait 6 clients en double, le second n'en traitait qu'1, et l'exploitant devant deux
     * chiffres contradictoires cesse de croire les deux. C'est le motif du « jumeau oublié »
     * pris à sa naissance, avant qu'il ne coûte quelque chose.
     *
     * ── CE QU'ELLE PROTÈGE ───────────────────────────────────────────────────────────────────
     * Dans un restaurant de quartier, l'exploitant ou un caissier partage volontiers son numéro
     * avec un client. Un outil qui regrouperait « les comptes de ce numéro » sans distinguer
     * transférerait les points DU CLIENT vers le compte DU PERSONNEL. Le doute profite au
     * client : au moindre rôle d'exploitation, ce n'est pas un compte de fidélité.
     */
    public function isLoyaltyCustomer(): bool
    {
        return ! $this->hasAnyRole([
            'Admin',
            'Branch Manager',
            'POS Operator',
            'Chef',
            'Waiter',
            'Delivery Boy',
            'Stuff',
        ]);
    }
}
