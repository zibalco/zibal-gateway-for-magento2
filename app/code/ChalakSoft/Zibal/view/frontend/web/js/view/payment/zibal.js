define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';

        rendererList.push(
            {
                type: 'zibal',
                component: 'ChalakSoft_Zibal/js/view/payment/method-renderer/zibal-method'
            }
        );

        /** Add view logic here if needed */
        return Component.extend({
            defaults: {
                redirectAfterPlaceOrder: true
            },

            afterPlaceOrder: function (data, event) {
                window.location.replace('zibal/index/index');

            }
        });
    }
);
