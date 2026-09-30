<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Documentation technique
    |--------------------------------------------------------------------------
    |
    | La documentation détaillée est ouverte par un mot de passe, distinct des
    | comptes de l'application : elle s'adresse à un recruteur ou à un
    | développeur, pas à un utilisateur de la facturation.
    |
    | Le mot de passe vit dans `.env`, qui n'est PAS versionné. Ce dépôt est
    | public : une valeur écrite ici serait lisible par tout le monde, et le
    | verrou ne verrouillerait rien.
    |
    | Vide par défaut, et le contrôleur refuse alors l'accès plutôt que de
    | laisser entrer avec une chaîne vide — une variable oubliée au déploiement
    | ouvrirait sinon la porte en grand, sans le moindre message.
    |
    */
    'documentation' => [
        'mot_de_passe' => env('DOCUMENTATION_MOT_DE_PASSE', ''),
    ],

];
