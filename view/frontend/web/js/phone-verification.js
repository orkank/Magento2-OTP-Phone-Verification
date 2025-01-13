define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    return function (config) {
        if (!config.isOptional) {
            // Add form validation if verification is required
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

            $.ajax({
                url: config.sendOtpUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    phone: phone
                },
                success: function (response) {
                    console.log('Send OTP Response:', response);
                    if (response.success) {
                        $('#otp-section').show();
                        alert(response.message);
                    } else {
                        alert(response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Send OTP Error:', error);
                    alert('Error sending OTP. Please try again.');
                }
            });
        });

        $(document).on('click', '#verify-otp', function (e) {
            e.preventDefault();
            var otp = $('#otp-input').val();

            console.log('Sending OTP for verification:', otp);

            $.ajax({
                url: config.verifyOtpUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    otp: otp
                },
                success: function (response) {
                    console.log('Verify OTP Response:', response);
                    if (response.success) {
                        $('#phone-verified').val(1);
                        $('#otp-section').hide();
                        $('#phone-verification-status').text($t('Phone Verified')).addClass('verified').removeClass('not-verified');
                        alert(response.message);
                        // Refresh the page to show updated information
                        window.location.reload();
                    } else {
                        alert(response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Verify OTP Error:', error);
                    alert('Error verifying OTP. Please try again.');
                }
            });
        });

        $(document).on('click', '#skip-verification', function (e) {
            e.preventDefault();
            $('#otp-section').hide();
            $('#phone-verification-status').text($t('Not Verified')).addClass('not-verified').removeClass('verified');
        });
    };
});