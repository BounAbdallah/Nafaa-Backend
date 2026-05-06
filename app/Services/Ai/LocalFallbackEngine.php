<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Moteur de compréhension locale — utilisé quand Groq est indisponible.
 *
 * Analyse le message en français par patterns regex, extrait les paramètres
 * et appelle directement les outils ERP (DB) sans aucun appel API externe.
 *
 * Intentions supportées :
 *   - list_products, list_materials, list_low_stock
 *   - query_stock
 *   - list_expenses
 *   - list_orders
 *   - list_customers
 *   - list_boms
 *   - create_expense    (montant + description)
 *   - add_stock_movement (quantité + produit + sens)
 */
class LocalFallbackEngine
{
    public function __construct(private readonly ToolRegistry $tools) {}

    // ── Noms de mois FR → numéro ──────────────────────────────────────────────
    private const MONTHS_FR = [
        'janvier' => 1,  'jan' => 1,
        'février' => 2,  'fevrier' => 2, 'fev' => 2,
        'mars'    => 3,  'mar' => 3,
        'avril'   => 4,  'avr' => 4,
        'mai'     => 5,
        'juin'    => 6,  'jun' => 6,
        'juillet' => 7,  'juil' => 7,  'jul' => 7,
        'août'    => 8,  'aout' => 8,  'aou' => 8,
        'septembre' => 9,  'sep' => 9,  'sept' => 9,
        'octobre'   => 10, 'oct' => 10,
        'novembre'  => 11, 'nov' => 11,
        'décembre'  => 12, 'decembre' => 12, 'dec' => 12,
    ];

    /**
     * Point d'entrée principal.
     *
     * @return array{transcript:string, action:string, tool_result:?array, reply:string, _fallback:bool}
     */
    public function handle(string $userText): array
    {
        $normalized = $this->normalize($userText);

        Log::debug('[AI:Fallback] Analyse locale', ['text' => $normalized]);

        $intent = $this->detectIntent($normalized, $userText);

        if (! $intent) {
            return $this->noMatch($userText);
        }

        $tool = $this->tools->get($intent['tool']);
        if (! $tool) {
            return $this->noMatch($userText);
        }

        try {
            $result = $tool->execute($intent['args']);
        } catch (\Throwable $e) {
            Log::error('[AI:Fallback] Tool failed', ['tool' => $intent['tool'], 'err' => $e->getMessage()]);
            return $this->noMatch($userText);
        }

        // Génère une réponse formatée depuis items si disponible (plus riche que message)
        $reply = $this->formatToolReply($intent['tool'], $result);

        return [
            'transcript'  => $userText,
            'action'      => $intent['tool'],
            'tool_result' => $result,
            'reply'       => $reply,
            '_fallback'   => true,
        ];
    }

    // ── Détection d'intention ─────────────────────────────────────────────────

    private function detectIntent(string $text, string $original): ?array
    {
        // ── 1. Stock faible / rupture ─────────────────────────────────────────
        if ($this->matches($text, [
            'stock (faible|bas|critique|alerte|rupture|manque|vide|epuise)',
            'produit(s)? (faible|bas|epuise|rupture)',
            '(rupture|manque) de stock',
            'qu(el|elle|els|elles)? produit(s)? (manque|stock)',
            'en rupture',
        ])) {
            return ['tool' => 'list_low_stock', 'args' => []];
        }

        // ── 2. Stock d'un produit précis ──────────────────────────────────────
        if (preg_match('/(?:stock|reste|quantite|combien[a-z\s]+de?)\s+(?:de\s+|du\s+|d[e\']?\s*)?([a-zàâäéèêëîïôùûüÿæœ][a-zàâäéèêëîïôùûüÿæœ\s]{1,40})/ui', $original, $m)) {
            $product = trim($m[1]);
            if (! $this->isStopWord($product)) {
                return ['tool' => 'query_stock', 'args' => ['product' => $product]];
            }
        }

        // ── 3. Matières premières ─────────────────────────────────────────────
        if ($this->matches($text, [
            'matiere(s)? premiere(s)?',
            'matiere(s)?',
            'ingredient(s)?',
            'composant(s)?',
            'materiaux',
        ])) {
            return ['tool' => 'list_materials', 'args' => []];
        }

        // ── 4. Dépenses (avec extraction de période) ──────────────────────────
        if ($this->matches($text, [
            'depense(s)?', 'charge(s)?', 'frais', 'cout(s)?',
            'combien .*depen', 'ce que .*cout',
        ])) {
            return ['tool' => 'list_expenses', 'args' => $this->parseDateRange($text, 'month')];
        }

        // ── 5. Ventes / Commandes ─────────────────────────────────────────────
        if ($this->matches($text, [
            'vente(s)?', 'vendu', 'commande(s)?', 'chiffre',
            'combien .*(vendu|vend)', 'ca (du|de|aujourd)',
            'recette(s)? (du jour|aujourd)', 'revenu',
        ])) {
            // Défaut : ce mois (plus utile que "aujourd'hui" quand pas de date précisée)
            return ['tool' => 'list_orders', 'args' => $this->parseDateRange($text, 'month')];
        }

        // ── 6. Clients ────────────────────────────────────────────────────────
        if ($this->matches($text, ['client(s)?', 'acheteur(s)?', 'contact(s)?', 'fiche client'])) {
            $search = $this->extractSearchTerm($text, [
                'clients', 'client', 'acheteurs', 'acheteur',
                'contacts', 'contact', 'cherche', 'trouve', 'liste', 'mes',
            ]);
            return ['tool' => 'list_customers', 'args' => array_filter(['search' => $search])];
        }

        // ── 7. Recettes / BOM ─────────────────────────────────────────────────
        if ($this->matches($text, ['recette(s)?', '\bbom\b', 'nomenclature(s)?', 'formule(s)?'])) {
            return ['tool' => 'list_boms', 'args' => []];
        }

        // ── 8. Produits (catalogue général) ──────────────────────────────────
        if ($this->matches($text, [
            'produit(s)?', 'article(s)?', 'catalogue',
            'qu(el|elle|els|elles)? (produit|article)',
        ])) {
            $search = $this->extractSearchTerm($text, [
                'produits', 'produit', 'articles', 'article',
                'liste', 'cherche', 'trouve', 'catalogue', 'mes', 'tous',
            ]);
            return ['tool' => 'list_products', 'args' => array_filter(['search' => $search])];
        }

        // ── 9. Mouvement de stock (AVANT dépenses pour éviter ambiguïté "ajoute N de X") ──
        // "ajoute 50 kg de farine" / "retire 10 unités de savon" / "réception 200 sucre"
        // "ajoute X au stock" / "rentre 30 dans le stock"
        if (preg_match(
            '/(?:ajoute?|réception|entre|reçu?|arrivage|rentré?|retire?|enlève?|soustrai|sortie?)\D{0,10}(\d+)\s*(?:kgs?|gr?|ls?|litres?|pièces?|unités?|sacs?|cartons?|boites?|u|pcs|kg)?\s*(?:de\s+|du\s+|d[e\']?\s*)?([a-zàâäéèêëîïôùûüÿæœ][a-zàâäéèêëîïôùûüÿæœ\s]{1,35})(?:\s+(?:au|dans|en)\s+stock)?/ui',
            $original, $m
        )) {
            $qty     = (int) $m[1];
            $product = trim(preg_replace('/\s+(au|dans|en|le|du|de|d)\s.*$/ui', '', $m[2]));
            $isOut   = $this->matches($this->normalize($original), ['retire?', 'enlève?', 'soustrai', 'sortie', 'consomm']);
            // Ne pas confondre avec une dépense : dépense = montant important sans unité de mesure physique
            // Si le montant est < 1000 et le produit ressemble à un article → mouvement de stock
            if ($qty > 0 && ! $this->isStopWord($product) && mb_strlen($product) >= 2) {
                return ['tool' => 'add_stock_movement', 'args' => [
                    'product'  => $product,
                    'quantity' => $qty,
                    'type'     => $isOut ? 'out' : 'in',
                    'reason'   => $isOut ? 'Sortie manuelle' : 'Entrée manuelle',
                ]];
            }
        }

        // ── 10. Ajout de dépense ──────────────────────────────────────────────
        // "enregistre 5000 pour loyer" / "payé 12000 de transport" / "dépense de 3000 pour eau"
        // Nécessite le mot-clé "pour", "enregistre", "payé", "réglé" — pas juste "ajoute"
        if (preg_match(
            '/(?:enregistr|note?|payé?|régl|dépense\s+de?|spend|débours)\D{0,15}?(\d[\d\s.,]{0,10})\s*(?:fcfa|xof|f(?:cfa)?|€|eur)?\s*(?:pour|d[e\']|en)\s+(.{2,60})/ui',
            $original, $m
        ) || preg_match(
            '/(?:ajoute?)\D{0,10}(?:une?\s+)?(?:dépense|charge|frais)\D{0,10}(\d[\d\s.,]{0,10})\s*(?:fcfa|xof|f(?:cfa)?|€|eur)?\s*(?:pour|d[e\'])\s+(.{2,60})/ui',
            $original, $m
        )) {
            $amount = (float) preg_replace('/[\s,]/', '', str_replace('.', '', $m[1]));
            $desc   = trim($m[2]);
            if ($amount > 0 && $desc !== '') {
                return ['tool' => 'create_expense', 'args' => [
                    'amount'       => $amount,
                    'description'  => $desc,
                    'expense_date' => Carbon::now()->format('Y-m-d'),
                    'category'     => $this->guessCategory($desc),
                ]];
            }
        }

        return null;
    }

    // ── Parsing des dates ─────────────────────────────────────────────────────

    /**
     * Extrait une plage de dates à partir de mots-clés dans le message.
     *
     * @param string $default 'today' | 'week' | 'month'
     * @return array{start_date:string, end_date:string}
     */
    private function parseDateRange(string $text, string $default = 'today'): array
    {
        $today = Carbon::now();
        $fmt   = 'Y-m-d';

        // Aujourd'hui
        if ($this->matches($text, ["aujourd'?hui", '\bce jour\b', '\bdu jour\b'])) {
            return ['start_date' => $today->format($fmt), 'end_date' => $today->format($fmt)];
        }

        // Hier
        if ($this->matches($text, ['\bhier\b'])) {
            $d = $today->copy()->subDay();
            return ['start_date' => $d->format($fmt), 'end_date' => $d->format($fmt)];
        }

        // Cette semaine
        if ($this->matches($text, ['cette semaine', 'semaine (en cours|actuelle|courante)'])) {
            return [
                'start_date' => $today->copy()->startOfWeek()->format($fmt),
                'end_date'   => $today->format($fmt),
            ];
        }

        // Semaine dernière
        if ($this->matches($text, ['semaine (derniere|passee|precedente)'])) {
            $start = $today->copy()->subWeek()->startOfWeek();
            return [
                'start_date' => $start->format($fmt),
                'end_date'   => $start->copy()->endOfWeek()->format($fmt),
            ];
        }

        // Ce mois
        if ($this->matches($text, ['ce mois', 'mois en cours', 'mois actuel', 'mois-ci'])) {
            return [
                'start_date' => $today->copy()->startOfMonth()->format($fmt),
                'end_date'   => $today->format($fmt),
            ];
        }

        // Mois dernier
        if ($this->matches($text, ['mois (dernier|passe|precedent)'])) {
            $start = $today->copy()->subMonth()->startOfMonth();
            return [
                'start_date' => $start->format($fmt),
                'end_date'   => $start->copy()->endOfMonth()->format($fmt),
            ];
        }

        // Mois par nom (ex: "janvier", "en mars", "du mois de mai")
        foreach (self::MONTHS_FR as $name => $num) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/ui', $text)) {
                $year  = $today->year;
                $start = Carbon::create($year, $num, 1)->startOfMonth();
                return [
                    'start_date' => $start->format($fmt),
                    'end_date'   => $start->copy()->endOfMonth()->format($fmt),
                ];
            }
        }

        // Année spécifique (ex : "en 2026", "ventes 2025")
        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            $year = (int) $m[1];
            $end  = $year === (int) $today->year
                ? $today->format($fmt)                      // année en cours → jusqu'à aujourd'hui
                : Carbon::create($year, 12, 31)->format($fmt);
            return [
                'start_date' => "{$year}-01-01",
                'end_date'   => $end,
            ];
        }

        // Année en cours
        if ($this->matches($text, ["cette annee", "l'annee", 'annee en cours'])) {
            return [
                'start_date' => $today->copy()->startOfYear()->format($fmt),
                'end_date'   => $today->format($fmt),
            ];
        }

        // Défaut selon le contexte
        return match ($default) {
            'today' => ['start_date' => $today->format($fmt), 'end_date' => $today->format($fmt)],
            'week'  => [
                'start_date' => $today->copy()->startOfWeek()->format($fmt),
                'end_date'   => $today->format($fmt),
            ],
            default => [ // 'month'
                'start_date' => $today->copy()->startOfMonth()->format($fmt),
                'end_date'   => $today->format($fmt),
            ],
        };
    }

    // ── Deviner la catégorie d'une dépense ─────────────────────────────────────

    private function guessCategory(string $desc): string
    {
        $d = $this->normalize($desc); // normalise + supprime accents
        // Les clés doivent correspondre exactement à Expense::categories()
        $map = [
            'loyer|bail|location|immobilier|bureau|local'                      => 'loyer',
            'transport|carburant|essence|taxi|moto|livraison|uber|deplacement' => 'transport',
            'eau'                                                               => 'eau',
            'electricite|courant|groupe'                                        => 'electricite',
            'telephone|mobile|internet|wifi|abonnement|communication'          => 'communication',
            'salaire|employe|personnel|paie|prime|remuneration'                => 'salaires',
            'publicite|marketing|promotion|pub|reseaux|facebook|instagram'     => 'marketing',
            'fourniture|papier|stylo|materiel|equipement'                      => 'fournitures',
            'maintenance|reparation|entretien|panne|refection'                 => 'maintenance',
            'taxe|impot|tva|droit|douane|fiscal'                               => 'taxes',
        ];
        foreach ($map as $pattern => $cat) {
            if (preg_match('/(' . $pattern . ')/ui', $d)) {
                return $cat;
            }
        }
        return 'autre';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Normalise : minuscules + suppression des accents pour matcher même sans accent */
    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Translitération accent → ASCII pour matcher "depenses" = "dépenses"
        $map = [
            'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'î'=>'i','ï'=>'i','ì'=>'i','í'=>'i',
            'ô'=>'o','ö'=>'o','ò'=>'o','ó'=>'o','õ'=>'o',
            'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
            'ç'=>'c','ñ'=>'n',
            'æ'=>'ae','œ'=>'oe',
        ];
        return trim(strtr($text, $map));
    }

    /** Vérifie si le texte matche l'un des patterns (regex PCRE) */
    private function matches(string $text, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (preg_match('/' . $p . '/ui', $text)) {
                return true;
            }
        }
        return false;
    }

    /** Stop-words qui ne sont pas des noms de produits */
    private function isStopWord(string $word): bool
    {
        $stops = ['le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'en', 'au', 'aux', 'il', 'elle',
                  'produit', 'article', 'stock', 'quantite', 'combien', 'reste', 'materiau', 'materiel'];
        return in_array(mb_strtolower(trim($word)), $stops, true);
    }

    /**
     * Extrait un terme de recherche en retirant les mots-clés de commande.
     */
    private function extractSearchTerm(string $text, array $keywords): ?string
    {
        $stopWords = ['liste', 'lister', 'mes', 'les', 'tous', 'toutes', 'trouve', 'trouver',
                      'cherche', 'chercher', 'donne', 'affiche', 'voir', 'montre', 'montrer',
                      'de', 'du', 'des', 'un', 'une', 'le', 'la', 'moi'];
        $cleaned = $text;
        foreach (array_unique(array_merge($keywords, $stopWords)) as $kw) {
            $cleaned = preg_replace('/\b' . preg_quote($kw, '/') . '\b/ui', ' ', $cleaned) ?? $cleaned;
        }
        $cleaned = trim(preg_replace('/\s{2,}/', ' ', $cleaned) ?? '');
        // Résultat trop court ou vide → pas de filtre de recherche
        return mb_strlen($cleaned) >= 2 ? $cleaned : null;
    }

    /**
     * Génère une réponse lisible depuis le résultat brut du tool.
     * Pour les listes (items), affiche tous les éléments chargés plutôt que
     * les 5 du résumé `message`.
     */
    private function formatToolReply(string $tool, array $result): string
    {
        // Erreur
        if (($result['ok'] ?? true) === false) {
            return 'Désolé : ' . ($result['error'] ?? 'Erreur interne.');
        }

        $items = $result['items'] ?? null;
        $total = $result['total'] ?? null;

        // ── Matières premières ────────────────────────────────────────────────
        if ($tool === 'list_materials' && is_array($items) && count($items) > 0) {
            $count = count($items);
            $intro = $total && $total > $count
                ? "Voici {$count} de tes {$total} matières premières :"
                : "Voici tes {$count} matière(s) première(s) :";
            $lines = array_map(
                fn($m) => sprintf('• **%s** (%g %s)%s',
                    $m['name'],
                    $m['stock'],
                    $m['unit'] ?? 'u',
                    isset($m['is_low']) && $m['is_low'] ? ' ⚠️ stock bas' : ''
                ),
                $items
            );
            $suffix = $total && $total > $count
                ? "\n\n_({$total} au total — dis \"liste mes matières\" pour en voir plus.)_"
                : '';
            return $intro . "\n" . implode("\n", $lines) . $suffix;
        }

        // ── Produits ──────────────────────────────────────────────────────────
        if ($tool === 'list_products' && is_array($items) && count($items) > 0) {
            $count = count($items);
            $intro = $total && $total > $count
                ? "Voici {$count} de tes {$total} produits :"
                : "Voici tes {$count} produit(s) :";
            $lines = array_map(
                fn($p) => sprintf('• **%s** — prix : %g FCFA, stock : %g %s%s',
                    $p['name'],
                    $p['selling_price'] ?? 0,
                    $p['stock'] ?? 0,
                    $p['unit'] ?? 'u',
                    isset($p['is_low']) && $p['is_low'] ? ' ⚠️' : ''
                ),
                $items
            );
            $suffix = $total && $total > $count
                ? "\n\n_({$total} au total)_"
                : '';
            return $intro . "\n" . implode("\n", $lines) . $suffix;
        }

        // ── Stock faible ──────────────────────────────────────────────────────
        if ($tool === 'list_low_stock' && is_array($items)) {
            if (count($items) === 0) {
                return "Tous tes produits ont un stock suffisant. ✅";
            }
            $lines = array_map(
                fn($p) => sprintf('• **%s** — %g %s (seuil : %g)',
                    $p['name'], $p['stock'], $p['unit'] ?? 'u', $p['alert_level'] ?? 0
                ),
                $items
            );
            return count($items) . " produit(s) en stock bas :\n" . implode("\n", $lines);
        }

        // ── Dépenses ──────────────────────────────────────────────────────────
        if ($tool === 'list_expenses' && is_array($items)) {
            if (count($items) === 0) {
                return $result['message'] ?? 'Aucune dépense trouvée sur la période.';
            }
            $total_amount = $result['total_amount'] ?? array_sum(array_column($items, 'amount'));
            $lines = array_map(
                fn($e) => sprintf('• **%s** — %s FCFA (%s)',
                    $e['description'] ?? '—',
                    number_format($e['amount'] ?? 0, 0, ',', ' '),
                    $e['expense_date'] ?? ''
                ),
                $items
            );
            $header = count($items) . " dépense(s) — Total : " . number_format($total_amount, 0, ',', ' ') . " FCFA :";
            return $header . "\n" . implode("\n", $lines);
        }

        // ── Commandes ─────────────────────────────────────────────────────────
        if ($tool === 'list_orders' && is_array($items)) {
            if (count($items) === 0) {
                return $result['message'] ?? 'Aucune vente trouvée sur la période.';
            }
            $total_amount = $result['total_revenue'] ?? $result['total_amount'] ?? 0;
            $lines = array_map(
                fn($o) => sprintf('• **%s** — %s FCFA (%s) [%s]',
                    $o['reference'] ?? $o['id'] ?? '—',
                    number_format($o['total'] ?? 0, 0, ',', ' '),
                    $o['customer_name'] ?? 'Client de passage',
                    $o['created_at'] ?? ''
                ),
                $items
            );
            $header = count($items) . " vente(s)";
            if ($total_amount > 0) {
                $header .= " — Total : " . number_format($total_amount, 0, ',', ' ') . " FCFA";
            }
            return $header . " :\n" . implode("\n", $lines);
        }

        // ── Clients ───────────────────────────────────────────────────────────
        if ($tool === 'list_customers' && is_array($items) && count($items) > 0) {
            $lines = array_map(
                fn($c) => sprintf('• **%s**%s%s',
                    $c['name'] ?? '—',
                    ! empty($c['phone']) ? ' — ' . $c['phone'] : '',
                    ! empty($c['email']) ? ' — ' . $c['email'] : ''
                ),
                $items
            );
            return count($items) . " client(s) :\n" . implode("\n", $lines);
        }

        // ── Défaut : utiliser le message du tool ──────────────────────────────
        return $result['message'] ?? 'Action effectuée.';
    }

    /** Réponse quand aucune intention n'est détectée */
    private function noMatch(string $userText): array
    {
        $tips = implode("\n", [
            "• \"liste mes produits\" — voir le catalogue",
            "• \"stock de [produit]\" — vérifier un stock",
            "• \"produits en rupture\" — alertes stock faible",
            "• \"mes dépenses de ce mois\" — résumé financier",
            "• \"ventes d'aujourd'hui\" — chiffre du jour",
            "• \"mes clients\" — liste des clients",
            "• \"ajoute 50 de [produit] au stock\" — mouvement de stock",
            "• \"enregistre 5000 pour loyer\" — nouvelle dépense",
        ]);

        return [
            'transcript'  => $userText,
            'action'      => 'none',
            'tool_result' => null,
            'reply'       => "Je suis en mode hors-ligne (IA temporairement indisponible).\n\nVoici ce que je peux faire directement :\n\n{$tips}",
            '_fallback'   => true,
        ];
    }
}
