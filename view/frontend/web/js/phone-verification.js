define([
    'jquery',
    'Magento_Ui/js/modal/modal'
], function ($, modal) {
    'use strict';

    return function (config) {
        const EXPIRY_TIME = 3 * 60 * 1000; // 3 minutes in milliseconds
        const texts = config.translations;
        let formSubmitEvent = null;

        // Initialize modal for registration flow
        if (!config.isLoggedIn) {
            const modalElement = $('#otp-modal');
            const modalOptions = {
                type: 'popup',
                responsive: true,
                innerScroll: true,
                title: texts.enterOtp,
                buttons: []
            };
            const otpModal = modal(modalOptions, modalElement);
        }

        // Logged-in user flow
        if (config.isLoggedIn) {
            $(document).on('click', '#send-otp', function (e) {
                e.preventDefault();
                var phone = $('#phone').val();
                var $sendButton = $(this);
                var originalText = $sendButton.text();

                if (phone.replace(/\D/g, '').length < 10) {
                    alert(texts.invalidPhone);
                    return false;
                }

                // Disable button and show loading state
                $sendButton.prop('disabled', true).text(texts.sendingOtp || 'Sending...');
                $('#phone').prop('disabled', true);

                $.ajax({
                    url: config.sendOtpUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: { phone: phone },
                    success: function(response) {
                        if (response.success) {
                            $('#otp-section').show();
                            startExpiryTimer();
                            alert(response.message);
                            // Keep button disabled until OTP is verified or expired
                            $sendButton.text(texts.otpSent || 'OTP Sent');
                        } else {
                            alert(response.message);
                            // Re-enable on error
                            $sendButton.prop('disabled', false).text(originalText);
                            $('#phone').prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert(texts.errorSendingOtp);
                        // Re-enable on error
                        $sendButton.prop('disabled', false).text(originalText);
                        $('#phone').prop('disabled', false);
                    }
                });
            });
        }
        // Registration flow
        else {
            let skipVerification = false;

            // Handle registration form submit
            $('form.form-create-account').on('submit', function(e) {
                const phone = $('#phone').val();
                const isVerified = $('#phone-verified').val() === '1';

                // Skip validation if skip was clicked or phone is empty
                if (skipVerification || !phone) {
                    return true;
                }

                // If phone is not empty and not verified
                if (!isVerified) {
                    e.preventDefault();
                    formSubmitEvent = e;

                    // Validate phone number
                    if (phone.replace(/\D/g, '').length < 10) {
                        alert(texts.invalidPhone);
                        return false;
                    }

                    // First validate phone availability
                    $.ajax({
                        url: config.validatePhoneUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: { phone: phone },
                        success: function(response) {
                            if (response.success) {
                                sendOtp(phone);
                            } else {
                                alert(response.message);
                            }
                        },
                        error: function() {
                            alert(texts.errorValidating);
                        }
                    });

                    return false;
                }
                return true;
            });

            function sendOtp(phone) {
                // Disable the form during OTP sending
                $('form.form-create-account input, form.form-create-account button').prop('disabled', true);

                // Show loading state on submit button
                var $submitButton = $('form.form-create-account button[type="submit"]');
                var originalSubmitText = $submitButton.text();
                $submitButton.text(texts.sendingOtp || 'Sending OTP...');

                $.ajax({
                    url: config.sendOtpUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: { phone: phone },
                    success: function(response) {
                        if (response.success) {
                            $('#otp-modal').modal('openModal');
                            startExpiryTimer();
                            // Re-enable form except phone field
                            $('form.form-create-account input, form.form-create-account button').prop('disabled', false);
                            $('#phone').prop('disabled', true); // Keep phone disabled
                            $submitButton.text(originalSubmitText);
                        } else {
                            alert(response.message);
                            // Re-enable form on error
                            $('form.form-create-account input, form.form-create-account button').prop('disabled', false);
                            $submitButton.text(originalSubmitText);
                        }
                    },
                    error: function() {
                        alert(texts.errorSendingOtp);
                        // Re-enable form on error
                        $('form.form-create-account input, form.form-create-account button').prop('disabled', false);
                        $submitButton.text(originalSubmitText);
                    }
                });
            }

            // Skip verification button (only for optional verification)
            $(document).on('click', '#skip-verification', function() {
                if (config.isOptional) {
                    skipVerification = true;
                    $('#otp-modal').modal('closeModal');
                    if (formSubmitEvent && formSubmitEvent.target) {
                        $(formSubmitEvent.target).submit();
                    }
                }
            });

            // Reset skip flag when modal is closed
            $('#otp-modal').on('modalclosed', function() {
                const timer = $(this).data('timer');
                if (timer) {
                    clearInterval(timer);
                }
                skipVerification = false;
            });
        }

        // Common functionality for both flows
        $(document).on('click', '#verify-otp', function() {
            const otp = $('#otp-input').val();
            const $verifyButton = $(this);
            const originalText = $verifyButton.text();

            // Disable verify button and OTP input during verification
            $verifyButton.prop('disabled', true).text(texts.verifyingOtp || 'Verifying...');
            $('#otp-input').prop('disabled', true);

            $.ajax({
                url: config.verifyOtpUrl,
                type: 'POST',
                dataType: 'json',
                data: { otp: otp },
                success: function(response) {
                    if (response.success) {
                        $('#phone-verified').val(1);
                        $('#phone').prop('readonly', true);
                        $('#phone-verification-status')
                            .text(texts.phoneVerified)
                            .addClass('verified')
                            .removeClass('not-verified');

                        if (config.isLoggedIn) {
                            $('#otp-section').hide();
                            // Re-enable send OTP button for logged-in users
                            $('#send-otp').prop('disabled', false).text(texts.sendOtp || 'Send OTP');
                        } else {
                            $('#otp-modal').modal('closeModal');
                            // Submit the form if it was prevented
                            if (formSubmitEvent && formSubmitEvent.target) {
                                $(formSubmitEvent.target).submit();
                            }
                        }
                    } else {
                        alert(response.message);
                        // Re-enable on error
                        $verifyButton.prop('disabled', false).text(originalText);
                        $('#otp-input').prop('disabled', false);
                    }
                },
                error: function() {
                    alert(texts.errorVerifyingOtp);
                    // Re-enable on error
                    $verifyButton.prop('disabled', false).text(originalText);
                    $('#otp-input').prop('disabled', false);
                }
            });
        });

        function startExpiryTimer() {
            let timeLeft = EXPIRY_TIME / 1000;
            const timerElement = $('#otp-timer');
            timerElement.show();

            const timer = setInterval(function() {
                timeLeft--;
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerElement.text(texts.timeRemaining +
                    minutes + ':' + (seconds < 10 ? '0' : '') + seconds);

                if (timeLeft <= 0) {
                    clearInterval(timer);
                    // Only reset verification if phone is not already verified
                    if ($('#phone-verified').val() !== '1') {
                        if (config.isLoggedIn) {
                            $('#otp-section').hide();
                            // Re-enable send OTP button
                            $('#send-otp').prop('disabled', false).text(texts.sendOtp || 'Send OTP');
                        } else {
                            $('#otp-modal').modal('closeModal');
                            // Re-enable form elements
                            $('form.form-create-account input, form.form-create-account button').prop('disabled', false);
                        }
                        $('#phone').prop('readonly', false).prop('disabled', false);
                        $('#phone-verified').val(0);
                        alert(texts.otpExpired);
                    }
                }
            }, 1000);

            if (config.isLoggedIn) {
                $('#otp-section').data('timer', timer);
            } else {
                $('#otp-modal').data('timer', timer);
            }
        }
    };
});