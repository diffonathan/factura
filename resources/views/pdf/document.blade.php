{{--
    Le document tel qu'il sera remis au client.

    Mise en page par TABLEAUX, et c'est imposé : le moteur PDF ne connaît ni
    flexbox ni grid. Écrire la feuille comme une page web moderne donnerait un
    empilement vertical illisible — sans erreur, ce qui est pire.

    Aucune couleur de la charte de l'application ici. Une facture est un
    document légal destiné à l'impression et à l'archivage : elle se lit en
    noir sur blanc, y compris photocopiée.
--}}
@php
    /** Montant au format marocain : séparateur de milliers espace, décimales virgule. */
    $montant = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ');
    $estEmis = $document->numero !== null;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $estEmis ? $document->reference : 'Brouillon' }}</title>
    <style>
        @page { margin: 18mm 15mm 22mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #000; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        .entete td { vertical-align: top; }
        .emetteur { font-size: 9pt; }
        .emetteur .nom { font-size: 13pt; font-weight: bold; }
        .titre { text-align: right; }
        .titre .type { font-size: 18pt; font-weight: bold; letter-spacing: 1px; }
        .titre .numero { font-size: 12pt; margin-top: 2mm; }
        .brouillon {
            margin: 6mm 0; padding: 3mm; border: 1.5pt solid #000;
            text-align: center; font-weight: bold; font-size: 10pt;
        }
        .client { margin-top: 10mm; }
        .client .cadre { border: 0.5pt solid #000; padding: 3mm; width: 48%; }
        .client .etiquette { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1px; }
        .lignes { margin-top: 8mm; }
        .lignes th {
            border-bottom: 1pt solid #000; padding: 2mm 1.5mm;
            font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5px; text-align: left;
        }
        .lignes td { border-bottom: 0.3pt solid #999; padding: 2mm 1.5mm; }
        {{-- `nowrap` sur les nombres : sans lui, « 94 560,00 MAD » se coupe à
             l'espace des milliers et un montant s'affiche sur trois lignes. --}}
        .n { text-align: right; white-space: nowrap; }
        .totaux { margin-top: 6mm; }
        {{-- 100 % de la CELLULE, pas de la page. Une largeur en pourcentage se
             calcule sur le conteneur : « 46 % » dans une cellule qui occupe la
             moitié de la page donnait un bloc de 23 %, et tous les libellés
             passaient à la ligne. --}}
        .totaux .bloc { width: 100%; }
        .totaux td { padding: 1.2mm 2mm; }
        .totaux .libelle { white-space: nowrap; }
        .totaux .ttc td { border-top: 1pt solid #000; font-weight: bold; font-size: 11pt; padding-top: 2.5mm; }
        .ventilation th, .ventilation td { border: 0.3pt solid #999; padding: 1.5mm; font-size: 8pt; }
        .reglements { margin-top: 7mm; }
        .reglements th { text-align: left; font-size: 7.5pt; text-transform: uppercase; border-bottom: 0.5pt solid #000; padding: 1.5mm; }
        .reglements td { padding: 1.5mm; border-bottom: 0.3pt solid #999; }
        .bas-de-page { margin-top: 7mm; font-size: 8.5pt; }
        .pied {
            position: fixed; bottom: -14mm; left: 0; right: 0;
            font-size: 7pt; text-align: center; color: #333;
            border-top: 0.3pt solid #999; padding-top: 2mm;
        }
    </style>
</head>
<body>

<table class="entete">
    <tr>
        <td class="emetteur" style="width: 55%;">
            <div class="nom">{{ $emetteur->raison_sociale }}{{ $emetteur->forme_juridique ? ' '.$emetteur->forme_juridique : '' }}</div>
            @if ($emetteur->adresse)<div>{{ $emetteur->adresse }}</div>@endif
            @if ($emetteur->ville)<div>{{ $emetteur->ville }}</div>@endif
            @if ($emetteur->telephone)<div>Tél. {{ $emetteur->telephone }}</div>@endif
            @if ($emetteur->email)<div>{{ $emetteur->email }}</div>@endif
        </td>
        <td class="titre">
            <div class="type">{{ mb_strtoupper($document->type->libelle()) }}</div>
            @if ($estEmis)
                <div class="numero">{{ $document->reference }}</div>
            @endif
            <div style="margin-top: 3mm;">
                Date : {{ $document->date_emission->format('d/m/Y') }}
                @if ($document->date_echeance)
                    <br>Échéance : {{ $document->date_echeance->format('d/m/Y') }}
                @endif
            </div>
        </td>
    </tr>
</table>

@unless ($estEmis)
    {{--
        Un brouillon n'a pas de numéro, parce que le numéro n'est attribué qu'à
        l'émission — c'est ce qui garantit une série sans trou. Le dire en
        toutes lettres évite qu'un document de travail circule comme une
        facture, ce qu'un client ne pourrait pas distinguer autrement.
    --}}
    <div class="brouillon">
        BROUILLON — document de travail, sans valeur légale<br>
        <span style="font-weight: normal; font-size: 8.5pt;">
            Le numéro de la série légale n'est attribué qu'à l'émission.
        </span>
    </div>
@endunless

<table class="client">
    <tr>
        <td style="width: 52%;"></td>
        <td>
            <div class="cadre" style="width: auto;">
                <div class="etiquette">Client</div>
                <div style="font-weight: bold; margin-top: 1mm;">{{ $client->nom }}</div>
                @if ($client->adresse)<div>{{ $client->adresse }}</div>@endif
                @if ($client->ville)<div>{{ $client->ville }}</div>@endif
                @if ($client->ice)<div style="margin-top: 1.5mm;">ICE : {{ $client->ice }}</div>@endif
                @if ($client->identifiant_fiscal)<div>IF : {{ $client->identifiant_fiscal }}</div>@endif
            </div>
        </td>
    </tr>
</table>

@if ($document->objet)
    <div style="margin-top: 7mm;"><strong>Objet :</strong> {{ $document->objet }}</div>
@endif

<table class="lignes">
    <thead>
        <tr>
            <th style="width: 38%;">Désignation</th>
            <th class="n" style="width: 8%;">Qté</th>
            <th style="width: 10%;">Unité</th>
            <th class="n" style="width: 13%;">P.U. HT</th>
            <th class="n" style="width: 9%;">Rem.</th>
            <th class="n" style="width: 9%;">TVA</th>
            <th class="n" style="width: 13%;">Montant HT</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document->lignes as $ligne)
            <tr>
                <td>{{ $ligne->designation }}</td>
                <td class="n">{{ rtrim(rtrim(number_format((float) $ligne->quantite, 3, ',', ' '), '0'), ',') }}</td>
                <td>{{ $ligne->unite }}</td>
                <td class="n">{{ $montant($ligne->prix_unitaire_ht) }}</td>
                <td class="n">{{ (float) $ligne->remise_pct > 0 ? $montant($ligne->remise_pct).' %' : '—' }}</td>
                <td class="n">{{ (float) $ligne->taux_tva > 0 ? rtrim(rtrim(number_format((float) $ligne->taux_tva, 2, ',', ' '), '0'), ',').' %' : 'Exo.' }}</td>
                <td class="n">{{ $montant($ligne->montant_ht) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totaux">
    <tr>
        <td style="vertical-align: top; width: 44%; padding-right: 8mm;">
            {{-- La ventilation par taux est une mention obligatoire dès que
                 plusieurs taux coexistent. On l'affiche toujours : un
                 contrôleur la cherche, et son absence se remarque. --}}
            <table class="ventilation">
                <tr>
                    <th>Taux</th>
                    <th class="n">Base HT</th>
                    <th class="n">TVA</th>
                </tr>
                @foreach ($ventilation as $tranche)
                    <tr>
                        <td>{{ $tranche['taux'] > 0 ? rtrim(rtrim(number_format($tranche['taux'], 2, ',', ' '), '0'), ',').' %' : 'Exonéré' }}</td>
                        <td class="n">{{ $montant($tranche['base']) }}</td>
                        <td class="n">{{ $montant($tranche['tva']) }}</td>
                    </tr>
                @endforeach
            </table>
        </td>
        <td style="vertical-align: top;">
            <table class="bloc">
                <tr>
                    <td class="libelle">Total HT</td>
                    <td class="n">{{ $montant($document->montant_ht) }}</td>
                </tr>
                <tr>
                    <td class="libelle">TVA</td>
                    <td class="n">{{ $montant($document->montant_tva) }}</td>
                </tr>
                <tr class="ttc">
                    <td class="libelle">Total TTC</td>
                    <td class="n">{{ $montant($document->montant_ttc) }} {{ $document->devise }}</td>
                </tr>
                @if ((float) $document->montant_paye > 0)
                    <tr>
                        <td class="libelle">Déjà réglé</td>
                        <td class="n">{{ $montant($document->montant_paye) }}</td>
                    </tr>
                    <tr>
                        <td class="libelle"><strong>Reste à payer</strong></td>
                        <td class="n"><strong>{{ $montant($document->resteAPayer()) }} {{ $document->devise }}</strong></td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if ($document->paiements->isNotEmpty())
    <table class="reglements">
        <tr>
            <th>Règlements reçus</th>
            <th>Mode</th>
            <th>Référence</th>
            <th class="n">Montant</th>
        </tr>
        @foreach ($document->paiements as $paiement)
            <tr>
                <td>{{ $paiement->date_paiement->format('d/m/Y') }}</td>
                <td>{{ $paiement->mode->libelle() }}</td>
                <td>{{ $paiement->reference ?: '—' }}</td>
                <td class="n">{{ $montant($paiement->montant) }}</td>
            </tr>
        @endforeach
    </table>
@endif

{{-- Conditions et banque CÔTE À CÔTE. Empilées, elles poussaient les
     coordonnées bancaires seules sur une deuxième page — une page entière
     pour une ligne, que le destinataire imprime quand même. --}}
@if ($document->conditions || $emetteur->banque || $emetteur->rib)
    <table class="bas-de-page">
        <tr>
            <td style="width: 50%; padding-right: 6mm; vertical-align: top;">
                @if ($document->conditions)
                    <strong>Conditions de règlement</strong><br>{{ $document->conditions }}
                @endif
            </td>
            <td style="vertical-align: top;">
                @if ($emetteur->banque || $emetteur->rib)
                    <strong>Coordonnées bancaires</strong><br>
                    {{ $emetteur->banque }}@if ($emetteur->banque && $emetteur->rib)<br>@endif{{ $emetteur->rib }}
                @endif
            </td>
        </tr>
    </table>
@endif

<div class="pied">
    {{ $emetteur->raison_sociale }}{{ $emetteur->forme_juridique ? ' '.$emetteur->forme_juridique : '' }}
    @if ($emetteur->mentionsLegales())
        — {{ implode(' · ', $emetteur->mentionsLegales()) }}
    @endif
</div>

</body>
</html>
