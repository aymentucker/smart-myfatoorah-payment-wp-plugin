(function () {
    'use strict';

    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) {
        return;
    }

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var settings = (
        typeof window.wc.wcSettings.getPaymentMethodData === 'function'
            ? window.wc.wcSettings.getPaymentMethodData('smart_myfatoorah', {})
            : window.wc.wcSettings.getSetting('smart_myfatoorah_data', {})
    ) || {};
    var el = window.wp.element.createElement;
    var useEffect = window.wp.element.useEffect;
    var useState = window.wp.element.useState;
    var useRef = window.wp.element.useRef;
    var decodeEntities = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities
        ? window.wp.htmlEntities.decodeEntities
        : function (value) { return value; };

    var logos = settings.logos || {};
    var useLogos = settings.displayStyle === 'logos';
    var messageBound = false;

    function Label(props) {
        var PaymentMethodLabel = props.components.PaymentMethodLabel;
        return el(PaymentMethodLabel, { text: decodeEntities(settings.title || 'Secure payment') });
    }

    function RouteOption(props) {
        var isSelected = props.selected === props.value;
        var showLogo = props.useLogo && props.logoUrl;
        var className = 'smf-route-option smf-block-route-option' +
            (isSelected ? ' is-selected' : '') +
            (props.isRecommended ? ' is-recommended' : '') +
            (showLogo ? ' smf-route-option--logo' : '');

        function selectRoute(event) {
            if (event && event.preventDefault) {
                // Keep native radio behavior; just sync React state.
            }
            props.onChange(props.value);
        }

        var contentChildren;

        if (showLogo) {
            var caption = props.caption || '';
            contentChildren = [
                el('span', { key: 'row', className: 'smf-logo-row' },
                    el('span', { className: 'smf-logo-badge smf-logo-badge--' + props.value },
                        el('img', {
                            className: 'smf-logo-img',
                            src: props.logoUrl,
                            alt: props.title,
                            loading: 'eager',
                            decoding: 'async',
                            width: 120,
                            height: 40
                        })
                    ),
                    props.isRecommended
                        ? el('span', { className: 'smf-pill' }, decodeEntities(settings.recommendedPill || 'Recommended'))
                        : null
                ),
                caption ? el('strong', { key: 'caption', className: 'smf-logo-caption' }, caption) : null,
                props.help ? el('small', { key: 'help', className: 'smf-logo-help' }, props.help) : null,
                el('span', { key: 'sr', className: 'screen-reader-text' }, props.title)
            ];
        } else {
            contentChildren = [
                el('span', { key: 'row', className: 'smf-label-row' },
                    el('strong', null, props.title),
                    props.isRecommended
                        ? el('span', { className: 'smf-pill' }, decodeEntities(settings.recommendedPill || 'Recommended'))
                        : null
                ),
                props.help ? el('small', { key: 'help' }, props.help) : null
            ];
        }

        return el(
            'label',
            {
                className: className,
                onClick: function () { props.onChange(props.value); }
            },
            el('span', { className: 'smf-radio-wrap' },
                el('input', {
                    className: 'smf-radio-input',
                    type: 'radio',
                    name: 'smf_blocks_route',
                    value: props.value,
                    checked: isSelected,
                    onChange: selectRoute
                }),
                el('span', { className: 'smf-radio', 'aria-hidden': 'true' })
            ),
            el('span', { className: 'smf-route-content' + (showLogo ? ' smf-route-content--logo' : '') }, contentChildren)
        );
    }

    function initCardView(session) {
        if (!settings.embeddedEnabled || !session || !session.session_id || !session.country_code) {
            return false;
        }
        if (typeof window.myFatoorah === 'undefined' || typeof window.myFatoorah.init !== 'function') {
            return false;
        }

        var placeholders = settings.placeholders || {};
        var styleCfg = settings.cardViewStyle || {};
        try {
            window.myFatoorah.init({
                countryCode: session.country_code,
                sessionId: session.session_id,
                cardViewId: 'smf-cardview-blocks',
                style: {
                    hideCardIcons: false,
                    cardHeight: styleCfg.cardHeight || 190,
                    direction: settings.direction || '',
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
            });
            if (!messageBound && typeof window.myFatoorah.recievedMessage === 'function') {
                window.addEventListener('message', window.myFatoorah.recievedMessage);
                messageBound = true;
            }
            return true;
        } catch (err) {
            return false;
        }
    }

    function refreshEmbeddedSession(currentSession) {
        if (!settings.ajaxUrl || !settings.checkoutNonce) {
            return Promise.resolve(currentSession || null);
        }

        var body = new window.FormData();
        body.append('action', 'smf_initiate_session');
        body.append('nonce', settings.checkoutNonce);

        return window.fetch(settings.ajaxUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (json && json.success && json.data && json.data.session_id) {
                return json.data;
            }
            throw new Error(
                (json && json.data && json.data.message)
                    ? json.data.message
                    : 'Unable to refresh embedded session.'
            );
        });
    }

    function Content(props) {
        var eventRegistration = props.eventRegistration || {};
        var emitResponse = props.emitResponse || {};
        var billing = props.billing || {};
        var billingAddress = billing.billingAddress || {};
        var country = (billingAddress.country || '').toUpperCase();
        var localMethods = Array.isArray(settings.localMethods) ? settings.localMethods : [];
        var showQpay = false;
        var recommended = 'card';
        var priority = ['qpay', 'knet', 'benefit', 'mada', 'stc_pay', 'meeza'];
        var visibleLocals = localMethods.filter(function (row) {
            var list = (row.countries || []).map(function (c) { return String(c || '').toUpperCase(); });
            return list.indexOf(country) !== -1;
        });
        for (var pi = 0; pi < priority.length; pi++) {
            var hit = visibleLocals.filter(function (row) { return row.route === priority[pi]; })[0];
            if (hit) {
                recommended = hit.route;
                break;
            }
        }
        showQpay = visibleLocals.some(function (row) { return row.route === 'qpay'; });
        var state = useState(recommended);
        var route = state[0];
        var setRoute = state[1];
        var onPaymentProcessing = eventRegistration.onPaymentProcessing || eventRegistration.onPaymentSetup;
        var sessionRef = useRef(settings.embeddedSession || null);
        var cardReadyRef = useRef(false);

        useEffect(function () {
            setRoute(recommended);
        }, [recommended]);

        useEffect(function () {
            if (!settings.embeddedEnabled || route !== 'card') {
                cardReadyRef.current = false;
                return function () {};
            }

            var cancelled = false;
            refreshEmbeddedSession(sessionRef.current).then(function (freshSession) {
                if (cancelled || !freshSession) {
                    return;
                }
                sessionRef.current = freshSession;
                window.setTimeout(function () {
                    if (!cancelled) {
                        cardReadyRef.current = initCardView(freshSession);
                    }
                }, 50);
            }).catch(function () {
                window.setTimeout(function () {
                    if (!cancelled) {
                        cardReadyRef.current = initCardView(sessionRef.current);
                    }
                }, 50);
            });

            return function () {
                cancelled = true;
            };
        }, [route]);

        useEffect(function () {
            if (!onPaymentProcessing) {
                return function () {};
            }

            var unsubscribe = onPaymentProcessing(function () {
                var successType = emitResponse.responseTypes.SUCCESS;
                var errorType = emitResponse.responseTypes.ERROR;

                if (route !== 'card' || !settings.embeddedEnabled) {
                    return {
                        type: successType,
                        meta: {
                            paymentMethodData: {
                                smf_route: route
                            }
                        }
                    };
                }

                if (typeof window.myFatoorah === 'undefined' || typeof window.myFatoorah.submit !== 'function' || !sessionRef.current) {
                    return {
                        type: successType,
                        meta: {
                            paymentMethodData: {
                                smf_route: 'card'
                            }
                        }
                    };
                }

                return window.myFatoorah.submit(settings.currency || '').then(
                    function (response) {
                        return {
                            type: successType,
                            meta: {
                                paymentMethodData: {
                                    smf_route: 'card',
                                    mfData: response && response.sessionId ? response.sessionId : ''
                                }
                            }
                        };
                    },
                    function (error) {
                        return {
                            type: errorType,
                            message: typeof error === 'string'
                                ? error
                                : decodeEntities(settings.submitError || 'Please check your card details and try again.')
                        };
                    }
                );
            });

            return typeof unsubscribe === 'function' ? unsubscribe : function () {};
        }, [onPaymentProcessing, emitResponse.responseTypes, route]);

        var children = [];
        var session = sessionRef.current;

        if (settings.description) {
            children.push(el('p', { key: 'description', className: 'smf-description' }, decodeEntities(settings.description)));
        }

        children.push(
            el('div', { key: 'recommendation', className: 'smf-recommendation' },
                recommended !== 'card'
                    ? decodeEntities(settings.recommendedLocal || settings.recommendedQatar || 'Based on your country, a local payment method is pre-selected. You can choose another method below.')
                    : decodeEntities(settings.recommendedCard || 'Based on your country, card payment is pre-selected. You can choose another method below.')
            )
        );

        if (settings.allowManualOverride) {
            var captions = settings.logoCaptions || {};
            var routes = [];

            visibleLocals.forEach(function (row) {
                routes.push(el(RouteOption, {
                    key: row.route,
                    value: row.route,
                    selected: route,
                    onChange: setRoute,
                    useLogo: useLogos,
                    logoUrl: row.logo || logos[row.route] || '',
                    caption: decodeEntities(row.caption || captions[row.route] || row.label || ''),
                    isRecommended: recommended === row.route,
                    title: decodeEntities(row.label || row.route),
                    help: decodeEntities(row.help || '')
                }));
            });

            routes.push(el(RouteOption, {
                key: 'card', value: 'card', selected: route, onChange: setRoute, useLogo: useLogos,
                logoUrl: logos.card || '',
                caption: decodeEntities(
                    showQpay
                        ? (captions.cardQatar || captions.card || 'International · Credit Card')
                        : (captions.cardOnly || 'Debit Card - Credit Card')
                ),
                isRecommended: recommended === 'card',
                title: decodeEntities(settings.cardLabel || 'Visa / Mastercard'),
                help: decodeEntities(
                    showQpay
                        ? (settings.cardHelpQatar || settings.cardHelp || 'For credit cards and international bank cards.')
                        : (settings.cardHelpOnly || 'Visa and Mastercard debit or credit cards.')
                )
            }));

            if (settings.applePayAvailable) {
                routes.push(el(RouteOption, {
                    key: 'apple', value: 'apple_pay', selected: route, onChange: setRoute, useLogo: useLogos,
                    logoUrl: logos.apple_pay || '',
                    caption: decodeEntities(captions.apple_pay || settings.applePayLabel || 'Apple Pay'),
                    isRecommended: false,
                    title: decodeEntities(settings.applePayLabel || 'Apple Pay')
                }));
            }
            if (settings.googlePayAvailable) {
                routes.push(el(RouteOption, {
                    key: 'google', value: 'google_pay', selected: route, onChange: setRoute, useLogo: useLogos,
                    logoUrl: logos.google_pay || '',
                    caption: decodeEntities(captions.google_pay || settings.googlePayLabel || 'Google Pay'),
                    isRecommended: false,
                    title: decodeEntities(settings.googlePayLabel || 'Google Pay')
                }));
            }

            children.push(el('div', {
                key: 'routes',
                className: 'smf-routes',
                role: 'radiogroup',
                'aria-label': decodeEntities(settings.routesAriaLabel || 'Payment method')
            }, routes));
        }

        if (settings.embeddedEnabled && route === 'card') {
            if (session && session.session_id) {
                children.push(
                    el('div', { key: 'embedded', className: 'smf-embedded-wrap' },
                        el('h4', { className: 'smf-embedded-title' }, decodeEntities(settings.embeddedHint || 'Card details')),
                        el('div', { id: 'smf-cardview-blocks', className: 'smf-cardview' })
                    )
                );
            } else {
                children.push(
                    el('p', { key: 'embedded-fallback', className: 'smf-embedded-fallback' },
                        decodeEntities(settings.embeddedUnavailable || '')
                    )
                );
            }
        }

        return el(
            'div',
            {
                className: 'smf-checkout-box smf-blocks-box'
                    + (settings.displayClasses ? (' ' + settings.displayClasses) : (useLogos ? ' smf-checkout-box--logos' : ' smf-checkout-box--text'))
                    + (settings.customStyle ? ' smf-has-custom-style' : ''),
                'data-display': useLogos ? 'logos' : 'text',
                'data-recommended': recommended,
                'data-cols': settings.routeColumns || '2',
                'data-logo-layout': settings.logoLayout || 'cards',
                'data-text-layout': settings.textLayout || 'list',
                style: settings.customStyle && settings.styleVars ? settings.styleVars : undefined
            },
            children
        );
    }

    registerPaymentMethod({
        name: 'smart_myfatoorah',
        label: el(Label),
        content: el(Content),
        edit: el('div', { className: 'smf-checkout-box' }, decodeEntities(settings.description || 'Smart MyFatoorah payment routing')),
        canMakePayment: function () { return true; },
        ariaLabel: decodeEntities(settings.title || 'Secure payment'),
        supports: {
            features: settings.supports || ['products']
        }
    });
})();
