<?php

namespace App\Models;

use App\Util\FunctionReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;
use stdClass;

/**
 * App\Models\Admin
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Activitylog\Models\Activity[] $activities
 * @property-read int|null $activities_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection|\Illuminate\Notifications\DatabaseNotification[] $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Permission\Models\Permission[] $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Permission\Models\Role[] $roles
 * @property-read int|null $roles_count
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin permission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin role($roles, $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Admin whereUpdatedAt($value)
 * @mixin \Eloquent
 * @property int $status 状态 1:正常 2:禁止
 * @method static Builder|Admin whereStatus($value)
 */
class Admin extends Authenticatable implements JWTSubject
{
    use Notifiable, HasRoles, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'status'
    ];

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logFillable()
            ->logUnguarded();
    }

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims(): array
    {
        return ['role' => 'admin'];
    }

    /**
     * 获取列表
     *
     * @param array $validated
     * @return array
     */
    public static function getList(array $validated): array
    {
        $model = DB::table(function (Query $query) use ($validated) {
            $query->from('admins')
                ->groupBy('admins.id')
                ->join('model_has_roles', function (JoinClause $join) {
                    $join->on('admins.id', '=', 'model_has_roles.model_id')
                        ->where('model_type', '=', 'App\\Models\\Admin');
                }, null, null, 'left')
                ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->when($validated['name'] ?? null, function (Query $query) use ($validated): Query {
                    return $query->where('admins.name', 'like', '%' . $validated['name'] . '%');
                })
                ->when($validated['email'] ?? null, function (Query $query) use ($validated): Query {
                    return $query->where('admins.email', 'like', '%' . $validated['email'] . '%');
                })
                ->when(isset($validated['status']) && is_numeric($validated['status']), function (Query $query) use ($validated): Query {
                    return $query->where('admins.status', '=', $validated['status']);
                })
                ->when($validated['start_at'] ?? null, function (Query $query) use ($validated): Query {
                    return $query->whereBetween('admins.created_at', [$validated['start_at'], $validated['end_at']]);
                })
                ->when(
                    isset($validated['role_ids']) && count($validated['role_ids']),
                    function (Query $query) use ($validated): Query {
                        $roleIds = implode('|', $validated['role_ids']);
                        return $query->havingRaw("CONCAT (',',role_ids,',') REGEXP ',({$roleIds}),'");
                    }
                )->select([
                    'admins.id',
                    'admins.name',
                    'admins.email',
                    DB::raw(' GROUP_CONCAT(roles.id) as role_ids'),
                    DB::raw(' GROUP_CONCAT(roles.name) as role_names'),
                    'admins.status',
                    'admins.created_at',
                    'admins.updated_at',
                ]);
        }, 'admins');

        $total = $model->count('id');

        $admins = $model
            ->orderBy($validated['sort'] ?? 'created_at', $validated['order'] === 'ascending' ? 'asc' : 'desc')
            ->offset(($validated['offset'] - 1) * $validated['limit'])
            ->limit($validated['limit'])
            ->get()
            ->map(function (stdClass $admin): stdClass {
                $admin->role_ids = is_string($admin->role_ids) ? explode(',', $admin->role_ids) : [];
                $admin->role_names = is_string($admin->role_names) ? explode(',', $admin->role_names) : [];
                return $admin;
            });

        return [
            'admins' => $admins,
            'total' => $total
        ];
    }

    /**
     * 创建
     *
     * @param array $attributes
     * @return Admin
     */
    public static function create(array $attributes): Admin
    {
        $attributes['password'] = Hash::make($attributes['password']);
        return static::query()->create($attributes);
    }

    /**
     * 更新
     *
     * @param array $data
     * @return array
     */
    public static function updateSave(array $data): FunctionReturn
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $admin = Admin::find($data['id']);
        unset($data['id']);

        return new FunctionReturn($admin->update($data), '', [
            'admin' => $admin
        ]);
    }

    public static function selectAll(): Collection
    {
        return Admin::select(['id', 'name'])->get();
    }
}
