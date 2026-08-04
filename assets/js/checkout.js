(function ($) {
    'use strict';

    var embReady = false;
    var cardviewInited = false;
    var messageBound = false;

    function labels() {
        return window.SMFCheckout || {};
    }

    function updateRecommendation() {
        var $box = $('.smf-checkout-box');
        if (!$box.length) {
            return;
        }

        var cfg = labels();
        var country = ($('#billing_country').val() || $box.data('country') || '').toUpperCase();
        var captions = cfg.logoCaptions || {};
        var recommended = 'card';
        var localPriority = ['qpay', 'knet', 'benefit', 'mada', 'stc_pay', 'meeza'];

        $box.find('.smf-route-option[data-smf-countries]').each(function () {
            var $opt = $(this);
            var route = String($opt.data('smf-route') || '');
            var list = String($opt.data('smf-countries') || '').toUpperCase().split(',').filter(Boolean);
            var show = list.indexOf(country) !== -1;
            $opt.toggleClass('smf-route-option--hidden', !show);
            if (show) {
                $opt.removeAttr('hidden');
                $opt.find('input[type="radio"]').prop('disabled', false);
            } else {
                $opt.attr('hidden', 'hidden');
                $opt.find('input[type="radio"]').prop('disabled', true);
            }
        });

        for (var i = 0; i < localPriority.length; i++) {
            var key = localPriority[i];
            var $local = $box.find('.smf-route-option[data-smf-route="' + key + '"]:not(.smf-route-option--hidden)');
            if ($local.length) {
                recommended = key;
                break;
            }
        }

        var showQpay = recommended === 'qpay' || $box.find('.smf-route-option[data-smf-route="qpay"]:not(.smf-route-option--hidden)').length > 0;
        var text = (recommended !== 'card')
            ? (cfg.recommendedLocal || cfg.recommendedQatar || 'Based on your country, a local payment method is pre-selected. You can choose another method below.')
            : (cfg.recommendedCard || 'Based on your country, card payment is pre-selected. You can choose another method below.');

        $box.attr('data-country', country);
        $box.attr('data-recommended', recommended);
        $box.find('.smf-recommendation').text(text);

        var $card = $box.find('.smf-route-option[data-smf-route="card"]');
        if ($card.length) {
            var cardCaption = showQpay
                ? (captions.cardQatar || 'International · Credit Card')
                : (captions.cardOnly || 'Debit Card - Credit Card');
            $card.find('.smf-logo-caption').text(cardCaption);
            var cardHelp = showQpay
                ? (cfg.cardHelpQatar || 'For credit cards and international bank cards.')
                : (cfg.cardHelpOnly || 'Visa and Mastercard debit or credit cards.');
            $card.find('.smf-logo-help, .smf-route-content > small').first().text(cardHelp);
        }

        var manual = $box.data('manual-route');
        var $checked = $box.find('input[name="smf_route"]:checked:not(:disabled)');
        var checkedVal = $checked.length ? String($checked.val() || '') : '';
        var checkedHidden = $checked.closest('.smf-route-option').hasClass('smf-route-option--hidden');

        if (!manual || manual === $box.data('last-recommended') || checkedHidden || !checkedVal) {
            var $target = $box.find('input[name="smf_route"][value="' + recommended + '"]:not(:disabled)');
            if ($target.length) {
                $target.prop('checked', true);
            } else {
                $box.find('input[name="smf_route"][value="card"]:not(:disabled)').prop('checked', true);
            }
            $box.data('manual-route', null);
        }

        $box.data('last-recommended', recommended);
        $box.find('.smf-route-option').removeClass('is-recommended');
        $box.find('.smf-route-option').has('input[value="' + recommended + '"]').addClass('is-recommended');
        syncSelectedRoute();
        syncEmbeddedVisibility();
    }

    function syncSelectedRoute() {
        $('.smf-checkout-box .smf-route-option').each(function () {
            var $option = $(this);
            var checked = $option.find('input[type="radio"]').is(':checked');
            $option.toggleClass('is-selected', checked);
            $option.find('input[type="radio"]').attr('aria-checked', checked ? 'true' : 'false');
        });
    }

    function getSelectedRoute() {
        var $box = $('.smf-checkout-box');
        if (!$box.length) {
            return 'card';
        }

        var $checked = $box.find('input[name="smf_route"]:checked');
        if ($checked.length) {
            return String($checked.val() || 'card');
        }

        var $hidden = $box.find('input[name="smf_route"][type="hidden"]');
        if ($hidden.length) {
            var value = String($hidden.val() || 'smart');
            if (value === 'smart') {
                return String($box.data('recommended') || 'card');
            }
            return value;
        }

        return String($box.data('recommended') || 'card');
    }

    function syncEmbeddedVisibility() {
        var $wrap = $('#smf-embedded-wrap');
        if (!$wrap.length) {
            return;
        }

        var show = getSelectedRoute() === 'card';
        $wrap.toggle(show);
        if (show) {
            initCardView();
        }
    }

    function initCardView() {
        var cfg = labels();
        if (!cfg.embeddedEnabled || typeof window.myFatoorah === 'undefined') {
            return;
        }

        var $mount = $('#smf-cardview');
        if (!$mount.length) {
            return;
        }

        var sessionId = String($mount.data('session-id') || '');
        var countryCode = String($mount.data('country-code') || '');
        if (!sessionId || !countryCode) {
            return;
        }

        // Avoid re-init on the same mount node with same session.
        if (cardviewInited && $mount.data('smf-inited') === sessionId) {
            return;
        }

        var placeholders = cfg.placeholders || {};
        var styleCfg = cfg.cardViewStyle || {};
        var mfConfig = {
            countryCode: countryCode,
            sessionId: sessionId,
            cardViewId: 'smf-cardview',
            style: {
                hideCardIcons: false,
                cardHeight: styleCfg.cardHeight || 190,
                direction: cfg.direction || '',
                input: {
                    color: '#0f172a',
                    fontSize: styleCfg.fontSize || '14px',
                    fontFamily: 'inherit',
                    inputHeight: styleCfg.inputHeight || '40px',
                    inputMargin: styleCfg.inputMargin || '4px',
                    borderColor: 'rgba(15, 23, 42, 0.14)',
                    borderWidth: styleCfg.borderWidth || '1px',
                    borderRadius: styleCfg.borderRadius || '12px',
                    boxShadow: '0 1px 2px rgba(15, 23, 42, 0.04)',
                    backgroundColor: '#ffffff',
                    placeHolder: {
                        holderName: placeholders.holderName || 'Name On Card',
                        cardNumber: placeholders.cardNumber || 'Card number',
                        expiryDate: placeholders.expiryDate || 'MM / YY',
                        securityCode: placeholders.securityCode || 'CVV'
                    }
                },
                error: {
                    borderColor: '#dc2626',
                    borderRadius: styleCfg.borderRadius || '12px',
                    boxShadow: '0 0 0 3px rgba(220, 38, 38, 0.12)'
                }
            }
        };

        try {
            window.myFatoorah.init(mfConfig);
            if (!messageBound && typeof window.myFatoorah.recievedMessage === 'function') {
                window.addEventListener('message', window.myFatoorah.recievedMessage);
                messageBound = true;
            }
            $mount.data('smf-inited', sessionId);
            cardviewInited = true;
            $('#smf-embedded-wrap').attr('data-ready', '1');
        } catch (err) {
            cardviewInited = false;
            $('#smf-embedded-wrap').attr('data-ready', '0');
        }
    }

    function shouldUseEmbedded() {
        var cfg = labels();
        if (!cfg.embeddedEnabled) {
            return false;
        }
        if (!$('#payment_method_smart_myfatoorah').is(':checked')) {
            return false;
        }
        if (getSelectedRoute() !== 'card') {
            return false;
        }
        if (typeof window.myFatoorah === 'undefined' || typeof window.myFatoorah.submit !== 'function') {
            return false;
        }
        var $wrap = $('#smf-embedded-wrap');
        if (!$wrap.length || String($wrap.attr('data-ready')) !== '1') {
            return false;
        }
        if (!$('#smf-cardview').length) {
            return false;
        }
        return true;
    }

    function showEmbeddedError(message) {
        var text = message || labels().submitError || 'Please check your card details and try again.';
        var $notices = $('.woocommerce-notices-wrapper').first();
        if (!$notices.length) {
            $notices = $('form.checkout').prepend('<div class="woocommerce-notices-wrapper"></div>').find('.woocommerce-notices-wrapper').first();
        }
        $notices.html('<ul class="woocommerce-error" role="alert"><li>' + $('<div/>').text(text).html() + '</li></ul>');
        if ($notices.offset()) {
            $('html, body').animate({ scrollTop: $notices.offset().top - 80 }, 400);
        }
    }

    $(document.body).on('updated_checkout', function () {
        cardviewInited = false;
        embReady = false;
        updateRecommendation();
        syncSelectedRoute();
        syncEmbeddedVisibility();
    });
    $(document.body).on('change', '#billing_country', updateRecommendation);
    $(document.body).on('change', '.smf-checkout-box input[name="smf_route"]', function () {
        var $box = $(this).closest('.smf-checkout-box');
        $box.data('manual-route', $(this).val());
        syncSelectedRoute();
        syncEmbeddedVisibility();
    });
    $(document.body).on('change', 'input[name="payment_method"]', syncEmbeddedVisibility);
    $(document.body).on('click', '.smf-checkout-box .smf-route-option', function () {
        var $input = $(this).find('input[type="radio"]');
        if ($input.length && !$input.prop('checked')) {
            $input.prop('checked', true).trigger('change');
        } else {
            syncSelectedRoute();
            syncEmbeddedVisibility();
        }
    });

    $(document.body).on('checkout_place_order_smart_myfatoorah', function () {
        if (!shouldUseEmbedded()) {
            return true;
        }

        if (embReady) {
            embReady = false;
            return true;
        }

        var $form = $('form.checkout');
        var currency = String($('#smf-cardview').data('currency') || '');

        if ($form.data('blockUI') || typeof $form.block === 'function') {
            $form.block({
                message: null,
                overlayCSS: { background: '#fff', opacity: 0.6 }
            });
        }

        window.myFatoorah.submit(currency).then(
            function (response) {
                if (typeof $form.unblock === 'function') {
                    $form.unblock();
                }
                $form.find('input[name="mfData"]').remove();
                $form.append(
                    $('<input>', {
                        type: 'hidden',
                        name: 'mfData',
                        id: 'mfData',
                        value: response && response.sessionId ? response.sessionId : ''
                    })
                );
                embReady = true;
                $form.trigger('submit');
            },
            function (error) {
                if (typeof $form.unblock === 'function') {
                    $form.unblock();
                }
                showEmbeddedError(typeof error === 'string' ? error : (labels().submitError || ''));
            }
        );

        return false;
    });

    $(function () {
        updateRecommendation();
        syncSelectedRoute();
        syncEmbeddedVisibility();
    });
})(jQuery);
