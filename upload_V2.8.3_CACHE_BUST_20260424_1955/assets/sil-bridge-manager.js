(function($) {
    "use strict";
    jQuery(document).ready(function ($) {
    const config = $.extend({
        ajaxurl: (typeof ajaxurl !== 'undefined' ? ajaxurl : ''),
        nonce: '',
        admin_nonce: ''
    }, window.silSharedData || {}, window.silData || {});

    // Fallback for admin_nonce if missing from silSharedData
    if (!config.admin_nonce && config.nonce) {
        config.admin_nonce = config.nonce;
    }

    // Fallback for silNotify/silToast (admin.js may not be loaded on Cartographie page)
    if (typeof window.silNotify !== 'function') {
        window.silNotify = function(msg, type) {
            console.warn('SIL Notify (' + (type || 'info') + '):', msg);
            alert((type === 'error' ? '❌ ' : '✅ ') + msg);
        };
    }
    if (typeof window.silToast !== 'function') {
        window.silToast = window.silNotify;
    }

    // Fallback for escHtml/escAttr (defined in admin.js, missing on Cartographie page)
    if (typeof window.escHtml !== 'function') {
        window.escHtml = function(text) {
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text ? String(text).replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
        };
    }
    if (typeof window.escAttr !== 'function') {
        window.escAttr = window.escHtml;
    }

    window.SIL_Bridge = {
        /**
         * Generates a bridge prompt and opens the modal.
         */
        generate: function (sourceId, targetId, anchorText, $btn, note = '', targetTitleArg = '') {
            const targetTitle = targetTitleArg || ($btn ? $btn.data('title') : '') || 'Article à booster';

            if (!sourceId) {
                // BMAD Booster Mode: We have a target, we need to find a source.
                const data = {
                    is_booster: true,
                    target_id: targetId,
                    target_title: targetTitle,
                    source_id: null,
                    source_title: 'À définir (Mégaphone)',
                    original: '',
                    prompt: '',
                    anchor: anchorText
                };
                this.openModal(data);
                return;
            }

            const originalText = $btn.text();
            $btn.prop('disabled', true).text('⏳ Calcul sémantique...');

            $.post(config.ajaxurl, {
                action: 'sil_generate_bridge_prompt',
                nonce: config.admin_nonce, // Now guaranteed to have a fallback
                source_id: sourceId,
                target_id: targetId,
                anchor_text: anchorText,
                note: note
            }, function (response) {
                $btn.prop('disabled', false).text(originalText);
                if (response.success) {
                    window.SIL_Bridge.openModal(response.data);
                } else {
                    window.silNotify(response.data || 'Erreur lors de la génération du prompt.', 'error');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text(originalText);
                window.silNotify('Erreur réseau lors de la génération du prompt.', 'error');
            });
        },

        /**
         * Opens the AI Bridge Modal.
         */
        openModal: function (data) {
            // Remove existing modal if any
            $('#sil-ai-modal-overlay').remove();

            const isBooster = !!data.is_booster;
            
            // Helper to strip images and heavy tags from the prompt context
            const sanitize = (text) => {
                if (!text) return '';
                return text.replace(/<img[^>]*>/gi, '[IMAGE]')
                           .replace(/<iframe[^>]*>.*?<\/iframe>/gi, '[EMBED]')
                           .replace(/<[^>]+>/g, (tag) => {
                               // Keep common formatting AND LINKS but strip everything else for the prompt
                               return /<\/?(b|strong|i|em|p|br|a)>/i.test(tag) ? tag : '';
                           });
            };

            const promptText = sanitize(data.prompt || '');
            const sourceTitle = data.source_title || 'Source';
            const targetTitle = data.target_title || 'Cible';
            
            const modalTitle = isBooster ? '🚀 Booster l\'article' : '🤖 Workflow IA : Pont Sémantique Direct';
            const sourceLabel = isBooster ? '📢 Source (Mégaphone)' : '📄 Source';
            const targetLabel = isBooster ? '🎯 Cible (À booster)' : '🎯 Cible';

            const modalHtml = `
            <div id="sil-ai-modal-overlay" class="${isBooster ? 'sil-mode-booster' : ''}">
                <div class="sil-modal-container" data-anchor="${data.anchor || ''}">
                    <div class="sil-modal-header">
                        <h3 id="sil-modal-title">
                            <span class="sil-v17-modal-header-icon">
                                ${isBooster ? '🚀' : '🤖'}
                            </span>
                            ${modalTitle}
                        </h3>
                        <button id="sil-ai-modal-close" class="sil-modal-close-btn">&times;</button>
                    </div>
                    
                    <div class="sil-v17-modal-subheader">
                        <div class="sil-v17-path-item sil-text-right">
                            <div class="sil-v17-path-label">${sourceLabel}</div>
                            <div id="sil-modal-source-display" class="sil-v17-path-value">${sourceTitle}</div>
                        </div>
                        <div class="sil-v17-path-arrow">${isBooster ? '←' : '→'}</div>
                        <div class="sil-v17-path-item">
                            <div class="sil-v17-path-label">${targetLabel}</div>
                            <div id="sil-modal-target-display" class="sil-v17-path-value sil-text-success">${targetTitle}</div>
                        </div>
                    </div>

                    ${isBooster && !data.source_id ? `
                    <div class="sil-v17-search-step">
                         <label class="sil-v17-search-label">Étape 1 : Chercher un article SOURCE (Mégaphone)</label>
                         <div class="sil-v17-search-input-group" style="position:relative;">
                            <input type="text" id="sil-booster-search-input" placeholder="Rechercher une page d'autorité pour envoyer du jus...">
                            <button id="sil-booster-search-btn" class="button">🔍 Chercher</button>
                            <div id="sil-booster-search-results" class="sil-v17-results-container" style="display:none;"></div>
                         </div>
                    </div>
                    ` : ''}

                    <div class="sil-modal-body ${isBooster && !data.source_id ? 'sil-disabled-overlay' : ''}">
                        <div class="sil-modal-column alt-bg">
                            <label class="sil-modal-label">1. Copiez le Prompt (Mode Naturel)</label>
                            <p class="sil-modal-desc">L'IA va transformer un mot existant en lien (Ancre Floue) ou adapter la phrase.</p>
                            
                            <div class="sil-v17-p-target-zone" style="margin-bottom:15px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                                <div style="font-size:10px; text-transform:uppercase; color:#64748b; font-weight:700; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                                    <span>📍 Emplacement cible (Paragraphe ${(data.p_index + 1) || '?'}/${data.total_p || '?'})</span>
                                    <button id="sil-change-p-location" class="sil-btn-mini" 
                                            data-source="${data.source_id}" 
                                            data-target="${data.target_id}" 
                                            data-anchor="${window.escAttr(data.anchor_text || data.anchor || '')}" 
                                            data-pindex="${data.p_index}" 
                                            data-total="${data.total_p}" 
                                            style="background:#fff; border:1px solid #cbd5e1; border-radius:4px; padding:2px 6px; cursor:pointer; font-size:10px;">🔄 Changer d'emplacement</button>
                                </div>
                                <div class="sil-p-preview" style="font-size:12px; color:#1e293b; font-style:italic; max-height:80px; overflow-y:auto; border-left:3px solid var(--sil-primary); padding-left:10px;">${data.selected_html || '(Indéfini)'}</div>
                            </div>

                            <div id="sil-v10-micro-suggestion" style="display:none; margin-bottom:15px; padding:12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px;">
                                <div style="font-size:10px; text-transform:uppercase; color:#166534; font-weight:700; margin-bottom:8px;">🎯 Paragraphe Idéal (Détecté par IA)</div>
                                <div id="sil-micro-suggestion-content" style="font-size:11px; color:#14532d; line-height:1.4;">Chargement de la zone idéale...</div>
                            </div>

                            <div style="position:relative; flex: 1; display: flex; flex-direction: column;">
                                <textarea id="sil-prompt-text" class="sil-modal-textarea" readonly>${promptText}</textarea>
                                <button class="button button-secondary" id="sil-copy-prompt-btn" style="margin-top:12px; width:100%; height: 44px; font-weight: 700; border: 2px solid var(--sil-primary); color: var(--sil-primary);">
                                    📋 Copier le prompt pour Gemini/ChatGPT
                                </button>
                            </div>
                        </div>
                        <div class="sil-modal-column">
                            <label class="sil-modal-label">2. Collez le résultat HTML</label>
                            <p class="sil-modal-desc">Collez ici le paragraphe généré contenant le lien <code>&lt;a href="..."&gt;</code>.</p>
                            <textarea id="sil-ai-modal-editor" class="sil-modal-textarea editor" placeholder="Collez ici le paragraphe généré..."></textarea>
                            
                            <div style="margin-top: 15px; padding: 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 11px; color: #92400e;">
                                💡 <strong>Note :</strong> L'insertion est "chirurgicale" et respecte vos blocs Gutenberg.
                            </div>
                        </div>
                    </div>
                    <div class="sil-modal-footer">
                        <button id="sil-ai-modal-cancel" class="button">Annuler</button>
                        <button id="sil-ai-modal-confirm" class="button button-primary" 
                                style="background: var(--sil-success); border-color: #059669; box-shadow: 0 4px 0 #059669;"
                                data-source="${data.source_id || ''}" 
                                data-target="${data.target_id}" 
                                data-original="${data.original || ''}"
                                data-pindex="${data.p_index !== undefined ? data.p_index : -1}"
                                ${!data.source_id ? 'disabled' : ''}>🚀 Sauvegarder l'insertion</button>
                    </div>
                </div>
            </div>`;
            $('body').append(modalHtml);

            // AUTO-FETCH Micro-Suggestion if source and target are present
            if (data.source_id && data.target_id) {
                this.fetchMicroSuggestion(data.source_id, data.target_id);
            }
        },

        /**
         * Fetch the best paragraph via V10 Micro-Embedding.
         */
        fetchMicroSuggestion: function(sourceId, targetId) {
            const $zone = $('#sil-v10-micro-suggestion');
            const $content = $('#sil-micro-suggestion-content');
            
            $zone.show();
            $content.html('<span class="sil-spinner-mini"></span> Analyse du meilleur emplacement...');

            $.post(config.ajaxurl, {
                action: 'sil_get_best_paragraph',
                nonce: config.admin_nonce,
                source_id: sourceId,
                target_id: targetId
            }, function(response) {
                if (response.success) {
                    const currentText = $('.sil-p-preview').text().trim();
                    const suggestedText = response.data.content.trim();
                    // Normalisation simple pour comparaison
                    const isAlreadySelected = (currentText === suggestedText || currentText.indexOf(suggestedText) !== -1 || suggestedText.indexOf(currentText) !== -1);

                    $content.html(`
                        <div style="margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                            <span>${isAlreadySelected ? '✅ <strong>L\'IA a confirmé l\'emplacement.</strong>' : 'Meilleure pertinence (Score: ' + response.data.score + ') :'}</span>
                            ${!isAlreadySelected ? `<button id="sil-magic-sync" class="sil-btn-magic" data-pindex="${response.data.p_index}" title="Synchroniser avec l'emplacement IA">Sauter au Paragraphe ✨</button>` : ''}
                        </div>
                        ${isAlreadySelected ? '' : '<div style="padding:6px; background:#fff; border:1px solid #dcfce7; border-radius:4px; font-style:italic; font-size:11px;">"' + response.data.content + '"</div>'}
                        <div style="margin-top:8px; font-size:9px; color:#64748b;">💡 Alignez l'emplacement pour un résultat optimal.</div>
                    `);
                } else {
                    $zone.hide();
                    console.warn('SIL V10: Suggestion failed', response.data);
                }
            });
        }

    };

    // --- Bridge Trigger from Cartographie/Graph ---
    $(document).on('click', '.sil-manual-bridge-trigger', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const sourceId = $btn.attr('data-source') || $btn.data('source');
        const targetId = $btn.attr('data-target') || $btn.data('target');
        const targetTitle = $btn.attr('data-title') || $btn.data('title') || 'Article cible';
        let anchorText = $btn.attr('data-anchor') || $btn.data('anchor') || '';

        // Force manual anchor definition via prompt
        const userAnchor = window.prompt("Saisissez l'ancre précise (le texte du lien) pour ce pont sémantique :", anchorText || targetTitle);
        
        if (userAnchor === null) return; // User cancelled
        anchorText = userAnchor || anchorText;

        console.log('SIL DEBUG: Manual Bridge Triggered with Anchor:', { sourceId, targetId, anchorText });
        window.SIL_Bridge.generate(sourceId, targetId, anchorText, $btn, '', targetTitle);
    });

    // --- Global Event Listeners for the Modal ---

    $(document).on('click', '#sil-ai-modal-close, #sil-ai-modal-cancel', function () {
        $('#sil-ai-modal-overlay').remove();
    });

    // Change Location Handler (Cycle paragraphs)
    $(document).on('click', '#sil-change-p-location', function() {
        const $btn = $(this);
        const sourceId = $btn.data('source');
        const targetId = $btn.data('target');
        const anchor = $btn.data('anchor');
        const totalP = parseInt($btn.data('total') || 1);
        let currentIdx = parseInt($btn.data('pindex') || 0);
        
        let nextIdx = currentIdx + 1;
        if (nextIdx >= totalP) {
            nextIdx = 0;
        }

        $btn.text('⌛...').prop('disabled', true);

        // Self-contained AJAX call (no dependency on admin.js)
        $.post(config.ajaxurl, {
            action: 'sil_generate_bridge_prompt',
            nonce: config.admin_nonce || config.nonce,
            source_id: sourceId,
            target_id: targetId,
            anchor_text: anchor,
            p_index: nextIdx
        }, function(response) {
            if (response.success) {
                // Remove old modal and open new one with updated data
                $('#sil-ai-modal-overlay').remove();
                window.SIL_Bridge.openModal(response.data);
            } else {
                $btn.text('🔄 Changer d\'emplacement').prop('disabled', false);
                window.silNotify('Erreur: ' + (response.data || 'Impossible de changer d\'emplacement.'), 'error');
            }
        }).fail(function() {
            $btn.text('🔄 Changer d\'emplacement').prop('disabled', false);
            window.silNotify('Erreur réseau lors du changement d\'emplacement.', 'error');
        });
    });

    // Magic Sync Handler
    $(document).on('click', '#sil-magic-sync', function() {
        const $btn = $(this);
        const nextIdx = $btn.data('pindex');
        const $changeBtn = $('#sil-change-p-location');
        
        const sourceId = $changeBtn.data('source');
        const targetId = $changeBtn.data('target');
        const anchor = $changeBtn.data('anchor');

        $btn.html('⏳ Sync...').prop('disabled', true);

        $.post(config.ajaxurl, {
            action: 'sil_generate_bridge_prompt',
            nonce: config.admin_nonce || config.nonce,
            source_id: sourceId,
            target_id: targetId,
            anchor_text: anchor,
            p_index: nextIdx
        }, function(response) {
            if (response.success) {
                $('#sil-ai-modal-overlay').remove();
                window.SIL_Bridge.openModal(response.data);
                window.silNotify('🎯 Emplacement synchronisé avec l\'IA.');
            } else {
                $btn.html('Sauter au Paragraphe ✨').prop('disabled', false);
                window.silNotify('Erreur: ' + (response.data || 'Impossible de synchroniser.'), 'error');
            }
        });
    });

    // Copy Prompt Handler
    $(document).on('click', '#sil-copy-prompt-btn', function() {
        const $btn = $(this);
        const $textarea = $('#sil-prompt-text');
        $textarea.select();
        document.execCommand('copy');
        
        const originalText = $btn.text();
        $btn.text('✅ Copié !').addClass('button-primary');
        setTimeout(() => {
            $btn.text(originalText).removeClass('button-primary');
        }, 2000);
    });

    // --- Bridge Search (Live Suggestions) ---
    function initBridgeSearch() {
        let searchTimeout;
        
        // Remove existing handlers to avoid duplicates on AJAX refreshes (if any)
        $(document).off('input', '#sil-booster-search-input');
        $(document).off('click', '.sil-booster-result-item');

        $(document).on('input', '#sil-booster-search-input', function() {
            const $input = $(this);
            const query = $input.val();
            const $results = $('#sil-booster-search-results');
            const $btn = $('#sil-booster-search-btn');

            if (query.length < 3) {
                $results.hide().empty();
                return;
            }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                console.log('SIL DEBUG: Initiating hybrid search for:', query);
                $btn.prop('disabled', true).text('⏳...');
                $results.empty().show().append('<div style="padding:16px; text-align:center; font-size:11px; color:#64748b;">🔍 Analyse sémantique & textuelle en cours...</div>');

                $.post(config.ajaxurl, {
                    action: 'sil_search_posts_for_link',
                    nonce: config.nonce, 
                    s: query
                }, function(response) {
                    $btn.prop('disabled', false).text('🔍 Chercher');
                    $results.empty();
                    
                    if (response.success && response.data) {
                        if (response.data.length > 0) {
                            response.data.forEach(function(post) {
                                const megaphoneBadge = post.is_megaphone ? 
                                    `<span class="sil-badge sil-badge-warning" style="margin-left:8px;" title="Top 10% visibilité">📢</span>` : '';
                                
                                const confidenceColor = post.confidence >= 90 ? '#10b981' : (post.confidence >= 80 ? '#3b82f6' : '#64748b');
                                const matchLabel = post.match_type === 'keyword' ? '🎯 Correspondance' : '🧠 Sémantique';

                                $results.append(`
                                    <div class="sil-booster-result-item" data-id="${post.id}" data-title="${post.title}">
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                                            <span style="font-weight:700; color:#0f172a; font-size:13px; line-height:1.2;">${post.title}</span>
                                            <div style="display:flex; gap:4px; align-items:center;">
                                                ${megaphoneBadge}
                                                <span style="font-size:10px; font-weight:800; color:${confidenceColor}; background:rgba(0,0,0,0.03); padding:2px 6px; border-radius:4px;">
                                                    ${post.confidence}%
                                                </span>
                                            </div>
                                        </div>
                                        <div style="display:flex; align-items:center; justify-content:space-between; font-size:10px; color:#64748b;">
                                            <div>
                                                <span style="font-style:italic;">ID: ${post.id}</span>
                                                <span style="background:#f1f5f9; padding:1px 5px; border-radius:3px; font-weight:600; margin-left:8px;">${matchLabel}</span>
                                            </div>
                                            ${post.impressions > 0 ? `<span>${post.impressions.toLocaleString()} imp.</span>` : ''}
                                        </div>
                                    </div>
                                `);
                            });
                        } else {
                            $results.append('<div style="padding:16px; text-align:center; font-size:11px; color:#64748b;">Aucun contenu trouvé. Essayez un autre mot-clé ou attendez l\'IA.</div>');
                        }
                    } else {
                        console.error('SIL ERROR: Invalid AJAX response format', response);
                        $results.append('<div style="padding:16px; text-align:center; font-size:11px; color:#dc2626;">❌ Erreur de données serveur.</div>');
                    }
                }).fail(function(xhr, status, error) {
                    console.error('SIL AJAX FAIL:', status, error, xhr.responseText);
                    $btn.prop('disabled', false).text('🔍 Chercher');
                    $results.empty().append('<div style="padding:16px; text-align:center; font-size:11px; color:#dc2626;">⚠️ Erreur critique : La tuyauterie a un bouchon (500).</div>');
                });
            }, 400);
        });

        // Close results on click outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.sil-v17-search-input-group').length) {
                $('#sil-booster-search-results').hide();
            }
        });

        // Item selection is now handled by a single global listener below to avoid duplication.

        // Initialize searches
        $(document).on('click', '#sil-booster-search-btn', function() {
            $('#sil-booster-search-input').trigger('input');
        });
    }

    initBridgeSearch();

    // Manual Trigger for the button (optional, for explicit search)
    $(document).on('click', '#sil-booster-search-btn', function() {
        $('#sil-booster-search-input').trigger('input');
    });

    // Action Générer SEO (GSC Opportunity) - Modal
    $(document).on('click', '.sil-modal-v17-actions .sil-opp-seo-optimize', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const postId = $btn.data('id');
        const $suggestions = $('#sil-v17-suggestions');

        $btn.prop('disabled', true).css('opacity', '0.5');

        $.post(config.ajaxurl, {
            action: 'sil_v17_expert_action',
            v17_type: 'generate_seo',
            nonce: config.admin_nonce, // EXPERT actions require Admin Nonce
            post_id: postId
        }, function(response) {
            $btn.prop('disabled', false).css('opacity', '1');
            if (response.success) {
                const opp = response.data;
                $suggestions.empty().append(`
                    <div style="width:100%; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px; font-size:12px; color:#1e40af;">
                        <div style="font-weight:700; margin-bottom:4px;">✨ Opportunité GSC détectée</div>
                        <p style="margin:0 0 8px 0;">Le terme <strong>"${opp.query}"</strong> a un gros potentiel (${opp.impressions} imp.) mais un CTR faible. 
                           Suggeston de titre : <em>"${opp.suggested_title}"</em></p>
                        <a href="${config.post_edit_base ? config.post_edit_base : 'post.php'}?post=${postId}&action=edit" target="_blank" 
                           class="button button-small" style="background:#3b82f6; color:#fff; border:none;">🚀 Appliquer au Title</a>
                    </div>
                `);
            } else {
                $suggestions.empty().append('<div style="padding:10px; font-size:11px; color:#b91c1c;">Information: ' + response.data + '</div>');
            }
        });
    });

    // Action Trouver une ancre (Long-Tail) - Modal
    $(document).on('click', '.sil-modal-v17-actions .sil-opp-find-anchor', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const postId = $btn.data('id');
        const $suggestions = $('#sil-v17-suggestions');

        $btn.prop('disabled', true).css('opacity', '0.5');

        $.post(config.ajaxurl, {
            action: 'sil_v17_expert_action',
            v17_type: 'find_anchor',
            nonce: config.admin_nonce, // EXPERT actions require Admin Nonce
            post_id: postId
        }, function(response) {
            $btn.prop('disabled', false).css('opacity', '1');
            if (response.success && response.data.anchors) {
                $suggestions.empty().append('<div style="width:100%; font-size:10px; color:#64748b; margin-bottom:4px; font-weight:700; text-transform:uppercase;">💡 Suggestions Longue Traîne :</div>');
                response.data.anchors.forEach(function(anchor) {
                    $suggestions.append(`
                        <button class="sil-v17-suggestions-tag">
                            ${anchor}
                        </button>
                    `);
                });
            } else {
                $suggestions.empty().append('<div style="padding:10px; font-size:11px; color:#64748b;">Info: ' + (response.data || 'Aucune suggestion longue traîne trouvée.') + '</div>');
            }
        });
    });

    // Tag click handler
    $(document).on('click', '.sil-v17-tag-suggestion', function() {
        const text = $(this).text().trim();
        $('#sil-link-anchor').val(text).addClass('sil-highlight-flash');
        setTimeout(() => $('#sil-link-anchor').removeClass('sil-highlight-flash'), 1000);
    });

    // Selection of Source in Booster Mode
    $(document).on('click', '.sil-booster-result-item', function() {
        const $item = $(this);
        const sourceId = $item.data('id');
        const sourceTitle = $item.data('title');
        const $confirmBtn = $('#sil-ai-modal-confirm');
        const targetId = $confirmBtn.data('target');
        
        // Wand logic: If targetId is provided (Booster Mode), fetch its top query automatically
        if (targetId) {
            $.post(config.ajaxurl, {
                action: 'sil_v17_expert_action',
                v17_type: 'get_wand_anchor',
                nonce: config.admin_nonce, // EXPERT actions require Admin Nonce
                post_id: targetId
            }, function(response) {
                if (response.success && response.data.anchor) {
                    $('#sil-link-anchor').val(response.data.anchor);
                    // Add visual highlight
                    $('#sil-link-anchor').addClass('sil-highlight-flash');
                    setTimeout(() => $('#sil-link-anchor').removeClass('sil-highlight-flash'), 1000);
                }
            });
        }

        // Visual update: Immediate feedback
        const $sourceDisplay = $('#sil-modal-source-display');
        $sourceDisplay.text(sourceTitle).addClass('sil-text-success sil-source-selected');
        
        $('#sil-booster-search-results').hide();
        $('#sil-booster-search-input').val(sourceTitle);
        $('.sil-v17-search-step').fadeOut();
        $('.sil-modal-body').css({'opacity': '1', 'pointer-events': 'all'});
        
        // Update confirmation button: Use both .attr and .data for maximum compatibility
        $confirmBtn.attr('data-source', sourceId).data('source', sourceId).prop('disabled', false);

        // Fetch prompt now that we have both source and target
        const $promptTextarea = $('#sil-prompt-text');
        $promptTextarea.val('⏳ Génération du prompt en cours...');

        $.post(config.ajaxurl, {
            action: 'sil_generate_bridge_prompt',
            nonce: config.admin_nonce, // EXPERT actions require Admin Nonce
            source_id: sourceId,
            target_id: targetId,
            anchor_text: '' 
        }, function(response) {
            if (response.success) {
                $promptTextarea.val(response.data.prompt);
                $confirmBtn.data('original', response.data.original);
                $confirmBtn.attr('data-pindex', response.data.p_index).data('pindex', response.data.p_index);
                // V10: Also fetch micro-suggestion for the booster selection
                window.SIL_Bridge.fetchMicroSuggestion(sourceId, targetId);
            } else {
                $promptTextarea.val('Erreur : ' + (response.data || 'Impossible de générer le prompt.'));
            }
        });
    });

    $(document).on('click', '#sil-ai-modal-confirm', function () {
        const $btnModal = $(this);
        const sourceId = $btnModal.data('source');
        const targetId = $btnModal.data('target');
        const originalText = $btnModal.data('original');
        const finalText = $('#sil-ai-modal-editor').val();

        if (!finalText.trim()) {
            window.silNotify('Veuillez coller le résultat de l\'IA avant de sauvegarder.', 'warning');
            return;
        }

        $btnModal.prop('disabled', true).text('⏳ Enregistrement...');
        
        $.post(config.ajaxurl, {
            action: 'sil_apply_anchor_context',
            nonce: config.admin_nonce, // CONTEXT application requires Admin Nonce
            source_id: sourceId,
            target_id: targetId,
            p_index: $btnModal.data('pindex'),
            original_text: originalText,
            final_text: finalText
        }, function (response) {
            if (response.success) {
                $('#sil-ai-modal-overlay').remove();
                window.silNotify('Pont inséré avec succès !');
                // Trigger global event for custom page refreshes
                $(document).trigger('sil_bridge_applied', [sourceId, targetId]);

                // Phase 4: Mise à jour de l'UI après l'IA
                if (window.location.href.indexOf('page=sil-pilot-center') !== -1 || $('.sil-incubator-table').length > 0) {
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                }
            } else {
                window.silNotify(response.data || 'Erreur lors de l\'enregistrement.', 'error');
                $btnModal.prop('disabled', false).text('Sauvegarder l\'insertion');
            }
        }).fail(function(xhr) {
            $btnModal.prop('disabled', false).text('Sauvegarder l\'insertion');
            const errorMsg = xhr.status === 500 ? "Erreur Serveur (500) : Plantage PHP. Vérifiez les logs." : "Erreur réseau (" + xhr.status + ").";
            window.silNotify(errorMsg, 'error');
        });
    });

    // Compatibility Alias
    window.openBridgeModal = window.SIL_Bridge.openModal;
});
})(jQuery);
