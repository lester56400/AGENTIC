/**
 * Smart Internal Links - Bridge Manager (Shared Logic)
 * Unified Workflow for Semantic Bridges (AI-assisted linking)
 */
jQuery(document).ready(function ($) {
    const config = window.silGraphData || window.silAjax || { ajaxurl: ajaxurl, nonce: '' };

    window.SIL_Bridge = {
        /**
         * Generates a bridge prompt and opens the modal.
         */
        generate: function (sourceId, targetId, anchorText, $btn) {
            const originalText = $btn.text();
            $btn.prop('disabled', true).text('⏳ Calcul sémantique...');

            $.post(config.ajaxurl, {
                action: 'sil_generate_bridge_prompt',
                nonce: config.nonce,
                source_id: sourceId,
                target_id: targetId,
                anchor_text: anchorText
            }, function (response) {
                $btn.prop('disabled', false).text(originalText);
                if (response.success) {
                    window.SIL_Bridge.openModal(response.data);
                } else {
                    alert(response.data || 'Erreur lors de la génération du prompt.');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text(originalText);
                alert('Erreur réseau lors de la génération du prompt.');
            });
        },

        /**
         * Opens the AI Bridge Modal.
         */
        openModal: function (data) {
            // Remove existing modal if any
            $('#sil-ai-modal-overlay').remove();

            const promptText = data.prompt;
            const modalHtml = `
            <div id="sil-ai-modal-overlay">
                <div class="sil-modal-container">
                    <div class="sil-modal-header">
                        <h3>
                            <span style="background:var(--sil-primary, #6366f1);color:#fff;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;">🤖</span>
                            Workflow IA : Pont Sémantique Direct
                        </h3>
                        <button id="sil-ai-modal-close" class="sil-modal-close-btn">&times;</button>
                    </div>
                    <div class="sil-modal-body">
                        <div class="sil-modal-column alt-bg">
                            <label class="sil-modal-label">1. Copiez le Prompt (Mode Naturel)</label>
                            <p class="sil-modal-desc">L'IA va scanner votre paragraphe et transformer un mot existant en lien (Ancre Floue) ou adapter la phrase pour une insertion invisible.</p>
                            <textarea id="sil-prompt-text" class="sil-modal-textarea" readonly>${promptText}</textarea>
                            <button class="button button-secondary" style="margin-top:16px;width:100%;" onclick="const t=document.getElementById('sil-prompt-text');t.select();document.execCommand('copy');this.innerText='✅ Copié !';setTimeout(()=>this.innerText='📋 Copier le prompt',2000);">
                                📋 Copier le prompt
                            </button>
                        </div>
                        <div class="sil-modal-column">
                            <label class="sil-modal-label">2. Collez le résultat HTML</label>
                            <p class="sil-modal-desc">L'IA doit vous retourner un paragraphe contenant le lien <code>&lt;a href="..."&gt;</code>.</p>
                            <textarea id="sil-ai-modal-editor" class="sil-modal-textarea editor" placeholder="Collez ici le paragraphe généré..."></textarea>
                        </div>
                    </div>
                    <div class="sil-modal-footer">
                        <button id="sil-ai-modal-cancel" class="button">Annuler</button>
                        <button id="sil-ai-modal-confirm" class="button button-primary" data-source="${data.source_id}" data-target="${data.target_id}" data-original="${data.original}">🚀 Sauvegarder l'insertion</button>
                    </div>
                </div>
            </div>`;
            $('body').append(modalHtml);
        }
    };

    // --- Global Event Listeners for the Modal ---

    $(document).on('click', '#sil-ai-modal-close, #sil-ai-modal-cancel', function () {
        $('#sil-ai-modal-overlay').remove();
    });

    $(document).on('click', '#sil-ai-modal-confirm', function () {
        const $btnModal = $(this);
        const sourceId = $btnModal.data('source');
        const targetId = $btnModal.data('target');
        const originalText = $btnModal.data('original');
        const finalText = $('#sil-ai-modal-editor').val();

        if (!finalText.trim()) {
            alert('Veuillez coller le résultat de l\'IA avant de sauvegarder.');
            return;
        }

        $btnModal.prop('disabled', true).text('⏳ Enregistrement...');
        
        $.post(config.ajaxurl, {
            action: 'sil_apply_anchor_context',
            nonce: config.nonce,
            source_id: sourceId,
            target_id: targetId,
            original_text: originalText,
            final_text: finalText
        }, function (response) {
            if (response.success) {
                $('#sil-ai-modal-overlay').remove();
                alert('Pont inséré avec succès !');
                // Trigger global event for custom page refreshes
                $(document).trigger('sil_bridge_applied', [sourceId, targetId]);
            } else {
                alert(response.data || 'Erreur lors de l\'enregistrement.');
                $btnModal.prop('disabled', false).text('Sauvegarder l\'insertion');
            }
        }).fail(function() {
            $btnModal.prop('disabled', false).text('Sauvegarder l\'insertion');
            alert('Erreur réseau lors de l\'enregistrement.');
        });
    });

    // Compatibility Alias
    window.openBridgeModal = window.SIL_Bridge.openModal;
});
