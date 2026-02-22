/**
 * Tracking des clics sur les liens mailto warmup
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Tracker les clics sur les liens mailto
        $('.pw-mailto-link[data-track="true"]').on('click', function(e) {
            const $link = $(this);
            const template = $link.data('template');
            const server = $link.data('server');
            const pageUrl = window.location.href;
            
            // Envoyer la requête AJAX (non-bloquante)
            $.post(pwMailto.ajaxurl, {
                action: 'pw_mailto_click',
                nonce: pwMailto.nonce,
                template: template,
                server: server,
                page_url: pageUrl
            }, function(response) {
                if (response.success) {
                    console.log('Postal Warmup: Click tracked');
                } else {
                    console.warn('Postal Warmup: Tracking failed', response);
                }
            });
            
            // Le clic continue normalement (ouvre le client mail)
        });
        
        // Rotation des emails au survol
        $('.pw-mailto-link[data-rotate="true"]').on('mouseenter', function() {
            const $link = $(this);
            const emails = $link.data('rotate-emails');
            const domain = $link.data('server-domain');
            
            if (emails && domain) {
                const prefixList = emails.split(',');
                const randomPrefix = prefixList[Math.floor(Math.random() * prefixList.length)].trim();
                const newEmail = randomPrefix + '@' + domain;
                
                // Mettre à jour le href en conservant les params (subject, body)
                const currentHref = $link.attr('href');
                if (currentHref.indexOf('mailto:') === 0) {
                    const parts = currentHref.split('?');
                    const params = parts.length > 1 ? '?' + parts[1] : '';
                    $link.attr('href', 'mailto:' + newEmail + params);
                }
            } else if ($link.data('rotate') === 'true') {
                // Rotation AJAX complète (sujet + corps)
                const template = $link.data('template');
                const nonce = $link.data('nonce');
                
                $.post(pwMailto.ajaxurl, {
                    action: 'pw_get_mailto_url',
                    nonce: nonce,
                    template: template
                }, function(res) {
                    if (res.success && res.data.url) {
                        $link.attr('href', res.data.url);
                    }
                });
            }
        });

    });
    
})(jQuery);