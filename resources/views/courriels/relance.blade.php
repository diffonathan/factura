{{--
    Le courriel de relance. Volontairement sobre : un impayé se règle mieux
    avec une information claire — la référence, le montant, le retard — qu'avec
    une mise en page soignée.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $niveauLibelle }} — {{ $facture->reference }}</title>
</head>
<body style="margin:0;padding:24px;background:#f6f7f9;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#1f2933;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;">

    <p style="margin:0 0 20px;">Bonjour,</p>

    @if ($niveau === 1)
        <p style="margin:0 0 16px;line-height:1.6;">
            Sauf erreur de notre part, la facture ci-dessous reste à régler. Il
            s'agit peut-être d'un simple oubli — si le règlement est déjà parti,
            merci de ne pas tenir compte de ce message.
        </p>
    @elseif ($niveau === 2)
        <p style="margin:0 0 16px;line-height:1.6;">
            Malgré notre premier rappel, la facture ci-dessous demeure impayée.
            Nous vous remercions de procéder au règlement, ou de nous indiquer
            la date à laquelle nous pouvons l'attendre.
        </p>
    @else
        <p style="margin:0 0 16px;line-height:1.6;">
            La facture ci-dessous reste impayée malgré nos relances. Le présent
            courrier vaut <strong>mise en demeure</strong> de régler la somme
            due. À défaut de règlement sous huit jours, des intérêts de retard
            seront appliqués conformément à la loi 69-21 relative aux délais de
            paiement, et le recouvrement sera confié à un tiers.
        </p>
    @endif

    <table style="width:100%;border-collapse:collapse;margin:24px 0;font-size:15px;">
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #eceff3;color:#5b6675;">Facture</td>
            <td style="padding:10px 0;border-bottom:1px solid #eceff3;text-align:right;font-weight:600;">{{ $facture->reference }}</td>
        </tr>
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #eceff3;color:#5b6675;">Date</td>
            <td style="padding:10px 0;border-bottom:1px solid #eceff3;text-align:right;">{{ $facture->date_emission->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #eceff3;color:#5b6675;">Échéance</td>
            <td style="padding:10px 0;border-bottom:1px solid #eceff3;text-align:right;">{{ $facture->date_echeance?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #eceff3;color:#5b6675;">Retard</td>
            <td style="padding:10px 0;border-bottom:1px solid #eceff3;text-align:right;">{{ $facture->joursDeRetard() }} jours</td>
        </tr>
        <tr>
            <td style="padding:14px 0;color:#5b6675;">Reste dû</td>
            <td style="padding:14px 0;text-align:right;font-size:20px;font-weight:700;color:#0f7b45;">
                {{ number_format((float) $facture->resteAPayer(), 2, ',', ' ') }} {{ $facture->devise }}
            </td>
        </tr>
    </table>

    <p style="margin:0 0 4px;line-height:1.6;">Cordialement,</p>
    <p style="margin:0;font-weight:600;">{{ $facture->entreprise->raison_sociale }}</p>

    @if ($facture->entreprise->rib)
        <p style="margin:20px 0 0;font-size:13px;color:#5b6675;line-height:1.6;">
            Règlement par virement —
            @if ($facture->entreprise->banque){{ $facture->entreprise->banque }},@endif
            RIB {{ $facture->entreprise->rib }}
        </p>
    @endif

    <p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #eceff3;font-size:12px;color:#8a95a5;line-height:1.6;">
        {{ implode(' · ', $facture->entreprise->mentionsLegales()) }}
    </p>
</div>
</body>
</html>
