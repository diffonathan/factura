<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Factura') }}</title>

    {{-- @routes n'est pas utilisé : les URL sont passées explicitement depuis
         les contrôleurs. Exposer toute la table de routage au navigateur
         renseigne un visiteur sur des chemins qu'il n'a pas à connaître. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full antialiased">
    @inertia
</body>
</html>
