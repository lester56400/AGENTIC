/**
 * SIL Semantic Audit Engine
 * Handles relative bucketing and semantic analysis logic.
 */
window.SIL_Audit = (function($) {
    'use strict';

    return {
        /**
         * Scans current edges to find min/max distances and define buckets.
         */
        getBuckets: function(cy) {
            let minDist = Infinity;
            let maxDist = -Infinity;
            const edges = cy.edges().filter(e => !e.hasClass('ghost-edge'));

            if (edges.length === 0) return null;

            // Deep Scan Diagnostic
            const coordsBase = (window.silGraphData && window.silGraphData.metadata) ? window.silGraphData.metadata.projection_coords : null;
            if (coordsBase) {
                const keys = Object.keys(coordsBase);
                console.log('[SIL-Audit] Deep Scan :', {
                    total_coords: keys.length,
                    sample_keys: keys.slice(0, 5),
                    first_node_id: String(edges.first().source().id()),
                    first_node_clean: String(edges.first().source().id()).replace(/\D/g, '')
                });
            } else {
                console.warn('[SIL-Audit] Metadata.projection_coords is MISSING from silGraphData.');
            }

            // First pass: Find Min/Max
            edges.forEach((edge, i) => {
                const source = edge.source();
                const target = edge.target();
                
                // Re-sync coords if missing (Rescue Mission)
                this.syncCoords(source);
                this.syncCoords(target);

                if (source.data('sem_x') !== undefined && target.data('sem_x') !== undefined) {
                    const dx = source.data('sem_x') - target.data('sem_x');
                    const dy = source.data('sem_y') - target.data('sem_y');
                    const dist = Math.sqrt(dx*dx + dy*dy);
                    edge.data('_jit_dist', dist);
                    
                    if (dist < minDist) minDist = dist;
                    if (dist > maxDist) maxDist = dist;
                }
            });

            if (minDist === Infinity) return null;

            const amplitude = maxDist - minDist;
            const step = amplitude / 4;

            return {
                min: minDist,
                max: maxDist,
                amplitude: amplitude,
                thresholds: {
                    core: minDist + step,          // 75-100% (Closest)
                    robust: minDist + (step * 2),   // 50-75%
                    fragile: minDist + (step * 3)   // 25-50%
                }
            };
        },

        /**
         * Ensures semantic coordinates are present on a node.
         * Handles ID cleaning (e.g. "post-123" -> "123")
         */
        syncCoords: function(node) {
            if (node.data('sem_x') !== undefined) return;

            // Robust ID Extraction (Digits only)
            const rawId = String(node.id());
            const numericId = rawId.replace(/\D/g, ''); 
            
            if (!numericId) return;

            // Fallback: Try to find them in the global silGraphData ( Rescue Mission)
            if (window.silGraphData && window.silGraphData.metadata && window.silGraphData.metadata.projection_coords) {
                const coords = window.silGraphData.metadata.projection_coords[numericId];
                if (coords) {
                    console.log('[SIL-Audit] Rescued coords for node:', numericId);
                    node.data('sem_x', parseFloat(coords.x));
                    node.data('sem_y', parseFloat(coords.y));
                }
            }
        },

        /**
         * Applies the filter based on relative buckets.
         */
        apply: function(cy, mode, callback) {
            const buckets = this.getBuckets(cy);
            if (!buckets) {
                console.error('[SIL-Audit] No semantic data found for bucketing.');
                if (typeof callback === 'function') callback(0, 0, null, 'no_data');
                return { visible: 0, hidden: 0, error: 'No data' };
            }

            console.log('[SIL-Audit] Relative Buckets:', buckets);
            const results = [];
            let visibleCount = 0;
            let hiddenCount = 0;

            cy.batch(() => {
                cy.edges().filter(e => !e.hasClass('ghost-edge')).forEach((edge, i) => {
                    const dist = edge.data('_jit_dist');
                    if (dist === undefined) {
                        edge.style('display', 'element');
                        visibleCount++;
                        return;
                    }

                    let visible = true;
                    const T = buckets.thresholds;

                    switch(mode) {
                        case 'audit_weak':    visible = (dist > T.fragile); break;
                        case 'audit_fragile': visible = (dist <= T.fragile && dist > T.robust); break;
                        case 'audit_robust':  visible = (dist <= T.robust && dist > T.core); break;
                        case 'audit_core':    visible = (dist <= T.core); break;
                        case 'all':           visible = true; break;
                    }

                    if (visible) {
                        edge.style('display', 'element');
                        visibleCount++;
                    } else {
                        edge.style('display', 'none');
                        hiddenCount++;
                    }

                    if (i < 5) results.push({ id: edge.id(), dist: dist.toFixed(3) });
                });
            });

            if (results.length > 0) console.table(results);
            
            if (typeof callback === 'function') callback(visibleCount, hiddenCount, buckets);
            return { visible: visibleCount, hidden: hiddenCount, buckets: buckets };
        }
    };
})(jQuery);
