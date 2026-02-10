var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/model/shipping-save-processor/default': {
                'IDangerous_PhoneOtpVerification/js/checkout/shipping-phone-verification-mixin': true
            },
            'Grinet_PaymentCore/js/model/shipping-save-processor/default': {
                'IDangerous_PhoneOtpVerification/js/checkout/shipping-phone-verification-mixin': true
            },
            'Magento_Customer/js/model/customer/address': {
                'IDangerous_PhoneOtpVerification/js/model/customer/address-mixin': true
            }
        }
    }
};
