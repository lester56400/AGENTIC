/**
 * Smart Internal Links - Frontend Tracking
 * Écoute les clics sur les liens insérés par le plugin pour mesurer le CTR.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.body.addEventListener('click', function (e) {
        // On cherche si le clic ou un de ses parents est un lien SIL
        const link = e.target.closest('a.sil-link');

        if (link) {
            const linkId = link.getAttribute('data-sil-id');

            if (linkId && typeof silTracking !== 'undefined') {
                const data = new FormData();
                data.append('action', 'sil_track_click');
                data.append('link_id', linkId);
                data.append('nonce', silTracking.nonce);

                // navigator.sendBeacon est idéal pour le tracking car non-bloquant
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(silTracking.ajax_url, data);
                } else {
                    fetch(silTracking.ajax_url, {
                        method: 'POST',
                        body: data,
                        keepalive: true
                    });
                }
            }
        }
    });
});
