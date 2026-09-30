<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    /**
     * Les entreprises dans lesquelles cet utilisateur travaille, avec son rôle
     * dans chacune. Un comptable indépendant en a plusieurs, et n'y a pas
     * forcément les mêmes droits.
     */
    public function entreprises(): BelongsToMany
    {
        return $this->belongsToMany(Entreprise::class, 'entreprise_utilisateur')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Le rôle tenu dans une entreprise donnée, ou null si l'utilisateur n'y
     *  a pas accès — ce que l'autorisation doit traiter comme un refus. */
    public function roleDans(Entreprise|int $entreprise): ?string
    {
        $id = $entreprise instanceof Entreprise ? $entreprise->id : $entreprise;

        return $this->entreprises->firstWhere('id', $id)?->pivot->role;
    }

    public function peutEmettreDans(Entreprise|int $entreprise): bool
    {
        return in_array($this->roleDans($entreprise), ['PROPRIETAIRE', 'COMPTABLE'], true);
    }
}
