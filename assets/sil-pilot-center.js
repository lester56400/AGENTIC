/* 
   Smart Internal Links - Pilot Center JS
   v2.5.3 - Atomic Diagnostics & Safe Boot
*/

jQuery(document).ready(function($) {
    console.log("SIL Pilot v2.5.3 Init 💎");

    // Global Safety Check
    const isDataValid = () => typeof silSharedData !== 'undefined' && silSharedData.ajaxurl && silSharedData.nonce;

    if (!isDataValid()) {
        console.error("SIL Pilot Critical: Metadata missing (silSharedData).", typeof silSharedData !== 'undefined' ? silSharedData : 'UNDEFINED');
        $('.action-list').html('<p class="empty-state">❌ Erreur de configuration (Metadata).</p>');
        return;
    }

    // Custom Toast Notification System
    function silToast(msg, type = 'success') {
        let toast = $('<div class="sil-toast ' + type + '">' + msg + '</div>');
        $('body').append(toast);
        setTimeout(() => toast.addClass('show'), 10);
        setTimeout(() => {
            toast.removeClass('show');
            setTimeout(() => toast.remove(), 400);
        }, 3000);
    }

    // --- Core Logic ---
    function init() {
        try {
            initAnimations();
            loadPilotActions();
            // Load journal only if tab active, else deferred
        } catch (e) {
            console.error("SIL Pilot Boot Error:", e);
        }
    }

    function initAnimations() {
        $('.glass-card').each(function(index) {
            $(this).css({
                'opacity': '0',
                'transform': 'translateY(20px)',
                'transition-delay': (index * 0.1) + 's'
            });
            setTimeout(() => {
                $(this).css({ 'opacity': '1', 'transform': 'translateY(0)' });
            }, 100);
        });
    }

    function loadPilotActions() {
        const list = $('#sil-orphan-list, #sil-booster-list');
        // Reset state
        $('#sil-orphan-list').html('<p class="empty-state">Recherche d\'orphelins...</p>');
        $('#sil-booster-list').html('<p class="empty-state">Analyse des opportunités...</p>');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_pilotage_actions',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success) {
                renderOrphans(response.data.orphans || []);
                renderBoosters(response.data.boosters || []);
            } else {
                list.html('<p class="empty-state">❌ Erreur Serveur: ' + (response.data || 'Inconnu') + '</p>');
            }
        }).fail(function(xhr) {
            list.html('<p class="empty-state">❌ Erreur Réseau (' + xhr.status + ').</p>');
        });
    }

    function renderOrphans(orphans) {
        const target = $('#sil-orphan-list');
        if (orphans.length === 0) {
            target.html('<p class="empty-state">✅ Aucun orphelin critique.</p>');
            return;
        }
        let html = '<ul class="pilot-action-items">';
        orphans.forEach(o => {
            html += `<li>
                <span class="item-title">${o.title}</span>
                <span class="item-meta">${o.impressions} imps</span>
                <button class="mini-adopt sil-pilot-bridge-trigger" data-source-id="${o.id}" data-source-title="${o.title.replace(/"/g,'&quot;')}">Adopter 🚀</button>
            </li>`;
        });
        target.html(html + '</ul>');
    }

    function renderBoosters(boosters) {
        const target = $('#sil-booster-list');
        if (boosters.length === 0) {
            target.html('<p class="empty-state">Tout est dans le Top 5 !</p>');
            return;
        }
        let html = '<ul class="pilot-action-items">';
        boosters.forEach(b => {
            html += `<li>
                <span class="item-title">"${b.kw}"</span>
                <span class="item-meta">Pos ${b.pos} | ${b.impressions} imps</span>
                <button class="mini-bridge sil-pilot-bridge-trigger" data-source-id="${b.post_id}" data-source-title="${b.title.replace(/"/g,'&quot;')}" data-anchor="${b.kw.replace(/"/g,'&quot;')}">Booster 🎯</button>
            </li>`;
        });
        target.html(html + '</ul>');
    }

    // --- Tabs ---
    $('.pilot-tab-btn').on('click', function() {
        const tab = $(this).data('tab');
        $('.pilot-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.pilot-tab-content').removeClass('active');
        $(`#pilot-tab-${tab}`).addClass('active');

        if (tab === 'journal') loadJournal();
        if (tab === 'diagnosis') loadDiagnosis();
        if (tab === 'incubator') loadIncubator();
    });

    // --- Diagnostics ---
    function loadDiagnosis() {
        const report = $('#sil-diagnosis-report');
        report.html('<p class="empty-state">Analyse en cours...</p>');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_pilotage_diagnostics',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success) {
                let d = response.data;
                let html = '<div class="diag-container">';

                // Environment
                html += '<div class="diag-section-title">🌍 Environnement</div>';
                html += `<div class="diag-line"><span class="diag-label">Horodatage</span> <span class="diag-val">${d.timestamp}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">PHP Version</span> <span class="diag-val">${d.env.php}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">WordPress</span> <span class="diag-val">${d.env.wp}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">Memory Limit</span> <span class="diag-val">${d.env.memory_limit}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">Max Exec Time</span> <span class="diag-val">${d.env.max_execution_time}s</span></div>`;

                // Database
                html += '<div class="diag-section-title">🗃️ Tables</div>';
                Object.entries(d.database).forEach(([key, val]) => {
                    html += `<div class="diag-line"><span class="diag-label">${key}</span> <span class="diag-val">${val}</span></div>`;
                });

                // Counts
                if (d.counts) {
                    html += '<div class="diag-section-title">📊 Volumes</div>';
                    html += `<div class="diag-line"><span class="diag-label">Liens Internes</span> <span class="diag-val">${d.counts.total_links}</span></div>`;
                    html += `<div class="diag-line"><span class="diag-label">Embeddings</span> <span class="diag-val">${d.counts.total_embeddings}</span></div>`;
                    html += `<div class="diag-line"><span class="diag-label">Action Logs</span> <span class="diag-val">${d.counts.total_logs}</span></div>`;
                }

                // Logic Tests
                html += '<div class="diag-section-title">🧪 Tests Logiques</div>';
                html += `<div class="diag-line"><span class="diag-label">Orphan Query</span> <span class="diag-val">${d.logic_test.orphan_query}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">Booster Query</span> <span class="diag-val">${d.logic_test.booster_query}</span></div>`;

                // AJAX Routes
                if (d.ajax_routes) {
                    html += '<div class="diag-section-title">🔌 Routes AJAX</div>';
                    Object.entries(d.ajax_routes).forEach(([key, val]) => {
                        html += `<div class="diag-line"><span class="diag-label">${key}</span> <span class="diag-val">${val}</span></div>`;
                    });
                }

                html += '</div>';
                report.html(html);
            } else {
                report.html('<p class="diag-val error">❌ Erreur AJAX Diagnostic: ' + (response.data || 'Réponse vide') + '</p>');
            }
        }).fail(function(xhr, textStatus, errorThrown) {
            console.error("SIL Diagnostic AJAX failed:", textStatus, errorThrown, xhr.responseText);
            let errorMsg = '❌ Erreur Réseau (' + xhr.status + ')';
            if (xhr.status === 0) {
                errorMsg = '❌ Erreur Réseau: Impossible de joindre le serveur. Vérifiez la connexion.';
            } else if (xhr.status === 400) {
                errorMsg = '❌ Route AJAX non enregistrée (400). La route "sil_get_pilotage_diagnostics" est peut-être absente.';
            } else if (xhr.status === 403) {
                errorMsg = '❌ Nonce expiré ou permissions insuffisantes (403).';
            }
            report.html('<p class="diag-val error">' + errorMsg + '</p>');
        });
    }

    $('#force-diagnosis').on('click', loadDiagnosis);

    // --- Journal ---
    function loadJournal() {
        const list = $('#sil-action-log-list');
        list.html('<p class="empty-state">Récupération des actions...</p>');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_action_logs',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success) {
                renderJournal(response.data);
            } else {
                list.html('<p class="empty-state">Erreur serveur journal.</p>');
            }
        }).fail(function(xhr) {
            list.html('<p class="empty-state">❌ Erreur Réseau journal (' + xhr.status + ').</p>');
        });
    }

    function renderJournal(logs) {
        const list = $('#sil-action-log-list');
        if (!logs || logs.length === 0) {
            list.html('<p class="empty-state">Aucune action journalisée pour le moment.</p>');
            return;
        }

        let html = '<table class="journal-table"><thead><tr><th>Date</th><th>Action</th><th>Cible</th><th>ROI</th></tr></thead><tbody>';
        logs.forEach(log => {
            html += `<tr>
                <td class="col-date">${log.human_time}</td>
                <td class="col-type"><span class="badge-action ${log.action_type}">${log.action_type}</span></td>
                <td class="col-title">${log.target_title || log.source_title || 'N/A'}</td>
                <td class="col-roi">Pos T0</td>
            </tr>`;
        });
        list.html(html + '</tbody></table>');
    }

    // --- Search ---
    let searchTimeout;
    $('#manual-post-search').on('input', function() {
        const q = $(this).val();
        if (q.length < 3) { $('#search-results-dropdown').hide(); return; }
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            $.post(silSharedData.ajaxurl, {
                action: 'sil_search_posts',
                nonce: silSharedData.nonce,
                q: q
            }, (res) => {
                if (res.success && res.data.length > 0) {
                    let html = '';
                    res.data.forEach(p => html += `<div class="dropdown-item" data-id="${p.id}" data-title="${p.title}">${p.title}</div>`);
                    $('#search-results-dropdown').html(html).show();
                }
            });
        }, 300);
    });

    $(document).on('click', '.dropdown-item', function() {
        $('#manual-post-id').val($(this).data('id'));
        $('#manual-post-search').val($(this).data('title'));
        $('#search-results-dropdown').hide();
    });

    $('#submit-manual-log').on('click', function() {
        const btn = $(this);
        const pid = $('#manual-post-id').val();
        if (!pid) { silToast('Sélectionnez un article.', 'error'); return; }
        
        btn.prop('disabled', true).html('<span class="sil-spinner"></span>...');
        $.post(silSharedData.ajaxurl, {
            action: 'sil_log_manual_action',
            nonce: silSharedData.nonce,
            post_id: pid,
            action_type: $('#manual-action-type').val(),
            note: $('#manual-action-note').val()
        }, function(res) {
            btn.prop('disabled', false).text('Enregistrer Action');
            if (res.success) {
                $('#manual-post-search, #manual-action-note').val('');
                $('#manual-post-id').val('');
                silToast('✅ Action enregistrée avec succès.');
                loadJournal();
            } else {
                silToast('Erreur: ' + res.data, 'error');
            }
        });
    });

    // --- Bridge Trigger: Recherche de cible inline puis modale IA ---
    $(document).on('click', '.sil-pilot-bridge-trigger', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const sourceId = $btn.data('source-id');
        const sourceTitle = $btn.data('source-title');
        const presetAnchor = $btn.data('anchor') || '';

        // Remove any existing popover
        $('.sil-pilot-target-popover').remove();

        const popoverHtml = `
        <div class="sil-pilot-target-popover" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100010;background:#fff;border-radius:12px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);padding:24px;width:420px;max-width:90vw;font-family:'DM Sans',sans-serif;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h4 style="margin:0;font-size:15px;color:#0f172a;">🌉 Créer un Pont Sémantique</h4>
                <button class="sil-popover-close" style="background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;">&times;</button>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:16px;">
                <div style="font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:4px;">📄 Source</div>
                <div style="font-size:13px;font-weight:600;color:#1e293b;">${sourceTitle}</div>
            </div>
            <div style="margin-bottom: 12px;">
                <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;display:block;margin-bottom:6px;">🎯 Article Cible</label>
                <input type="text" class="sil-popover-target-search" placeholder="Tapez le titre d'un article..." style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;box-sizing:border-box;">
                <div class="sil-popover-results" style="max-height:160px;overflow-y:auto;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;display:none;"></div>
            </div>
            <div style="margin-bottom:12px; display:flex; gap:10px;">
                <div style="flex:1;">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;display:block;margin-bottom:6px;">🔗 Ancre</label>
                    <input type="text" class="sil-popover-anchor" value="${presetAnchor}" placeholder="Ex: formation SEO" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;box-sizing:border-box;">
                </div>
                <div style="flex:2;">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;display:block;margin-bottom:6px;">📝 Notes / Emplacement</label>
                    <input type="text" class="sil-popover-note" placeholder="Ex: après le 2e H2, en conclusion..." style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;box-sizing:border-box;">
                </div>
            </div>
            <div class="sil-popover-target-info" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px;margin-bottom:16px;">
                <div style="font-size:10px;text-transform:uppercase;color:#166534;font-weight:700;margin-bottom:4px;">🎯 Cible sélectionnée</div>
                <div class="sil-popover-target-name" style="font-size:13px;font-weight:600;color:#166534;"></div>
            </div>
            <button class="button button-primary sil-popover-confirm" disabled style="width:100%;justify-content:center;display:flex;align-items:center;gap:8px;padding:10px;">🤖 Générer le Prompt IA</button>
            <input type="hidden" class="sil-popover-source-id" value="${sourceId}">
            <input type="hidden" class="sil-popover-target-id" value="">
        </div>
        <div class="sil-pilot-target-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:100009;"></div>`;

        $('body').append(popoverHtml);
        $('.sil-popover-target-search').focus();
    });

    // Close popover
    $(document).on('click', '.sil-popover-close, .sil-pilot-target-overlay', function() {
        $('.sil-pilot-target-popover, .sil-pilot-target-overlay').remove();
    });

    // Target search within popover
    let popoverSearchTimeout;
    $(document).on('input', '.sil-popover-target-search', function() {
        const $input = $(this);
        const val = $input.val();
        const $results = $input.siblings('.sil-popover-results');
        if (val.length < 3) { $results.hide().empty(); return; }
        clearTimeout(popoverSearchTimeout);
        popoverSearchTimeout = setTimeout(() => {
            $.post(silSharedData.ajaxurl, {
                action: 'sil_search_posts',
                nonce: silSharedData.nonce,
                q: val
            }, function(res) {
                if (res.success && res.data.length > 0) {
                    let html = '';
                    res.data.forEach(p => {
                        html += `<div class="sil-popover-result-item" data-id="${p.id}" data-title="${p.title}" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:12px;color:#334155;transition:background 0.15s;" onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background='#fff'">${p.title}</div>`;
                    });
                    $results.html(html).show();
                } else {
                    $results.html('<div style="padding:8px 12px;color:#94a3b8;font-size:12px;">Aucun résultat</div>').show();
                }
            });
        }, 300);
    });

    // Select target from results
    $(document).on('click', '.sil-popover-result-item', function() {
        const targetId = $(this).data('id');
        const targetTitle = $(this).data('title');
        const $popover = $(this).closest('.sil-pilot-target-popover');
        $popover.find('.sil-popover-target-id').val(targetId);
        $popover.find('.sil-popover-target-search').val(targetTitle);
        $popover.find('.sil-popover-results').hide();
        $popover.find('.sil-popover-target-name').text(targetTitle);
        $popover.find('.sil-popover-target-info').show();
        $popover.find('.sil-popover-confirm').prop('disabled', false);
    });

    // Confirm: trigger Bridge Manager
    $(document).on('click', '.sil-popover-confirm', function() {
        const sourceId = $('.sil-popover-source-id').val();
        const targetId = $('.sil-popover-target-id').val();
        const anchor   = $('.sil-popover-anchor').val();
        const note     = $('.sil-popover-note').val(); // Capture the manual note

        if (!targetId) return;

        // Close popover and launch Bridge Manager
        $('.sil-pilot-target-popover, .sil-pilot-target-overlay').remove();

        if (typeof window.SIL_Bridge !== 'undefined') {
            // Create a temporary button for the spinner state
            const $tempBtn = $('<button class="button">⏳ Calcul sémantique...</button>');
            $('body').append($tempBtn.hide());
            window.SIL_Bridge.generate(sourceId, targetId, anchor, $tempBtn, note);
        } else {
            silToast("Le moteur du pont sémantique IA n'est pas chargé.", 'error');
        }
    });

    $('.refresh-all').on('click', function() {
        $(this).addClass('rotating');
        loadPilotActions();
        setTimeout(() => $(this).removeClass('rotating'), 1000);
    });

    // --- Incubator ---
    function loadIncubator() {
        const list = $('#sil-incubator-list');
        list.html("<p class='empty-state'>Chargement de la file d'attente...</p>");

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_scheduled_links',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success && Object.keys(response.data).length > 0) {
                renderIncubator(response.data);
            } else {
                list.html("<p class='empty-state'>L'incubateur est vide.</p>");
            }
        });
    }

    function renderIncubator(links) {
        let html = '<table class="journal-table"><thead><tr><th>Source</th><th>Ancre Prévue</th><th>Cible</th><th>Statut/Action</th></tr></thead><tbody>';
        // handle object/array
        const linksArr = Array.isArray(links) ? links : Object.values(links);
        linksArr.forEach(l => {
            const isTargetPublish = (l.target_status === 'publish');
            
            let btnAction = '';
            if (l.status === 'completed') {
                btnAction = '<span class="status-pill ok">✅ Créé</span>';
            } else if (isTargetPublish) {
                btnAction = `<button class="mini-bridge btn-create-bridge" data-id="${l.id}" data-source="${l.source_id}" data-target="${l.target_id}" data-anchor="${l.anchor}" data-note="${l.note || ''}">Créer Pont 🚀</button>`;
            } else {
                btnAction = `<button class="mini-adopt" disabled style="opacity:0.5;cursor:not-allowed;">Attente Publication...</button>`;
            }

            html += `<tr>
                <td class="col-title">${l.source_title || 'N/A' }</td>
                <td style="font-style:italic;">
                    <span style="color:#a1a1aa;">"${l.anchor}"</span>
                    ${l.note ? `<br><small style="color:#6b7280; font-size: 0.8em;">💬 ${l.note}</small>` : ''}
                </td>
                <td class="col-title">${l.target_title || 'N/A'}</td>
                <td>
                    ${btnAction}
                    <button class="btn-delete-scheduled" data-id="${l.id}" style="background:transparent;border:none;color:#ef4444;cursor:pointer;margin-left:10px;" title="Supprimer">🗑️</button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        $('#sil-incubator-list').html(html);
    }

    let searchIncTimeout;
    function setupIncubatorSearch(inputId, dropdownId, hiddenId, postStatus) {
        $(`#${inputId}`).on('input', function() {
            const q = $(this).val();
            if (q.length < 3) { $(`#${dropdownId}`).hide(); return; }
            clearTimeout(searchIncTimeout);
            searchIncTimeout = setTimeout(() => {
                $.post(silSharedData.ajaxurl, {
                    action: 'sil_search_posts',
                    nonce: silSharedData.nonce,
                    post_status: postStatus,
                    q: q
                }, (res) => {
                    if (res.success && res.data.length > 0) {
                        let html = '';
                        res.data.forEach(p => html += `<div class="dropdown-item" data-id="${p.id}" data-title="${p.title}">${p.title}</div>`);
                        $(`#${dropdownId}`).html(html).show();
                    }
                });
            }, 300);
        });

        $(document).on('click', `#${dropdownId} .dropdown-item`, function() {
            $(`#${hiddenId}`).val($(this).data('id'));
            $(`#${inputId}`).val($(this).data('title'));
            $(`#${dropdownId}`).hide();
        });
    }

    setupIncubatorSearch('inc-source-search', 'inc-source-dropdown', 'inc-source-id', ['publish']);
    setupIncubatorSearch('inc-target-search', 'inc-target-dropdown', 'inc-target-id', ['draft', 'future', 'pending']);

    $('#submit-incubator').on('click', function() {
        const btn = $(this);
        const source_id = $('#inc-source-id').val();
        const target_id = $('#inc-target-id').val();
        const anchor = $('#inc-anchor').val();
        const note = $('#inc-note').val();

        if (!source_id || !target_id || !anchor) {
            silToast('Veuillez remplir les champs obligatoires.', 'error');
            return;
        }

        btn.prop('disabled', true).html('<span class="sil-spinner"></span>...');
        $.post(silSharedData.ajaxurl, {
            action: 'sil_schedule_link',
            nonce: silSharedData.nonce,
            source_id: source_id,
            target_id: target_id,
            anchor: anchor,
            note: note
        }, function(res) {
            btn.prop('disabled', false).text('Programmer');
            if (res.success) {
                $('#inc-source-search, #inc-target-search, #inc-anchor, #inc-note').val('');
                $('#inc-source-id, #inc-target-id').val('');
                silToast('🌱 Lien programmé avec succès.');
                loadIncubator();
            } else {
                silToast(res.data, 'error');
            }
        });
    });

    $(document).on('click', '.btn-delete-scheduled', function() {
        if (!confirm('Voulez-vous supprimer ce pont programmé ?')) return;
        const id = $(this).data('id');
        $.post(silSharedData.ajaxurl, {
            action: 'sil_delete_scheduled_link',
            nonce: silSharedData.nonce,
            id: id
        }, function(res) {
            if(res.success) loadIncubator();
        });
    });

    $(document).on('click', '.btn-create-bridge', function() {
        const btn = $(this);
        const source_id = btn.data('source');
        const target_id = btn.data('target');
        const anchor = btn.data('anchor');
        const note = btn.data('note');

        if (typeof window.SIL_Bridge !== 'undefined') {
            window.SIL_Bridge.generate(source_id, target_id, anchor, btn, note);
        } else {
            silToast("Le moteur du pont sémantique IA n'est pas chargé sur cette page.", 'error');
        }
    });

    // Écouter le succès du pont sémantique
    $(document).on('sil_bridge_applied', function(e, sourceId, targetId) {
        $.post(silSharedData.ajaxurl, {
            action: 'sil_complete_scheduled_link',
            nonce: silSharedData.nonce,
            source_id: sourceId,
            target_id: targetId
        }, function() {
            if ($('#pilot-tab-incubator').hasClass('active')) {
                loadIncubator();
            }
        });
    });


    // Start
    init();
});

