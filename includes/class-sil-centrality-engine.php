<?php
/**
 * SIL_Centrality_Engine
 * Calcule la centralité sémantique et de performance pour les articles.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIL_Centrality_Engine
{

    /**
     * Calcule le barycentre (vecteur moyen) d'un groupe d'embeddings.
     * @param array $embeddings Tableau d'embeddings.
     * @return array|null Le vecteur moyen ou null si vide.
     */
    public static function calculate_barycenter($embeddings)
    {
        if (empty($embeddings))
            return null;

        $count = count($embeddings);
        $dim = count($embeddings[0]);
        $barycenter = array_fill(0, $dim, 0.0);

        foreach ($embeddings as $embedding) {
            if (!is_array($embedding))
                continue;
            foreach ($embedding as $i => $val) {
                if (isset($barycenter[$i])) {
                    $barycenter[$i] += (float) $val;
                }
            }
        }

        foreach ($barycenter as $i => $val) {
            $barycenter[$i] /= $count;
        }

        return $barycenter;
    }

    /**
     * Calcule le score de représentativité (similarité cosinus).
     * @param array $post_embedding L'embedding de l'article.
     * @param array $barycenter Le barycentre du cluster.
     * @return float Score entre 0 et 1.
     */
    public static function get_representativeness_score($post_embedding, $barycenter)
    {
        if (empty($post_embedding) || empty($barycenter))
            return 0.5;

        $dot_product = 0;
        $norm_a = 0;
        $norm_b = 0;

        foreach ($post_embedding as $i => $val) {
            if (isset($barycenter[$i])) {
                $dot_product += $val * $barycenter[$i];
                $norm_a += $val * $val;
                $norm_b += $barycenter[$i] * $barycenter[$i];
            }
        }

        $norm = sqrt($norm_a) * sqrt($norm_b);
        return ($norm == 0) ? 0.5 : ($dot_product / $norm);
    }

    /**
     * Calcule un score de puissance GSC (Impressions / Position).
     * @param int $impressions
     * @param float $position
     * @return float Score normalisé (0.0 à 1.0).
     */
    public static function get_gsc_power_score($impressions, $position)
    {
        if ($impressions <= 0)
            return 0;
        $pos = max(1, (float) $position);
        // Score de puissance : pondéré par l'inverse de la racine de la position
        $raw_power = $impressions / sqrt($pos);
        return (float) min(1.0, log10($raw_power + 1) / 5.0);
    }

    /**
     * Combine les composantes pour le score final (0-100).
     * 50% Sémantique, 30% GSC, 20% Connectivité.
     */
    public static function compute_final_score($semantic_score, $gsc_score, $connectivity_score)
    {
        $final = ($semantic_score * 0.5) + ($gsc_score * 0.3) + ($connectivity_score * 0.2);
        return (float) round($final * 100, 2);
    }

    /**
     * Retourne les N meilleures recommandations sémantiques.
     * @param array $source_embedding L'embedding de référence.
     * @param array $candidates Tableau [post_id => embedding].
     * @param int $limit Nombre de résultats souhaités.
     * @return array [post_id => score] trié par pertinence.
     */
    public static function get_top_recommendations($source_embedding, $candidates, $limit = 5)
    {
        if (empty($source_embedding) || empty($candidates)) {
            return [];
        }

        $scores = [];
        foreach ($candidates as $post_id => $embedding) {
            // Sécurité : Vérifier que l'embedding candidat est valide
            if (!is_array($embedding) || count($embedding) !== count($source_embedding)) {
                continue;
            }
            $scores[$post_id] = (float) self::get_representativeness_score($source_embedding, $embedding);
        }

        arsort($scores);
        return array_slice($scores, 0, $limit, true);
    }

    /**
     * TEST DE VÉRIFICATION BMAD 1
     * Validation de la logique de centralité pour l'audit.
     */
    public static function test_centrality_logic() {
        try {
            $v_source = array_fill(0, 1536, 0.0); $v_source[0] = 1.0;
            $v_candi1 = array_fill(0, 1536, 0.0); $v_candi1[0] = 0.9; $v_candi1[1] = 0.1;
            $v_candi2 = array_fill(0, 1536, 0.0); $v_candi2[2] = 1.0;

            $sim = self::get_representativeness_score($v_source, $v_candi1);
            $recos = self::get_top_recommendations($v_source, [101 => $v_candi1, 102 => $v_candi2], 1);
            $winner = key($recos);

            if ($winner === 101) {
                return "SUCCESS: Logic validated (Winner: 101)";
            }
            return "FAILURE: Logic check failed (Winner: " . $winner . ")";
        } catch (Exception $e) { return "ERROR: " . $e->getMessage(); }
    }
}