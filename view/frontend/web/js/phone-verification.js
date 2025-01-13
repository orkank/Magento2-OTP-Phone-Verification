define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    return function (config) {
        // Load saved phone from localStorage if exists (for registration only)
        if (!config.isLoggedIn) {
            var savedPhone = localStorage.getItem('registration_phone');
            var savedVerified = localStorage.getItem('registration_phone_verified');

            if (savedPhone) {
                $('#phone').val(savedPhone);
                if (savedVerified === '1') {
                    $('#phone').prop('readonly', true);
                    $('#send-otp').prop('disabled', true);
                    $('#phone-verified').val(1);
                    $('#phone-verification-status').text($t('Phone Verified')).addClass('verified').removeClass('not-verified');
                }
            }
        }

        if (!config.isOptional) {
            $('form.form-create-account').on('submit', function(e) {
                if ($('#phone-verified').val() !== '1') {
                    e.preventDefault();
                    alert($t('Please verify your phone number before submitting.'));
                    return false;
                }
                return true;
            });
        }

        $(document).on('click', '#send-otp', function (e) {
            e.preventDefault();
            var phone = $('#phone').val();

            // Store phone in localStorage for registration
            if (!config.isLoggedIn) {
                localStorage.setItem('registration_phone', phone);
            }

            $.ajax({
                url: config.sendOtpUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    phone: phone
                },
                success: function (response) {
                    if (response.success) {
                        $('#otp-section').show();
                        alert(response.message);
                    } else {
                        alert(response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Send OTP Error:', error);
                    alert($t('Error sending OTP. Please try again.'));
                }
            });
        });

        $(document).on('click', '#verify-otp', function (e) {
            e.preventDefault();
            var otp = $('#otp-input').val();

            $.ajax({
                url: config.verifyOtpUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    otp: otp
                },
                success: function (response) {
                    if (response.success) {
                        $('#phone-verified').val(1);
                        $('#otp-section').hide();
                        $('#phone').prop('readonly', true);
                        $('#send-otp').prop('disabled', true);
                        $('#phone-verification-status').text($t('Phone Verified')).addClass('verified').removeClass('not-verified');

                        // Store verification status for registration
                        if (!config.isLoggedIn) {
                            localStorage.setItem('registration_phone_verified', '1');
                        }

                        alert(response.message);
                    } else {
                        alert(response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Verify OTP Error:', error);
                    alert($t('Error verifying OTP. Please try again.'));
                }
            });
        });

        // Clear localStorage after successful registration
        $('form.form-create-account').on('submit', function() {
            if ($('#phone-verified').val() === '1') {
                setTimeout(function() {
                    localStorage.removeItem('registration_phone');
                    localStorage.removeItem('registration_phone_verified');
                }, 1000);
            }
        });
    };
});