<?php

namespace App\Models;

use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'notify_email'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The model's default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::Viewer->value,
        'is_active' => true,
        'must_change_password' => false,
        'notify_email' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'notify_email' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function role(): UserRole
    {
        return $this->getAttribute('role') ?? UserRole::Viewer;
    }

    public function isAdmin(): bool
    {
        return $this->role() === UserRole::Admin;
    }

    public function canOperate(): bool
    {
        return in_array($this->role(), [UserRole::Admin, UserRole::Operator], true);
    }
}
