/**
 * Mixin to add phoneVerified property to address model
 */
define(function () {
    'use strict';

    return function (target) {
        return function (addressData) {
            var address = target(addressData);
            
            // Only add phoneVerified for customer addresses (not quote addresses)
            // Customer addresses have customerAddressId, quote addresses don't
            if (address.customerAddressId && addressData.phone_verified !== undefined) {
                address.phoneVerified = addressData.phone_verified == 1 || addressData.phone_verified === true;
            } else {
                address.phoneVerified = false;
            }
            
            return address;
        };
    };
});
