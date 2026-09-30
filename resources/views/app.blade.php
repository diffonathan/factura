<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Factura') }}</title>

    {{-- @routes n'est pas utilisé : les URL sont passées explicitement depuis
         les contrôleurs. Exposer toute la table de routage au navigateur
         renseigne un visiteur sur des chemins qu'il n'a pas à connaître. --}}
    {{-- Les deux fontes de la charte : DM Sans pour le texte, IBM Plex Mono
         pour les nombres. `display=swap` affiche le texte avec la fonte du
         système pendant le téléchargement plutôt que de laisser la page vide. --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|ibm-plex-mono:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full antialiased">
    @inertia
</body>
</html>
