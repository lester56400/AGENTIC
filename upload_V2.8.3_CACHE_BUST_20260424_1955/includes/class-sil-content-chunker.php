<?php
/**
 * Smart Internal Links - Content Chunker
 * Gère la segmentation des articles en paragraphes atomiques pour le Micro-Embedding.
 */

if (!defined('ABSPATH')) exit;

class SIL_Content_Chunker {

    /**
     * Extrait les paragraphes propres d'un post donné ou d'un contenu brut.
     * Supporte nativement les blocs Gutenberg et le fallback Classic.
     * @param int|WP_Post|string $input ID du post, objet WP_Post ou contenu HTML brut.
     * @return array Tableau de paragraphes [hash => content]
     */
    public function get_paragraphs($input, $full_data = false) {
        $content = '';

        if (is_numeric($input)) {
            $post = get_post($input);
            $content = $post ? $post->post_content : '';
        } elseif (is_object($input) && isset($input->post_content)) {
            $content = $input->post_content;
        } else {
            $content = (string) $input;
        }

        if (empty($content)) return [];
        $paragraphs = [];

        // 1. Détection des blocs Gutenberg
        if (has_blocks($content)) {
            $blocks = parse_blocks($content);
            foreach ($blocks as $block) {
                if ($block['blockName'] === 'core/paragraph') {
                    $html = $block['innerHTML'];
                    $txt = $this->clean_paragraph($html);
                    if (!empty($txt)) {
                        $hash = md5($txt);
                        $paragraphs[$hash] = $full_data ? ['clean' => $txt, 'raw' => $html] : $txt;
                    }
                }
            }
        } 
        
        // 2. Fallback si aucun bloc paragraph trouvé ou format Classic Editor
        if (empty($paragraphs)) {
            preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $matches);
            foreach ($matches[0] as $idx => $html) {
                $txt = $this->clean_paragraph($html);
                if (!empty($txt)) {
                    $hash = md5($txt);
                    $paragraphs[$hash] = $full_data ? ['clean' => $txt, 'raw' => $html] : $txt;
                }
            }
        }

        // 3. Fallback ultime pour le texte brut (sans balises HTML)
        if (empty($paragraphs)) {
            $txt = $this->clean_paragraph($content);
            if (!empty($txt)) {
                $hash = md5($txt);
                $paragraphs[$hash] = $full_data ? ['clean' => $txt, 'raw' => $content] : $txt;
            }
        }

        return $paragraphs;
    }

    /**
     * Nettoyage profond pour l'embedding : supprime HTML, entités, et normalise les espaces.
     */
    private function clean_paragraph($html) {
        // Supprimer les balises HTML
        $text = wp_strip_all_tags($html);
        
        // Décoder les entités (ex: &rsquo; -> ')
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Supprimer les espaces doubles et sauts de ligne
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
}
