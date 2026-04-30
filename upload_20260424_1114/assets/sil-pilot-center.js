(function($) {
    "use strict";
    jQuery(document).ready(function($) {

    // Fallback for silNotify/silToast (admin.js is NOT loaded on Pilotage page)
    if (typeof window.silNotify !== 'function') {
        window.silNotify = function(msg, type) {
            console.warn('SIL Notify (' + (type || 'info') + '):', msg);
            alert((type === 'error' ? '❌ ' : '✅ ') + msg);
        };
    }
    if (typeof window.silToast !== 'function') {
        window.silToast = window.silNotify;
    }
    if (typeof window.escHtml !== 'function') {
        window.escHtml = function(text) {
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text ? String(text).replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
        };
    }
    if (typeof window.escAttr !== 'function') {
        window.escAttr = window.escHtml;
    }

    // Global Safety Check
    const isDataValid = () => typeof silSharedData !== 'undefined' && silSharedData.ajaxurl && silSharedData.nonce;

    if (!isDataValid()) {
        $('.action-list').html('<p class="empty-state">❌ Erreur de configuration (Metadata).</p>');
        return;
    }

    // --- Core Logic ---
    function init() {
        try {
            initAnimations();
            loadPilotActions();
            // Load journal only if tab active, else deferred
        } catch (e) {
        }
    }

    function initAnimations() {
        $('.glass-card').addClass('sil-staggered').each(function(index) {
            $(this).addClass('sil-staggered-' + ((index % 4) + 1));
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
                renderCannibals(response.data.cannibals || []);
                renderTopologicalAnomalies(response.data.siphons || [], response.data.intruders || []);
                renderLeaks(response.data.leaks || []);
                renderDecay(response.data.decay || []);
            } else {
                const errorMsg = (response && response.data) ? (typeof response.data === 'object' ? (response.data.message || JSON.stringify(response.data)) : response.data) : 'Inconnu';
                list.html('<p class="empty-state">❌ Erreur Serveur: ' + errorMsg + '</p>');
                window.silNotify('Erreur : ' + errorMsg, 'error');
            }
        }).fail(function(xhr, status, error) {
            const statusCode = xhr.status;
            let msg = 'Erreur réseau ou serveur (' + statusCode + ')';
            if (statusCode === 500) msg = 'Erreur interne du serveur (500). Vérifiez les logs PHP ou l\'allocation mémoire.';
            if (statusCode === 504) msg = 'Gateway Timeout (504). Le scan est trop lourd pour le serveur.';
            
            list.html('<p class="empty-state">❌ ' + msg + '</p>');
            window.silNotify(msg, 'error');
            console.error('SIL BMAD DEBUG:', status, error, xhr.responseText);
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
            const isFull = o.current_links >= o.quota;
            const lockHtml = o.is_locked ? `<span class="sil-badge-lock" title="Drip-Feed : Verrouillé pendant 14 jours">⏳ Cooldown</span>` : '';
            
            html += `<li class="${o.is_locked ? 'item-locked' : ''}">
                <div class="item-main">
                    <span class="item-title">${o.title}</span>
                    <div class="item-stats">
                        <span class="item-meta">${o.impressions} imps</span>
                        <span class="quota-gauge ${isFull ? 'full' : ''}" title="Quota de liens (Ratio x Multiplicateur)">
                            ${o.current_links} / ${o.quota} links
                        </span>
                        ${lockHtml}
                    </div>
                </div>
                <button class="mini-adopt sil-pilot-bridge-trigger" 
                        data-source-id="${o.id}" 
                        data-source-title="${o.title.replace(/"/g,'&quot;')}"
                        ${o.is_locked ? 'disabled' : ''}>Adopter 🚀</button>
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
            const isFull = b.current_links >= b.quota;
            const lockHtml = b.is_locked ? `<span class="sil-badge-lock" title="Drip-Feed : Verrouillé pendant 14 jours">⏳ Cooldown</span>` : '';

            html += `<li class="sil-pilot-item booster-type ${b.is_locked ? 'item-locked' : ''}">
                <div class="item-row-top">
                    <span class="item-title">"${b.kw}"</span>
                    ${lockHtml}
                </div>
                <div class="item-row-bottom">
                    <div class="item-meta">
                        <span>Pos ${b.pos} | ${b.impressions} imps</span>
                        <span class="quota-gauge ${isFull ? 'full' : ''}" title="Quota de liens (Ratio x Multiplicateur)">
                            ${b.current_links} / ${b.quota} links
                        </span>
                    </div>
                    <button class="mini-bridge sil-pilot-booster-trigger" 
                            data-target-id="${b.post_id}" 
                            data-target-title="${b.title.replace(/"/g,'&quot;')}" 
                            data-anchor="${b.kw.replace(/"/g,'&quot;')}"
                            ${b.is_locked ? 'disabled' : ''}>
                        Booster 🎯
                    </button>
                </div>
            </li>`;
        });
        target.html(html + '</ul>');
    }

    function renderTopologicalAnomalies(siphons, intruders) {
        const target = $('#sil-topological-list');
        if (siphons.length === 0 && intruders.length === 0) {
            target.html('<p class="empty-state">✅ Topologie stable.</p>');
            return;
        }

        let html = '<ul class="pilot-action-items">';
        
        // Siphons
        siphons.forEach(s => {
            html += `<li class="sil-pilot-item anomaly-type">
                <div class="item-row-top">
                    <span class="item-title" title="Siphon : Reçoit ${s.in_count} liens mais n'en renvoie aucun.">${silSharedData.icons.trending_down} ${s.title}</span>
                </div>
                <div class="item-row-bottom">
                    <span class="item-meta">${s.in_count} inlinks</span>
                    <button class="mini-bridge sil-pilot-fix-siphon" data-post-id="${s.id}" title="Lier automatiquement vers le Pivot du silo">Stabiliser ${silSharedData.icons.wand}</button>
                </div>
            </li>`;
        });

        // Intruders
        intruders.forEach(i => {
            const bestSim = Math.round(i.similarity * 100);
            const currentSim = Math.round(i.current_similarity * 100);
            const delta = bestSim - currentSim;
            
            // Tooltip pédagogique sur le seuil de 10%
            const toleranceNote = delta < 10 
                ? "Écart < 10% : L'article est polyvalent. Le rapatriement est facultatif, un pont sémantique suffit souvent." 
                : "Écart significatif : Un rapatriement vers le silo idéal est conseillé pour stabiliser la structure.";

            html += `<li class="sil-pilot-item anomaly-type">
                <div class="item-row-top">
                    <span class="item-title" title="Intru : Actuellement dans ${i.current_silo_label}, devrait être dans ${i.ideal_silo_label}.">${silSharedData.icons.ghost} ${i.title}</span>
                </div>
                <div class="item-row-bottom">
                    <span class="item-meta" title="${toleranceNote}"><strong>${bestSim}%</strong> >> ${currentSim}% sim.</span>
                    <div class="item-actions">`;
            
            // Proposer un pont s'il n'existe pas encore
            if (!i.has_bridge_to_target) {
                html += `<button class="mini-bridge sil-pilot-bridge-intruder" data-post-id="${i.id}" data-target-silo="${i.ideal_silo_id}" title="Créer un lien vers le silo ${i.ideal_silo_label}">Pont ${silSharedData.icons.link}</button>`;
            }

            html += `<button class="mini-adopt sil-pilot-repatriate" data-post-id="${i.id}" data-ideal-silo="${i.ideal_silo_id}" title="Déplacer vers le silo ${i.ideal_silo_label}">Rapatrier ${silSharedData.icons.rocket}</button>
                    </div>
                </div>
            </li>`;
        });

        target.html(html + '</ul>');
    }

    function renderCannibals(cannibals) {
        const target = $('#sil-cannibal-list');
        const bubble = $('#sil-cannibal-bubble');
        const threshold = 0.92; // 92%
        
        if (!cannibals || cannibals.length === 0) {
            target.html('<p class="empty-state">⚔️ Aucun duel critique détecté.</p>');
            bubble.hide();
            return;
        }

        bubble.text(cannibals.length).show();

        let html = '<div class="cannibal-duels">';
        cannibals.forEach(c => {
            const similarityPercent = Math.round(c.similarity * 100);
            const commonKws = c.common_kws ? c.common_kws.split(',').map(k => k.trim()) : [];
            const isHighRisk = c.similarity >= threshold;
            
            html += `
                <div class="cannibal-duel-row urgency-${c.urgency}">
                    <div class="duel-post">
                        <span class="item-title">${window.escHtml(c.source_title)}</span>
                        <span class="item-meta">📄 Source principale</span>
                    </div>
                    
                    <div class="duel-vs">
                        <span class="vs-badge">VS</span>
                        <div class="similarity-meter" title="Similarité: ${similarityPercent}%">
                            <div class="similarity-fill" style="width: ${similarityPercent}%"></div>
                        </div>
                        <span class="similarity-label">${similarityPercent}%</span>
                    </div>
                    
                    <div class="duel-post">
                        <span class="item-title">${window.escHtml(c.target_title)}</span>
                        <span class="item-meta">📄 Rivale sémantique</span>
                    </div>
                    
                    <div class="duel-actions">
                        <button class="duel-btn btn-merge sil-pilot-bridge-trigger" 
                            data-source-id="${c.source_id}" 
                            data-target-id="${c.target_id}" 
                            data-source-title="${c.source_title.replace(/"/g,'&quot;')}"
                            title="Fusionner via Pont Sémantique">🔗 Fusion IA</button>
                        <button class="duel-btn btn-pivot" data-id="${c.target_id}">🔄 Pivot d'angle</button>
                        <button class="duel-btn btn-301" data-id="${c.target_id}">↗️ Rediriger 301</button>
                    </div>

                    <div class="recommendation-box">
                        <p><strong>💡 Conseil Expert :</strong> ${isHighRisk ? 
                            `Forte cannibalisation (${similarityPercent}%). Nous recommandons une <strong>Fusion IA</strong> pour créer un contenu pilier ou une <strong>Redirection 301</strong> si l'un des articles est obsolète.` : 
                            `Similarité modérée (${similarityPercent}%). Un <strong>Pivot d'angle</strong> sémantique permettrait de différencier ces contenus sans perte de pages.`
                        }</p>
                    </div>

                    ${commonKws.length > 0 ? `
                        <div class="common-queries">
                            ${commonKws.map(kw => `<span class="query-pill">🔑 ${window.escHtml(kw)}</span>`).join('')}
                        </div>
                    ` : ''}
                </div>`;
        });
        target.html(html + '</div>');
    }

    function renderLeaks(leaks) {
        const countTarget = $('#sil-leak-count');
        const listTarget = $('#sil-leak-list');
        
        countTarget.text(leaks.length);
        if (leaks.length === 0) {
            listTarget.html('<p class="empty-state" style="font-size:10px;">Étanchéité parfaite !</p>');
            return;
        }

        let html = '';
        leaks.forEach(l => {
            html += `
                <div class="insight-item">
                    <span class="item-label" title="${l.label}">${l.label}</span>
                    <span class="item-value">${l.ratio}% fuite</span>
                </div>`;
        });
        listTarget.html(html);
    }

    function renderDecay(decay) {
        const countTarget = $('#sil-decay-count');
        const listTarget = $('#sil-decay-list');
        
        countTarget.text(decay.length);
        if (decay.length === 0) {
            listTarget.html('<p class="empty-state" style="font-size:10px;">Momentum stable.</p>');
            return;
        }

        let html = '';
        decay.forEach(d => {
            html += `
                <div class="insight-item">
                    <span class="item-label" title="${d.title}">${d.title}</span>
                    <span class="item-value">${d.ctr}% CTR</span>
                </div>`;
        });
        listTarget.html(html);
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
        if (tab === 'coherence') {
            loadPilotActions(); 
        }
    });

    // --- Event Handlers for Phase 0 stabilization ---
    $(document).on('click', '.sil-pilot-fix-siphon', function() {
        const btn = $(this);
        const postId = btn.data('post-id');
        
        btn.prop('disabled', true).text('⏳ Prompt...');
        
        $.post(silSharedData.ajaxurl, {
            action: 'sil_generate_stabilize_prompt',
            nonce: silSharedData.admin_nonce,
            post_id: postId
        }, function(response) {
            btn.prop('disabled', false).html('Stabiliser ' + silSharedData.icons.wand);
            if (response.success) {
                window.SIL_Bridge.openModal(response.data);
            } else {
                window.silNotify('Erreur : ' + response.data, 'error');
            }
        }).fail(function() {
            btn.prop('disabled', false).html('Stabiliser ' + silSharedData.icons.wand);
            window.silNotify('Erreur réseau lors de la génération du prompt de stabilisation.', 'error');
        });
    });

    $(document).on('click', '.sil-pilot-bridge-intruder', function() {
        const btn = $(this);
        const postId = btn.data('post-id');
        const targetSilo = btn.data('target-silo');
        
        btn.prop('disabled', true).text('⏳ Prompt...');
        
        $.post(silSharedData.ajaxurl, {
            action: 'sil_generate_stabilize_prompt',
            nonce: silSharedData.admin_nonce,
            post_id: postId,
            target_silo_id: targetSilo // Optionnel : force le silo cible pour le prompt
        }, function(response) {
            btn.prop('disabled', false).html('Pont ' + silSharedData.icons.link);
            if (response.success) {
                window.SIL_Bridge.openModal(response.data);
            } else {
                window.silNotify('Erreur : ' + response.data, 'error');
            }
        }).fail(function() {
            btn.prop('disabled', false).html('Pont ' + silSharedData.icons.link);
            window.silNotify('Erreur réseau lors de la génération du prompt de pontage.', 'error');
        });
    });

    $(document).on('click', '.sil-pilot-repatriate', function() {
        const btn = $(this);
        const postId = btn.data('post-id');
        const idealSiloId = btn.data('ideal-silo');
        
        btn.prop('disabled', true).text('Rapatriement...');
        
        $.post(silSharedData.ajaxurl, {
            action: 'sil_repatriate_intruder',
            nonce: silSharedData.nonce,
            post_id: postId,
            ideal_silo_id: idealSiloId
        }, function(response) {
            if (response.success) {
                window.silNotify(response.data.message, 'success');
                btn.closest('li').fadeOut();
            } else {
                window.silNotify('Erreur : ' + response.data, 'error');
                btn.prop('disabled', false).html('Rapatrier ' + silSharedData.icons.rocket);
            }
        });
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

                // Recent Errors (Option B)
                if (d.recent_errors && d.recent_errors.length > 0) {
                    html += '<div class="diag-section-title">📜 Logs d\'Erreurs Récents (PHP)</div>';
                    html += '<div class="diag-error-log" style="background:#1e293b; color:#cbd5e1; padding:12px; border-radius:8px; font-family:monospace; font-size:10px; max-height:200px; overflow-y:auto; margin-top:10px; line-height:1.4;">';
                    d.recent_errors.forEach(err => {
                        let color = '#94a3b8';
                        if (err.toLowerCase().includes('fatal') || err.toLowerCase().includes('error')) color = '#f87171';
                        else if (err.toLowerCase().includes('warning')) color = '#fbbf24';
                        
                        html += `<div style="color:${color}; border-bottom:1px solid #334155; padding-bottom:4px; margin-bottom:4px;">${err}</div>`;
                    });
                    html += '</div>';
                }

                html += '</div>';
                report.html(html);
            } else {
                report.html('<p class="diag-val error">❌ Erreur AJAX Diagnostic: ' + (response.data || 'Réponse vide') + '</p>');
                window.silNotify('Erreur Diagnostic : ' + (response.data || 'Réponse vide'), 'error');
            }
        }).fail(function(xhr, textStatus, errorThrown) {
            const status = xhr.status;
            let errorMsg = '❌ Erreur Réseau (' + status + ')';
            if (status === 0) {
                errorMsg = '❌ Erreur Réseau: Impossible de joindre le serveur. Vérifiez la connexion.';
            } else if (status === 400) {
                errorMsg = '❌ Route AJAX non enregistrée (400). La route "sil_get_pilotage_diagnostics" est peut-être absente.';
            } else if (status === 403) {
                errorMsg = '❌ Nonce expiré ou permissions insuffisantes (403).';
            } else if (status === 500) {
                errorMsg = '❌ Erreur interne du serveur (500). Vérifiez les logs PHP.';
            }
            
            report.html('<p class="diag-val error">' + errorMsg + '</p>');
            window.silNotify(errorMsg, 'error');
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
                window.silNotify('Erreur de chargement du journal.', 'error');
            }
        }).fail(function(xhr) {
            const status = xhr.status;
            let msg = '❌ Erreur Réseau journal (' + status + ')';
            if (status === 403) msg = '❌ Session expirée (403/Journal).';
            
            list.html('<p class="empty-state">' + msg + '</p>');
            window.silNotify(msg, 'error');
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
        if (!pid) { window.silNotify('Sélectionnez un article.', 'error'); return; }
        
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
                window.silNotify('✅ Action enregistrée avec succès.');
                loadJournal();
            } else {
                window.silNotify('Erreur: ' + res.data, 'error');
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
        const presetTargetId = $btn.data('target-id'); // Pre-set for duel merge buttons

        // Shortcut: If target is already known (e.g. from Duel de Pages),
        // skip the target search popover and launch bridge directly
        if (presetTargetId) {
            if (typeof window.SIL_Bridge !== 'undefined') {
                window.SIL_Bridge.generate(sourceId, presetTargetId, presetAnchor || 'Cliquez ici', $btn);
            } else {
                window.silFetchAndShowBridgePrompt(sourceId, presetTargetId, presetAnchor || 'Cliquez ici');
            }
            return;
        }

        // Remove any existing popover
        $('.sil-pilot-target-popover').remove();

        const popoverHtml = `
        <div class="sil-pilot-target-popover">
            <div class="sil-popover-header">
                <h4>🌉 Créer un Pont Sémantique</h4>
                <button class="sil-popover-close">&times;</button>
            </div>
            <div class="sil-popover-section">
                <div class="sil-popover-label">📄 Source</div>
                <div class="sil-popover-value">${sourceTitle}</div>
            </div>
            <div class="sil-mt-2">
                <label class="sil-popover-label">🎯 Article Cible</label>
                <input type="text" class="sil-popover-target-search sil-popover-input" placeholder="Tapez le titre d'un article...">
                <div class="sil-popover-results"></div>
            </div>
            <div class="sil-mt-2 sil-flex-container" style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label class="sil-popover-label">🔗 Ancre</label>
                    <input type="text" class="sil-popover-anchor sil-popover-input" value="${presetAnchor}" placeholder="Ex: SEO">
                </div>
                <div style="flex:2;">
                    <label class="sil-popover-label">📝 Notes</label>
                    <input type="text" class="sil-popover-note sil-popover-input" placeholder="Ex: conclusion...">
                </div>
            </div>
            <div class="sil-popover-target-info sil-mt-2">
                <div class="sil-popover-label">🎯 Cible sélectionnée</div>
                <div class="sil-popover-target-name sil-popover-value" style="color:#166534;"></div>
            </div>
            <button class="button button-primary sil-popover-confirm sil-w-full sil-mt-4" disabled>Générer le Prompt IA</button>
            <input type="hidden" class="sil-popover-source-id" value="${sourceId}">
            <input type="hidden" class="sil-popover-target-id" value="">
        </div>
        <div class="sil-pilot-target-overlay"></div>`;

        $('body').append(popoverHtml);
        $('.sil-popover-target-search').focus();
    });
    
    // --- Booster Trigger: Target-First Semantic Source Search ---
    $(document).on('click', '.sil-pilot-booster-trigger', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const targetId = $btn.data('target-id');
        const targetTitle = $btn.data('target-title');
        const anchor = $btn.data('anchor');

        // Close any existing popover
        $('.sil-pilot-target-popover').remove();

        const popoverHtml = `
        <div class="sil-pilot-target-popover booster-logic">
            <div class="sil-popover-header">
                <h4>🚀 Booster de Trafic</h4>
                <button class="sil-popover-close">&times;</button>
            </div>
            <div class="sil-popover-section">
                <div class="sil-popover-label">🎯 Cible à Booster</div>
                <div class="sil-popover-value">${targetTitle}</div>
            </div>
            <div class="sil-popover-section">
                <div class="sil-popover-label">🔗 Requête GSC (Ancre)</div>
                <input type="text" class="sil-popover-anchor sil-popover-input" value="${anchor}">
            </div>
            <div class="sil-mt-2">
                <label class="sil-popover-label">💡 Sources Sémantiques Suggérées</label>
                <div class="sil-popover-suggestions">
                    <div class="sil-spinner"></div> Recherche des meilleures sources...
                </div>
            </div>
            <input type="hidden" class="sil-popover-target-id" value="${targetId}">
            <input type="hidden" class="sil-popover-source-id" value="">
            <button class="button button-primary sil-popover-confirm sil-w-full sil-mt-4" disabled>Générer le Pont IA</button>
        </div>
        <div class="sil-pilot-target-overlay"></div>`;

        $('body').append(popoverHtml);

        // Fetch semantic sources
        $.post(silSharedData.ajaxurl, {
            action: 'sil_find_semantic_sources',
            nonce: silSharedData.nonce,
            target_id: targetId
        }, function(res) {
            const $suggestions = $('.sil-popover-suggestions');
            if (res.success && res.data.length > 0) {
                let html = '<div class="suggestion-list">';
                res.data.forEach(s => {
                    const badgeClass = s.is_same_silo ? 'silo-same' : 'silo-diff';
                    const badgeLabel = s.is_same_silo ? '🟢 Même Silo' : '🟡 Hors Silo';
                    html += `
                        <div class="suggestion-item" data-id="${s.id}" data-title="${s.title}">
                            <div class="suggestion-main">
                                <div class="suggestion-title">${s.title}</div>
                                <div class="suggestion-meta">Sim: ${s.similarity}% <span class="silo-badge ${badgeClass}">${badgeLabel}</span></div>
                            </div>
                        </div>`;
                });
                $suggestions.html(html + '</div>');
            } else {
                $suggestions.html('<div class="empty-state">Aucune source pertinente trouvée via embeddings.</div>');
            }
        });
    });

    // Select source from suggestions
    $(document).on('click', '.suggestion-item', function() {
        $('.suggestion-item').removeClass('active');
        $(this).addClass('active');
        const sourceId = $(this).data('id');
        const $popover = $(this).closest('.sil-pilot-target-popover');
        $popover.find('.sil-popover-source-id').val(sourceId);
        $popover.find('.sil-popover-confirm').prop('disabled', false);
    });

    // Handle confirm for Booster Popover
    $(document).on('click', '.booster-logic .sil-popover-confirm', function() {
        const $popover = $(this).closest('.sil-pilot-target-popover');
        const sourceId = $popover.find('.sil-popover-source-id').val();
        const targetId = $popover.find('.sil-popover-target-id').val();
        const anchor = $popover.find('.sil-popover-anchor').val();
        
        if (typeof window.SIL_Bridge !== 'undefined') {
            window.SIL_Bridge.generate(sourceId, targetId, anchor, $(this));
        } else {
            window.silFetchAndShowBridgePrompt(sourceId, targetId, anchor);
        }
        $('.sil-pilot-target-popover, .sil-pilot-target-overlay').remove();
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
                        html += `<div class="sil-popover-result-item" data-id="${p.id}" data-title="${p.title}">${p.title}</div>`;
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
            window.silNotify("Le moteur du pont sémantique IA n'est pas chargé.", 'error');
        }
    });

    $('.refresh-all').on('click', function() {
        $(this).addClass('rotating');
        loadPilotActions();
        setTimeout(() => $(this).removeClass('rotating'), 1000);
    });

    // Refresh cannibals (Cohérence tab)
    $(document).on('click', '.refresh-cannibals', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).text('⏳ Analyse...');
        loadPilotActions();
        setTimeout(() => {
            $btn.prop('disabled', false).text('🔄 Re-analyser');
        }, 2000);
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
                <td class="sil-text-italic">
                    <span class="sil-text-muted">"${l.anchor}"</span>
                    ${l.note ? `<br><small class="sil-text-note">💬 ${l.note}</small>` : ''}
                </td>
                <td class="col-title">${l.target_title || 'N/A'}</td>
                <td>
                    ${btnAction}
                    <button class="btn-delete-scheduled sil-btn-icon-danger" data-id="${l.id}" title="Supprimer">🗑️</button>
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
            window.silNotify('Veuillez remplir les champs obligatoires.', 'error');
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
                window.silNotify('🌱 Lien programmé avec succès.');
                loadIncubator();
            } else {
                window.silNotify(res.data, 'error');
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Programmer');
            window.silNotify('Erreur lors de la programmation du lien.', 'error');
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
            window.silNotify("Le moteur du pont sémantique IA n'est pas chargé sur cette page.", 'error');
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


    // --- Gutenberg Integrity ---
    $('#sil-scan-integrity').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="sil-spinner"></span> Analyse en cours...');
        $('#sil-integrity-results').slideDown();
        $('#sil-corrupted-list').html('<p class="empty-state">Audit de la base de données... cela peut prendre quelques secondes.</p>');
        
        runIntegrityScan(btn);
    });

    function runIntegrityScan(btn) {
        $.post(silSharedData.ajaxurl, {
            action: 'sil_scan_html_integrity',
            nonce: silSharedData.nonce
        }, function(res) {
            if (res.success) {
                $('#sil-scanned-count').text(res.data.scanned + ' (lot) | Total Protégés : ' + res.data.total_protected);
                $('#sil-corrupted-total').text(res.data.total_corrupted);
                loadCorruptedPosts();
            }
            btn.prop('disabled', false).text('Lancer l\'audit de structure');
        }).fail(function() {
            btn.prop('disabled', false).text('Lancer l\'audit de structure');
            window.silNotify('Échec de l\'audit de structure (Timeout ou Mémoire).', 'error');
        });
    }

    function loadCorruptedPosts() {
        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_corrupted_posts',
            nonce: silSharedData.nonce
        }, function(res) {
            if (res.success) {
                renderCorruptedPosts(res.data);
            }
        });
    }

    function renderCorruptedPosts(posts) {
        const target = $('#sil-corrupted-list');
        if (posts.length === 0) {
            target.html('<p class="empty-state">✅ Aucune corruption détectée dans les articles scannés.</p>');
            return;
        }

        let html = '<div class="corrupted-items-grid">';
        posts.forEach(p => {
            const snippet = p.error_snippet ? window.escHtml(p.error_snippet) : 'Contenu non-segmenté (Snippet indisponible)';
            const errorType = p.error_type ? window.escHtml(p.error_type) : 'Corruption structurelle (Scan requis)';
            html += `
                <div class="corrupted-item-row">
                    <div class="corrupted-info">
                        <span class="corrupted-title" title="${p.title}">${window.escHtml(p.title)}</span>
                        <div class="corrupted-meta">
                            <span class="error-badge">${errorType}</span>
                            <span class="meta-date">ID: ${p.id} | ${p.date}</span>
                        </div>
                        <div class="corrupted-snippet-box">
                            <div class="snippet-header">Extrait Contextuel :</div>
                            <code class="context-snippet">${snippet}</code>
                        </div>
                    </div>
                    <div class="corrupted-actions">
                        <a href="${p.edit_url}" class="sil-btn-mini" target="_blank">Éditer ✏️</a>
                    </div>
                </div>`;
        });
        target.html(html + '</div>');
    }


    $('#sil-purge-integrity').on('click', function() {
        if (!confirm('Voulez-vous vraiment réinitialiser l\'audit ? Cela effacera tous les résultats stockés.')) return;
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="sil-spinner"></span> Purge...');
        
        $.post(silSharedData.ajaxurl, {
            action: 'sil_purge_integrity_audit',
            nonce: silSharedData.nonce
        }, function(res) {
            btn.prop('disabled', false).html('Purger 🗑️');
            if (res.success) {
                window.silNotify(res.data, 'success');
                $('#sil-corrupted-list').html('<p class="empty-state">Audit réinitialisé. Relancez le scan pour un diagnostic frais.</p>');
                $('#sil-scanned-count').text('0');
                $('#sil-corrupted-total').text('0');
            } else {
                window.silNotify(res.data, 'error');
            }
        });
    });

    $('#sil-run-bridge-tests').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="sil-spinner"></span> Tests en cours...');
        $('#sil-integrity-results').slideDown();
        $('#sil-corrupted-list').html('<p class="empty-state">Exécution de la suite de tests unitaires... veuillez patienter.</p>');
        
        $.post(silSharedData.ajaxurl, {
            action: 'sil_run_bridge_tests',
            nonce: silSharedData.nonce
        }, function(res) {
            btn.prop('disabled', false).text('Tests Pipeline Pont');
            if (res.success) {
                renderTestResults(res.data);
            } else {
                window.silNotify(res.data, 'error');
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).text('Tests Pipeline Pont');
            window.silNotify('Erreur critique pendant les tests: ' + xhr.status, 'error');
        });
    });

    function renderTestResults(data) {
        const target = $('#sil-corrupted-list');
        let html = `
            <div class="test-report-header">
                <strong>Réussite : ${data.passed}/${data.total}</strong>
                <progress class="sil-progress" value="${data.passed}" max="${data.total}"></progress>
            </div>
            <div class="test-results-grid">`;
        
        data.details.forEach(t => {
            const statusClass = (t.status === 'pass') ? 'test-pass' : 'test-fail';
            const icon = (t.status === 'pass') ? '✅' : '❌';
            html += `
                <div class="test-result-row ${statusClass}">
                    <div class="test-name">${icon} ${window.escHtml(t.name)}</div>
                    <div class="test-detail">${window.escHtml(t.detail)}</div>
                </div>`;
        });
        
        target.html(html + '</div>');
    }


    // Start
    init();
});
})(jQuery);

