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

                if (phone.replace(/\D/g, '').length < 10) {
                    alert(texts.invalidPhone);
                    return false;
                }

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
                        } else {
                            alert(response.message);
                        }
                    },
                    error: function() {
                        alert(texts.errorSendingOtp);
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
                $.ajax({
                    url: config.sendOtpUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: { phone: phone },
                    success: function(response) {
                        if (response.success) {
                            $('#otp-modal').modal('openModal');
                            startExpiryTimer();
                        } else {
                            alert(response.message);
                        }
                    },
                    error: function() {
                        alert(texts.errorSendingOtp);
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
                        } else {
                            $('#otp-modal').modal('closeModal');
                            // Submit the form if it was prevented
                            if (formSubmitEvent && formSubmitEvent.target) {
                                $(formSubmitEvent.target).submit();
                            }
                        }
                    } else {
                        alert(response.message);
                    }
                },
                error: function() {
                    alert(texts.errorVerifyingOtp);
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
                    if (config.isLoggedIn) {
                        $('#otp-section').hide();
                    } else {
                        $('#otp-modal').modal('closeModal');
                    }
                    $('#phone').prop('readonly', false);
                    $('#phone-verified').val(0);
                    alert(texts.otpExpired);
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