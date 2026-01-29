<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Fixtures\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Moffhub\MakerChecker\Contracts\MakerCheckerUserContract;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $team_id
 * @property string|null $role
 */
class User extends Model implements Authenticatable, MakerCheckerUserContract
{
    use Notifiable;

    protected $guarded = [];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function getMakerCheckerEmail(): ?string
    {
        return $this->email;
    }

    public function getMakerCheckerRole(): ?string
    {
        return $this->role;
    }

    public function getMakerCheckerTeamId(): ?int
    {
        return $this->team_id;
    }

    public function hasMakerCheckerPermission(string $permission): bool
    {
        // Simple permission check for testing
        return $this->role === 'admin';
    }

    // Authenticatable interface methods

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Not used in tests
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
