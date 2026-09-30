<?php

namespace App\Http\Middleware;

use App\Models\Entreprise;
use App\Support\EntrepriseCourante;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $requete): array
    {
        $utilisateur = $requete->user();
        $courante = app(EntrepriseCourante::class);

        return [
            ...parent::share($requete),

            // Partagé plutôt que renvoyé par chaque contrôleur : l'en-tête en a
            // besoin sur toutes les pages, et le répéter vingt fois finit par
            // un oubli sur la vingt-et-unième.
            'utilisateur' => $utilisateur ? [
                'nom' => $utilisateur->name,
                'email' => $utilisateur->email,
                'role' => $courante->estDefinie()
                    ? $utilisateur->roleDans($courante->id())
                    : null,
            ] : null,

            'entreprise' => fn () => $courante->estDefinie()
                ? Entreprise::find($courante->id())?->only(['id', 'raison_sociale', 'ville'])
                : null,

            // Les messages d'une action réussie. Fermeture différée : Inertia
            // ne l'évalue que lorsqu'il envoie réellement la page.
            'flash' => [
                'succes' => fn () => $requete->session()->get('succes'),
            ],
        ];
    }
}
