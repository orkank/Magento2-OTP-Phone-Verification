define([
    'jquery',
    'mage/translate',
    'mage/url',
    'Magento_Checkout/js/model/quote',
    'Magento_Ui/js/modal/modal'
], function ($, $t, urlBuilder, quote, modal) {
    'use strict';

    /**
     * Remove any phone verification keys from address objects before they get serialized
     * into /rest/* checkout endpoints. Otherwise WebAPI tries to map them onto
     * Magento\Quote\Api\Data\AddressInterface and crashes.
     */
    function stripPhoneVerifiedKeys(obj) {
        if (!obj || typeof obj !== 'object') {
            return;
        }

        // direct keys
        if (Object.prototype.hasOwnProperty.call(obj, 'phone_verified')) {
            delete obj.phone_verified;
        }
        if (Object.prototype.hasOwnProperty.call(obj, 'phoneVerified')) {
            delete obj.phoneVerified;
        }
        if (Object.prototype.hasOwnProperty.call(obj, 'PhoneVerified')) {
            delete obj.PhoneVerified;
        }

        // common nested containers used by Magento payloads
        if (obj.extension_attributes && typeof obj.extension_attributes === 'object') {
            stripPhoneVerifiedKeys(obj.extension_attributes);
        }
        if (obj.extensionAttributes && typeof obj.extensionAttributes === 'object') {
            stripPhoneVerifiedKeys(obj.extensionAttributes);
        }
        if (obj.custom_attributes && typeof obj.custom_attributes === 'object') {
            stripPhoneVerifiedKeys(obj.custom_attributes);
        }
        if (obj.customAttributes && typeof obj.customAttributes === 'object') {
            stripPhoneVerifiedKeys(obj.customAttributes);
        }
    }

    function getResponseMessage(response) {
        if (!response) {
            return '';
        }
        if (response.responseJSON && response.responseJSON.message) {
            return String(response.responseJSON.message);
        }
        if (response.responseText) {
            try {
                var parsed = JSON.parse(response.responseText);
                if (parsed && parsed.message) {
                    return String(parsed.message);
                }
            } catch (e) {
                // ignore
            }
            return String(response.responseText);
        }
        return '';
    }

    function isPhoneVerificationErrorMessage(message) {
        var msg = (message || '').toLowerCase();
        if (!msg) {
            return false;
        }

        // EN/TR heuristics (we can’t rely on exact translation text)
        var hasVerify =
            msg.indexOf('verification') !== -1 ||
            msg.indexOf('verify') !== -1 ||
            msg.indexOf('doğrula') !== -1 ||
            msg.indexOf('doğrulama') !== -1 ||
            msg.indexOf('dogrula') !== -1 ||
            msg.indexOf('dogrulama') !== -1;

        var hasPhone =
            msg.indexOf('phone') !== -1 ||
            msg.indexOf('telefon') !== -1;

        return hasVerify && hasPhone;
    }

    function guessAddressTypeFromMessage(message) {
        var msg = (message || '').toLowerCase();
        if (msg.indexOf('billing') !== -1 || msg.indexOf('fatura') !== -1) {
            return 'billing';
        }
        return 'shipping';
    }

    var modalInitialized = false;
    var otpModalInstance = null;
    var verificationInProgress = false;

    function ensureCheckoutModalDom() {
        var $modal = $('#checkout-otp-modal');
        if ($modal.length) {
            return $modal;
        }

        $('body').append(
            '<div id="checkout-otp-modal" style="display:none;">' +
                '<div class="idg-otp-modal">' +
                    '<p class="idg-otp-modal__desc">' + $t('Phone Verification Required') + '</p>' +
                    '<div class="idg-otp-modal__error" id="checkout-otp-error" style="display:none;"></div>' +
                    '<div class="idg-otp-modal__grid">' +
                        '<div class="idg-otp-modal__field">' +
                            '<label>' + $t('Phone Number') + '</label>' +
                            '<input type="text" id="checkout-otp-phone" readonly="readonly" />' +
                        '</div>' +
                        '<div class="idg-otp-modal__field">' +
                            '<label>' + $t('Enter OTP Code') + '</label>' +
                            '<input type="text" id="checkout-otp-input" maxlength="6" placeholder="' + $t('Enter 6-digit OTP code') + '" />' +
                            '<div id="checkout-otp-timer" class="idg-otp-modal__timer" style="display:none;"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="idg-otp-modal__actions">' +
                        '<button type="button" class="action primary" id="checkout-send-otp">' + $t('Send OTP') + '</button>' +
                        '<button type="button" class="action primary" id="checkout-verify-otp" style="display:none;">' + $t('Verify OTP') + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            // hidden helper flag (optional)
            '<input type="hidden" id="checkout-address-phone-verified" value="0" />'
        );

        return $('#checkout-otp-modal');
    }

    function initCheckoutModal() {
        if (modalInitialized) {
            return;
        }
        var $modal = ensureCheckoutModalDom();

        otpModalInstance = modal({
            type: 'popup',
            responsive: true,
            innerScroll: true,
            title: $t('Phone Verification Required'),
            buttons: [],
            modalClass: 'checkout-otp-modal'
        }, $modal);

        modalInitialized = true;
    }

    function openOtpModalAndVerify(phone, addressType, customerAddressId) {
        var deferred = $.Deferred();
        initCheckoutModal();

        var EXPIRY_TIME = 3 * 60 * 1000; // 3 minutes
        var timerInterval = null;

        var $phone = $('#checkout-otp-phone');
        var $otpInput = $('#checkout-otp-input');
        var $sendBtn = $('#checkout-send-otp');
        var $verifyBtn = $('#checkout-verify-otp');
        var $error = $('#checkout-otp-error');
        var $timer = $('#checkout-otp-timer');

        function showError(msg) {
            $error.text(msg).show();
            setTimeout(function () {
                $error.hide();
            }, 6000);
        }

        function resetUi() {
            $phone.val(phone || '');
            $otpInput.val('').prop('disabled', true);
            $error.hide();
            $timer.hide();
            $sendBtn.show().prop('disabled', false).text($t('Send OTP'));
            $verifyBtn.hide().prop('disabled', false).text($t('Verify OTP'));
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
        }

        function startTimer() {
            var timeLeft = EXPIRY_TIME / 1000;
            $timer.show();
            timerInterval = setInterval(function () {
                timeLeft--;
                var minutes = Math.floor(timeLeft / 60);
                var seconds = timeLeft % 60;
                $timer.text($t('Time remaining: ') + minutes + ':' + (seconds < 10 ? '0' : '') + seconds);
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                    $timer.hide();
                    $verifyBtn.hide();
                    $sendBtn.show().prop('disabled', false).text($t('Send OTP'));
                    showError($t('OTP has expired. Please request a new one.'));
                }
            }, 1000);
        }

        function sendOtp() {
            var phoneTrimmed = String(phone || '').replace(/\D/g, '');
            if (!phoneTrimmed || phoneTrimmed.length < 10) {
                showError($t('Please enter a valid phone number'));
                return;
            }

            $sendBtn.prop('disabled', true).text($t('Sending OTP...'));
            $otpInput.prop('disabled', true);

            $.ajax({
                url: urlBuilder.build('phoneotp/otp/send'),
                type: 'POST',
                dataType: 'json',
                // Checkout address verification: allow phone shared across customers
                data: { phone: phone, context: 'checkout' },
                success: function (response) {
                    if (response && response.success) {
                        $sendBtn.hide();
                        $verifyBtn.show().prop('disabled', false);
                        $otpInput.prop('disabled', false).focus();
                        startTimer();
                    } else {
                        showError((response && response.message) ? response.message : $t('Error sending OTP. Please try again.'));
                        $sendBtn.prop('disabled', false).text($t('Send OTP'));
                        $otpInput.prop('disabled', false);
                    }
                },
                error: function () {
                    showError($t('Error sending OTP. Please try again.'));
                    $sendBtn.prop('disabled', false).text($t('Send OTP'));
                    $otpInput.prop('disabled', false);
                }
            });
        }

        function verifyOtp() {
            var otp = String($otpInput.val() || '');
            if (!otp || otp.length !== 6) {
                showError($t('Please enter a valid 6-digit OTP code'));
                return;
            }

            $verifyBtn.prop('disabled', true).text($t('Verifying...'));
            $otpInput.prop('disabled', true);

            $.ajax({
                url: urlBuilder.build('phoneotp/otp/verify'),
                type: 'POST',
                dataType: 'json',
                data: {
                    otp: otp,
                    address_phone_verified: 1,
                    phone: phone,
                    address_type: addressType,
                    customer_address_id: customerAddressId || ''
                },
                success: function (response) {
                    if (response && response.success) {
                        $('#checkout-address-phone-verified').val('1');
                        if (otpModalInstance) {
                            otpModalInstance.closeModal();
                        }
                        deferred.resolve();
                    } else {
                        showError((response && response.message) ? response.message : $t('Error verifying OTP. Please try again.'));
                        $verifyBtn.prop('disabled', false).text($t('Verify OTP'));
                        $otpInput.prop('disabled', false);
                    }
                },
                error: function () {
                    showError($t('Error verifying OTP. Please try again.'));
                    $verifyBtn.prop('disabled', false).text($t('Verify OTP'));
                    $otpInput.prop('disabled', false);
                }
            });
        }

        // Bind click handlers (namespaced, so we don’t duplicate)
        $sendBtn.off('click.checkoutOtp').on('click.checkoutOtp', function () {
            sendOtp();
        });
        $verifyBtn.off('click.checkoutOtp').on('click.checkoutOtp', function () {
            verifyOtp();
        });

        resetUi();
        if (otpModalInstance) {
            otpModalInstance.openModal();
            // auto send OTP when opened
            setTimeout(function () {
                if ($sendBtn.is(':visible') && !$sendBtn.prop('disabled')) {
                    sendOtp();
                }
            }, 250);
        } else {
            deferred.reject(new Error('Modal init failed'));
        }

        return deferred.promise();
    }

    return function (target) {
        // Support both Magento core and custom processors (plain object)
        var original = target.saveShippingInformation;
        if (typeof original !== 'function') {
            return target;
        }

        target.saveShippingInformation = function () {
            var self = this;

            // Always strip before payload serialization
            try {
                stripPhoneVerifiedKeys(quote.shippingAddress());
                stripPhoneVerifiedKeys(quote.billingAddress());
            } catch (e) {
                // ignore
            }

            var first = original.apply(self, arguments);

            // If processor does not return a promise, nothing we can do
            if (!first || typeof first.fail !== 'function' || typeof first.done !== 'function') {
                return first;
            }

            var deferred = $.Deferred();

            first.done(function () {
                deferred.resolve.apply(deferred, arguments);
            }).fail(function (response) {
                var message = getResponseMessage(response);

                if (verificationInProgress || !isPhoneVerificationErrorMessage(message)) {
                    deferred.reject.apply(deferred, arguments);
                    return;
                }

                verificationInProgress = true;

                var addressType = guessAddressTypeFromMessage(message);
                var addr = (addressType === 'billing') ? quote.billingAddress() : quote.shippingAddress();
                var phone = (addr && addr.telephone) ? addr.telephone : '';
                var customerAddressId = (addr && addr.customerAddressId) ? addr.customerAddressId : 0;

                openOtpModalAndVerify(phone, addressType, customerAddressId)
                    .done(function () {
                        // retry after successful OTP verification
                        try {
                            stripPhoneVerifiedKeys(quote.shippingAddress());
                            stripPhoneVerifiedKeys(quote.billingAddress());
                        } catch (e2) {
                            // ignore
                        }

                        var second = original.apply(self, arguments);
                        if (second && typeof second.done === 'function' && typeof second.fail === 'function') {
                            second.done(function () {
                                deferred.resolve.apply(deferred, arguments);
                            }).fail(function () {
                                deferred.reject.apply(deferred, arguments);
                            }).always(function () {
                                verificationInProgress = false;
                            });
                        } else {
                            verificationInProgress = false;
                            deferred.resolve();
                        }
                    })
                    .fail(function () {
                        verificationInProgress = false;
                        deferred.reject.apply(deferred, arguments);
                    });
            });

            return deferred.promise();
        };

        return target;
    };
});
