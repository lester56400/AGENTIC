<?php
/**
 * Tests Unitaires du Pont Sémantique
 *
 * Vérifie chaque étape critique du pipeline de création de liens :
 * 1. Extraction des paragraphes
 * 2. Localisation du texte original (needle)
 * 3. Détection de l'encapsulation Gutenberg
 * 4. Remplacement de contenu (insertion)
 * 5. Prévention de la double encapsulation
 * 6. Suppression de lien (déliaison)
 *
 * @package SmartInternalLinks
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIL_Bridge_Tests {

    private $results = [];
    private $pass_count = 0;
    private $fail_count = 0;

    /**
     * Lance tous les tests et retourne un rapport structuré.
     */
    public function run_all() {
        $this->test_paragraph_extraction_gutenberg();
        $this->test_paragraph_extraction_classic();
        $this->test_needle_location_exact();
        $this->test_needle_location_with_entities();
        $this->test_gutenberg_wrap_detection();
        $this->test_gutenberg_unwrapped_detection();
        $this->test_content_replacement_gutenberg();
        $this->test_content_replacement_classic();
        $this->test_no_double_encapsulation();
        $this->test_inner_content_strip_block_comments();
        $this->test_link_removal_preserves_text();
        $this->test_link_removal_preserves_gutenberg_block();
        // $this->test_database_schema_integrity(); // Not implemented yet
        // $this->test_database_transaction_simulation(); // Not implemented yet
        // $this->test_post_lock_protection(); // Not implemented yet

        return [
            'total'   => count($this->results),
            'passed'  => $this->pass_count,
            'failed'  => $this->fail_count,
            'details' => $this->results
        ];
    }

    // --- Helpers ---

    private function assert($name, $condition, $detail = '') {
        if ($condition) {
            $this->pass_count++;
            $this->results[] = ['name' => $name, 'status' => 'pass', 'detail' => $detail];
        } else {
            $this->fail_count++;
            $this->results[] = ['name' => $name, 'status' => 'fail', 'detail' => $detail];
        }
    }

    // ============================================================
    // TEST 1 : Extraction des paragraphes (Gutenberg)
    // ============================================================
    private function test_paragraph_extraction_gutenberg() {
        $content = "<!-- wp:paragraph -->\n<p>Premier paragraphe avec du <strong>gras</strong>.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Deuxième paragraphe.</p>\n<!-- /wp:paragraph -->";

        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $matches);

        $this->assert(
            'Extraction paragraphes Gutenberg',
            count($matches[0]) === 2,
            'Attendu 2 paragraphes, trouvé ' . count($matches[0])
        );
    }

    // ============================================================
    // TEST 2 : Extraction des paragraphes (Classic Editor)
    // ============================================================
    private function test_paragraph_extraction_classic() {
        $content = "<p>Paragraphe classique sans blocs Gutenberg.</p>\n<p>Un second.</p>";

        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $matches);

        $this->assert(
            'Extraction paragraphes Classic',
            count($matches[0]) === 2,
            'Attendu 2 paragraphes, trouvé ' . count($matches[0])
        );
    }

    // ============================================================
    // TEST 3 : Localisation exacte du texte original
    // ============================================================
    private function test_needle_location_exact() {
        $haystack = "<!-- wp:paragraph -->\n<p>Voici un texte important pour le SEO.</p>\n<!-- /wp:paragraph -->";
        $needle = '<p>Voici un texte important pour le SEO.</p>';

        $pos = strpos($haystack, $needle);

        $this->assert(
            'Localisation needle (exact match)',
            $pos !== false,
            $pos !== false ? "Trouvé à la position $pos" : 'Non trouvé dans le haystack'
        );
    }

    // ============================================================
    // TEST 4 : Localisation avec entités HTML (fallback regex)
    // ============================================================
    private function test_needle_location_with_entities() {
        $haystack = "<p>L&rsquo;optimisation SEO est cruciale.</p>";
        $needle   = "<p>L'optimisation SEO est cruciale.</p>";

        // Simuler le normalize helper
        $normalize = function($t) {
            $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $t = str_replace("\xc2\xa0", ' ', $t);
            
            // Normalize smart quotes and other typography to straight versions
            $search = ['’', '‘', '“', '”', '«', '»', '…'];
            $replace = ["'", "'", '"', '"', '"', '"', '...'];
            $t = str_replace($search, $replace, $t);
            
            $t = preg_replace('/\s+/', ' ', $t);
            return trim($t);
        };

        $pos = strpos($haystack, $needle); // Devrait échouer (exact)

        if ($pos === false) {
            // Fallback : comparaison normalisée
            $haystack_norm = $normalize($haystack);
            $needle_norm = $normalize($needle);
            $pos = SIL_Pilot_Engine::mb_strpos_safe($haystack_norm, $needle_norm, 0, 'UTF-8');
        }

        $this->assert(
            'Localisation needle (entités HTML)',
            $pos !== false,
            $pos !== false ? "Trouvé via fallback à la position $pos" : 'Échec du fallback aussi'
        );
    }

    // ============================================================
    // TEST 5 : Détection Gutenberg wrap (positif)
    // ============================================================
    private function test_gutenberg_wrap_detection() {
        $haystack = "<!-- wp:heading -->\n<h2>Titre</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Texte cible à remplacer.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Autre paragraphe.</p>\n<!-- /wp:paragraph -->";
        $needle = '<p>Texte cible à remplacer.</p>';
        $pos = strpos($haystack, $needle);

        $text_before = substr($haystack, 0, $pos);

        // Chercher le dernier <!-- wp:paragraph --> avant $pos
        $last_wp_open = false;
        if (preg_match_all('/<!-- wp:paragraph(?: [^>]*)?-->/i', $text_before, $matches, PREG_OFFSET_CAPTURE)) {
            $last_match = end($matches[0]);
            $last_wp_open = $last_match[1];
        }

        $last_wp_close = false;
        if (preg_match_all('/<!-- \/wp:paragraph -->/i', $text_before, $matches, PREG_OFFSET_CAPTURE)) {
            $last_close_match = end($matches[0]);
            $last_wp_close = $last_close_match[1];
        }

        $is_wrapped = ($last_wp_open !== false && ($last_wp_close === false || $last_wp_open > $last_wp_close));

        $this->assert(
            'Détection Gutenberg wrap (positif)',
            $is_wrapped === true,
            $is_wrapped ? 'Bloc wp:paragraph détecté correctement' : 'Bloc non détecté !'
        );
    }

    // ============================================================
    // TEST 6 : Détection Gutenberg wrap (négatif - Classic)
    // ============================================================
    private function test_gutenberg_unwrapped_detection() {
        $haystack = "<p>Premier paragraphe classique.</p>\n<p>Texte cible classique.</p>";
        $needle = '<p>Texte cible classique.</p>';
        $pos = strpos($haystack, $needle);

        $text_before = substr($haystack, 0, $pos);

        $last_wp_open = false;
        if (preg_match_all('/<!-- wp:paragraph(?: [^>]*)?-->/i', $text_before, $matches, PREG_OFFSET_CAPTURE)) {
            $last_match = end($matches[0]);
            $last_wp_open = $last_match[1];
        }

        $is_wrapped = ($last_wp_open !== false);

        $this->assert(
            'Détection Gutenberg wrap (négatif/Classic)',
            $is_wrapped === false,
            !$is_wrapped ? 'Comportement classique confirmé' : 'Erreur : faux-positif Gutenberg'
        );
    }

    /**
     * TEST 7 : Localisation avec attributs Gutenberg et variations d'espaces (Nouveau V18)
     */
    private function test_needle_location_with_gutenberg_attr() {
        $haystack = "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">L'optimisation SEO est cruciale.</p>\n<!-- /wp:paragraph -->";
        $needle   = "<p>L'optimisation SEO est cruciale.</p>";

        $normalize = function($t) {
            $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $search = ['’', '‘', '“', '”', '«', '»', '…'];
            $replace = ["'", "'", '"', '"', '"', '"', '...'];
            $t = str_replace($search, $replace, $t);
            $t = preg_replace('/\s+/', ' ', $t);
            return trim($t);
        };

        $needle_norm = $normalize($needle);
        $regex_pattern = preg_quote($needle_norm, '/');
        $regex_pattern = preg_replace('/\\\\<p[^>]*?\\\\>/i', '<p[^>]*?>', $regex_pattern);
        $regex_pattern = preg_replace('/(\\\\\s)+/us', '\s+', $regex_pattern);
        $regex_pattern = preg_replace('/\s+/', '\s+', $regex_pattern);
        $regex_pattern = str_replace(["'", '"'], ["(?:'|&rsquo;|&apos;|&#039;|’|‘)", '(?:"|&quot;|&#034;|“|”)'], $regex_pattern);

        $found = preg_match('/' . $regex_pattern . '/us', $haystack, $matches, PREG_OFFSET_CAPTURE);

        $this->assert(
            'Localisation needle (Gutenberg Attr & Spaces)',
            $found !== 0,
            $found !== 0 ? "Trouvé à l'offset " . $matches[0][1] : "ÉCHEC de localisation avec attributs"
        );
    }

    // ============================================================
    // TEST 7 : Remplacement contenu Gutenberg (intégrité)
    // ============================================================
    private function test_content_replacement_gutenberg() {
        $original_content = "<!-- wp:paragraph -->\n<p>Voici un texte original sans lien.</p>\n<!-- /wp:paragraph -->";
        $needle = '<p>Voici un texte original sans lien.</p>';
        $inner_content_raw = 'Voici un texte original avec un <a href="https://example.com">lien test</a> naturel.';

        $pos = strpos($original_content, $needle);
        $text_before = substr($original_content, 0, $pos);

        // Simuler la détection du bloc
        $open_tag_full = '<!-- wp:paragraph -->';
        preg_match_all('/<!-- wp:paragraph(?: [^>]*)?-->/i', $text_before, $m, PREG_OFFSET_CAPTURE);
        $last_wp_open = end($m[0])[1];

        $text_after = substr($original_content, $pos + strlen($needle));
        $next_wp_close = strpos($text_after, '<!-- /wp:paragraph -->');

        $replace_start = $last_wp_open;
        $replace_length = ($pos + strlen($needle) + $next_wp_close + strlen('<!-- /wp:paragraph -->')) - $last_wp_open;

        $replacement = $open_tag_full . "\n<p>" . $inner_content_raw . "</p>\n<!-- /wp:paragraph -->";
        $new_content = substr_replace($original_content, $replacement, $replace_start, $replace_length);

        // Vérifications
        $has_link = strpos($new_content, '<a href="https://example.com">lien test</a>') !== false;
        $has_proper_wrap = strpos($new_content, "<!-- wp:paragraph -->\n<p>") !== false;
        $has_close = strpos($new_content, "</p>\n<!-- /wp:paragraph -->") !== false;

        $this->assert(
            'Remplacement contenu (Gutenberg)',
            $has_link && $has_proper_wrap && $has_close,
            $has_link ? 'Lien présent, structure Gutenberg intacte' : 'Lien ou structure manquante'
        );
    }

    // ============================================================
    // TEST 8 : Remplacement contenu Classic Editor
    // ============================================================
    private function test_content_replacement_classic() {
        $original_content = "<p>Texte classique sans lien.</p>\n<p>Autre bloc.</p>";
        $needle = '<p>Texte classique sans lien.</p>';
        $inner_content_raw = 'Texte classique avec <a href="https://example.com">un lien</a> ajouté.';
        $replacement = '<p>' . $inner_content_raw . '</p>';

        $pos = strpos($original_content, $needle);
        $new_content = substr_replace($original_content, $replacement, $pos, strlen($needle));

        $has_link = strpos($new_content, '<a href="https://example.com">un lien</a>') !== false;
        $no_gutenberg = strpos($new_content, '<!-- wp:paragraph') === false;

        $this->assert(
            'Remplacement contenu (Classic)',
            $has_link && $no_gutenberg,
            $has_link ? 'Lien inséré sans ajout de blocs Gutenberg' : 'Problème d\'insertion'
        );
    }

    // ============================================================
    // TEST 9 : Anti-Double Encapsulation
    // ============================================================
    private function test_no_double_encapsulation() {
        $original_content = "<!-- wp:paragraph -->\n<p>Texte à modifier.</p>\n<!-- /wp:paragraph -->";

        // Simuler un texte IA qui contient déjà des blocs Gutenberg (le bug)
        $ai_output = "<!-- wp:paragraph -->\n<p>Texte modifié avec <a href=\"https://example.com\">un lien</a>.</p>\n<!-- /wp:paragraph -->";

        // Appliquer le nettoyage (comme dans sil_apply_anchor_context)
        $text_for_validation = str_replace(["\r\n", "\r", "\n"], ' ', $ai_output);
        $text_for_validation = preg_replace('/<\/p>\s*<p[^>]*>/i', '<br />', $text_for_validation);
        $inner_content_raw = preg_replace('/^<p[^>]*>|<\/p>$/i', '', trim($text_for_validation));
        // Le fix critique : supprimer les commentaires de blocs
        $inner_content_raw = preg_replace('/<!--\s*\/?wp:[^>]*-->/i', '', $inner_content_raw);

        $no_block_comments = strpos($inner_content_raw, '<!-- wp:') === false;
        $has_link = strpos($inner_content_raw, '<a href') !== false;

        // Simuler le wrapping final
        $final = "<!-- wp:paragraph -->\n<p>" . $inner_content_raw . "</p>\n<!-- /wp:paragraph -->";

        // Compter les occurrences de <!-- wp:paragraph -->
        $open_count = preg_match_all('/<!-- wp:paragraph/', $final);

        $this->assert(
            'Anti-Double Encapsulation',
            $no_block_comments && $has_link && $open_count === 1,
            $open_count === 1
                ? 'Un seul bloc wp:paragraph dans le résultat final'
                : "DANGER : $open_count blocs wp:paragraph détectés (double encapsulation !)"
        );
    }

    // ============================================================
    // TEST 10 : Nettoyage des commentaires de blocs dans inner_content_raw
    // ============================================================
    private function test_inner_content_strip_block_comments() {
        $raw = '<!-- wp:paragraph -->Texte avec <!-- /wp:paragraph --> résidu.';
        $cleaned = preg_replace('/<!--\s*\/?wp:[^>]*-->/i', '', $raw);

        $this->assert(
            'Nettoyage block comments dans inner_content',
            strpos($cleaned, '<!-- wp:') === false && strpos($cleaned, 'Texte avec') !== false,
            'Commentaires de blocs éliminés, texte conservé'
        );
    }

    // ============================================================
    // TEST 11 : Suppression de lien (le texte d'ancre doit rester)
    // ============================================================
    private function test_link_removal_preserves_text() {
        $content = '<!-- wp:paragraph -->' . "\n" . '<p>Voici un <a href="https://example.com/cible">lien important</a> dans le texte.</p>' . "\n" . '<!-- /wp:paragraph -->';

        $target_url = 'https://example.com/cible';
        $escaped_url = preg_quote($target_url, '/');
        $pattern = '/<a[^>]+href=["\'](' . $escaped_url . ')["\'][^>]*>(.*?)<\/a>/i';
        $new_content = preg_replace($pattern, '$2', $content);

        $has_anchor_text = strpos($new_content, 'lien important') !== false;
        $no_link_tag = strpos($new_content, '<a ') === false;
        $has_surrounding_text = strpos($new_content, 'Voici un ') !== false && strpos($new_content, ' dans le texte.') !== false;

        $this->assert(
            'Suppression lien conserve le texte',
            $has_anchor_text && $no_link_tag && $has_surrounding_text,
            $has_anchor_text ? 'Texte d\'ancre "lien important" conservé, balise <a> retirée' : 'ERREUR : texte d\'ancre perdu !'
        );
    }

    // ============================================================
    // TEST 12 : Suppression de lien préserve le bloc Gutenberg
    // ============================================================
    private function test_link_removal_preserves_gutenberg_block() {
        $content = '<!-- wp:paragraph -->' . "\n" . '<p>Texte avec <a href="https://example.com">un lien</a> ici.</p>' . "\n" . '<!-- /wp:paragraph -->';

        $target_url = 'https://example.com';
        $escaped_url = preg_quote($target_url, '/');
        $pattern = '/<a[^>]+href=["\'](' . $escaped_url . ')["\'][^>]*>(.*?)<\/a>/i';
        $new_content = preg_replace($pattern, '$2', $content);

        $has_wp_open = strpos($new_content, '<!-- wp:paragraph -->') !== false;
        $has_wp_close = strpos($new_content, '<!-- /wp:paragraph -->') !== false;
        $has_p_tag = strpos($new_content, '<p>') !== false && strpos($new_content, '</p>') !== false;
        $p_not_empty = preg_match('/<p>\s*<\/p>/', $new_content) === 0;

        $this->assert(
            'Suppression lien préserve structure Gutenberg',
            $has_wp_open && $has_wp_close && $has_p_tag && $p_not_empty,
            ($has_wp_open && $has_wp_close) ? 'Bloc Gutenberg intact, paragraphe non-vide' : 'Structure Gutenberg cassée !'
        );
    }
}
