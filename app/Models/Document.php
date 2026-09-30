<?php

declare(strict_types=1);

namespace App\Models;

use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Models\Concerns\AppartientAEntreprise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un devis, une facture ou un avoir.
 *
 * Trois familles d'attributs, à ne pas confondre :
 *
 *   - ce que l'utilisateur saisit : client, dates, objet, conditions — donc
 *     `$fillable` ;
 *   - ce que la base calcule : `reference`, colonne GENERATED, et les
 *     montants, tenus par des triggers à partir des lignes et des
 *     encaissements. Absents de `$fillable`, et pour une raison de fond :
 *     les écrire depuis PHP créerait une deuxième vérité ;
 *   - ce que le service d'émission attribue : `numero`, `statut`, `emis_le`.
 *     Réservés à `ServiceEmission`, qui seul sait les poser sans trouer la
 *     série.
 */
class Document extends Model
{
    use AppartientAEntreprise;
    use HasFactory;

    protected $table = 'documents';

    protected $fillable = [
        'client_id', 'type', 'annee',
        'date_emission', 'date_echeance',
        'objet', 'conditions', 'notes_internes',
        'origine_id', 'devise',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeDocument::class,
            'statut' => StatutDocument::class,
            'annee' => 'integer',
            'numero' => 'integer',
            'date_emission' => 'date',
            'date_echeance' => 'date',
            'emis_le' => 'datetime',

            // `decimal:2` rend une chaîne, pas un flottant. C'est voulu :
            // additionner des flottants fait apparaître des centimes qui
            // n'existent pas. Les calculs restent en base, ou passent par
            // bcmath.
            'montant_ht' => 'decimal:2',
            'montant_tva' => 'decimal:2',
            'montant_ttc' => 'decimal:2',
            'montant_paye' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // L'année de la série se déduit de la date du document. La contrainte
        // `documents_annee_coherente` en base refuserait toute autre valeur —
        // autant la calculer correctement ici plutôt que la faire saisir.
        static::creating(function (self $document): void {
            if ($document->annee === null && $document->date_emission !== null) {
                $document->annee = (int) $document->date_emission->format('Y');
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(Ligne::class)->orderBy('position');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class)->orderBy('date_paiement');
    }

    public function relances(): HasMany
    {
        return $this->hasMany(Relance::class)->orderBy('niveau');
    }

    /** Le document dont celui-ci découle : le devis accepté, la facture corrigée. */
    public function origine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origine_id');
    }

    /** Ce qui en découle : la facture issue du devis, les avoirs de la facture. */
    public function derives(): HasMany
    {
        return $this->hasMany(self::class, 'origine_id');
    }

    // ------------------------------------------------------------------
    // Lectures
    // ------------------------------------------------------------------

    public function estBrouillon(): bool
    {
        return $this->numero === null;
    }

    public function estEmis(): bool
    {
        return $this->numero !== null;
    }

    /**
     * Le reste dû, en soustraction décimale exacte. `$ttc - $paye` en PHP
     * repasserait par le flottant et rendrait 0.009999 au lieu de 0 sur
     * certaines factures — soit une facture qui ne se solde jamais.
     */
    public function resteAPayer(): string
    {
        return bcsub((string) $this->montant_ttc, (string) $this->montant_paye, 2);
    }

    public function estSolde(): bool
    {
        return bccomp($this->resteAPayer(), '0', 2) <= 0;
    }

    public function estEnRetard(): bool
    {
        return $this->type === TypeDocument::Facture
            && $this->statut === StatutDocument::Emis
            && $this->date_echeance !== null
            && $this->date_echeance->isPast()
            && ! $this->estSolde();
    }

    public function joursDeRetard(): int
    {
        if (! $this->estEnRetard()) {
            return 0;
        }

        return (int) $this->date_echeance->startOfDay()->diffInDays(now()->startOfDay());
    }

    /** La TVA ventilée par taux — mention obligatoire dès qu'une facture
     *  mélange plusieurs taux, et le cas le plus courant des travaux. */
    public function ventilationTva(): array
    {
        return $this->loadMissing('lignes')->lignes
            ->groupBy(fn (Ligne $ligne) => (string) $ligne->taux_tva)
            ->map(fn ($lignes, $taux) => [
                'taux' => (float) $taux,
                'base' => (string) $lignes->reduce(
                    fn ($somme, Ligne $l) => bcadd((string) $somme, (string) $l->montant_ht, 2),
                    '0'
                ),
                'tva' => (string) $lignes->reduce(
                    fn ($somme, Ligne $l) => bcadd((string) $somme, (string) $l->montant_tva, 2),
                    '0'
                ),
            ])
            ->sortByDesc('taux')
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Filtres réutilisables
    // ------------------------------------------------------------------

    public function scopeDeType(Builder $requete, TypeDocument $type): Builder
    {
        return $requete->where('type', $type->value);
    }

    public function scopeEmis(Builder $requete): Builder
    {
        return $requete->whereNotNull('numero');
    }

    /**
     * Les factures échues et non soldées. C'est exactement le prédicat de
     * l'index partiel `documents_impayes` : PostgreSQL l'utilise, et le
     * balayage du relanceur ne lit que les lignes qui l'intéressent.
     */
    public function scopeEnRetard(Builder $requete, ?int $joursMinimum = null): Builder
    {
        $requete->where('type', TypeDocument::Facture->value)
            ->where('statut', StatutDocument::Emis->value)
            ->whereNotNull('date_echeance')
            ->whereColumn('montant_paye', '<', 'montant_ttc');

        return $joursMinimum === null
            ? $requete->whereDate('date_echeance', '<', now())
            : $requete->whereDate('date_echeance', '<=', now()->subDays($joursMinimum));
    }
}
