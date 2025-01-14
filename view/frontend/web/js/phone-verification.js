define([
    'jquery'
], function ($) {
    'use strict';

    return function (config) {
        const EXPIRY_TIME = 3 * 60 * 1000; // 3 minutes in milliseconds
        const texts = config.translations;

        function setStorageWithExpiry(key, value) {
            const item = {
                value: value,
                timestamp: new Date().getTime(),
                expiry: EXPIRY_TIME
            };
            localStorage.setItem(key, JSON.stringify(item));
        }

        function getStorageWithExpiry(key) {
            const itemStr = localStorage.getItem(key);
            if (!itemStr) return null;

            const item = JSON.parse(itemStr);
            const now = new Date().getTime();

            // Check if item is expired
            if (now - item.timestamp > item.expiry) {
                localStorage.removeItem(key);
                return null;
            }
            return item.value;
        }

        // Load saved phone from localStorage if exists (for registration only)
        if (!config.isLoggedIn) {
            var savedPhone = getStorageWithExpiry('registration_phone');
            var savedVerified = getStorageWithExpiry('registration_phone_verified');

            if (savedPhone) {
                $('#phone').val(savedPhone);
                if (savedVerified === '1') {
                    $('#phone').prop('readonly', true);
                    $('#send-otp').prop('disabled', true);
                    $('#phone-verified').val(1);
                    $('#phone-verification-status').text(texts.phoneVerified).addClass('verified').removeClass('not-verified');
                }
            }
        }

        if (!config.isOptional) {
            $('form.form-create-account').on('submit', function(e) {
                if ($('#phone-verified').val() !== '1') {
                    e.preventDefault();
                    alert(texts.pleaseVerifyPhone);
                    return false;
                }
                return true;
            });
        }

        $(document).on('click', '#send-otp', function (e) {
            e.preventDefault();
            var phone = $('#phone').val();

            // First validate phone availability (only for registration)
            if (!config.isLoggedIn) {
                $.ajax({
                    url: config.validatePhoneUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        phone: phone
                    },
                    success: function(response) {
                        if (response.success) {
                            sendOtp(phone);
                        } else {
                            alert(response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Validate Phone Error:', error);
                        alert(texts.errorValidating);
                    }
                });
            } else {
                sendOtp(phone);
            }
        });

        function sendOtp(phone) {
            // Store phone in localStorage for registration with expiry
            if (!config.isLoggedIn) {
                setStorageWithExpiry('registration_phone', phone);
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
                    alert(texts.errorSendingOtp);
                }
            });
        }

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
                        $('#phone-verification-status').text(texts.phoneVerified).addClass('verified').removeClass('not-verified');

                        // Store verification status for registration with expiry
                        if (!config.isLoggedIn) {
                            setStorageWithExpiry('registration_phone_verified', '1');
                        }

                        alert(response.message);
                    } else {
                        alert(response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Verify OTP Error:', error);
                    alert(texts.errorVerifyingOtp);
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

        // Add a timer to show remaining time
        function startExpiryTimer() {
            let timeLeft = EXPIRY_TIME / 1000; // Convert to seconds
            const timerElement = $('<div id="otp-timer"></div>');
            $('#otp-section').prepend(timerElement);

            const timer = setInterval(function() {
                timeLeft--;
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerElement.text(texts.timeRemaining +
                    minutes + ':' + (seconds < 10 ? '0' : '') + seconds);

                if (timeLeft <= 0) {
                    clearInterval(timer);
                    $('#otp-section').hide();
                    $('#phone').prop('readonly', false);
                    $('#send-otp').prop('disabled', false);
                    $('#phone-verified').val(0);
                    localStorage.removeItem('registration_phone');
                    localStorage.removeItem('registration_phone_verified');
                    timerElement.remove();
                    alert(texts.otpExpired);
                }
            }, 1000);

            // Store timer reference to clear it if needed
            $('#otp-section').data('timer', timer);
        }

        // Start timer when OTP is sent
        $(document).on('click', '#send-otp', function() {
            // Clear existing timer if any
            const existingTimer = $('#otp-section').data('timer');
            if (existingTimer) {
                clearInterval(existingTimer);
                $('#otp-timer').remove();
            }
            startExpiryTimer();
        });
    };
});