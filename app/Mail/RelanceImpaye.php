<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Document;
use App\Models\Relance;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Le courriel de relance. Le ton monte avec le niveau, pas la mise en page.
 *
 * Le niveau 3 nomme la loi 69-21 et les intérêts de retard : à ce stade le
 * courrier doit pouvoir servir de pièce, et une formule vague ne servirait à
 * rien devant un tribunal de commerce.
 */
final class RelanceImpaye extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Document $facture,
        public readonly int $niveau,
    ) {}

    public function envelope(): Envelope
    {
        $objet = match ($this->niveau) {
            1 => "Rappel : facture {$this->facture->reference}",
            2 => "Relance : facture {$this->facture->reference} échue depuis {$this->facture->joursDeRetard()} jours",
            default => "Mise en demeure : facture {$this->facture->reference}",
        };

        $entreprise = $this->facture->entreprise;

        // `from` attend une Address, une chaîne ou null — pas un tableau.
        // Passer `[]` pour « pas d'expéditeur » lève une TypeError à la
        // construction de l'enveloppe ; c'est `null` qui laisse Laravel
        // retomber sur l'expéditeur configuré par défaut.
        return new Envelope(
            subject: $objet,
            from: $entreprise->email
                ? new Address($entreprise->email, $entreprise->raison_sociale)
                : null,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'courriels.relance',
            with: [
                'niveauLibelle' => Relance::NIVEAUX[$this->niveau] ?? 'Relance',
            ],
        );
    }
}
