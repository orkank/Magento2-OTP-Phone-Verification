define([
    'jquery',
    'Magento_Ui/js/modal/modal'
], function ($, modal) {
    'use strict';

    return function (config) {
        const EXPIRY_TIME = 3 * 60 * 1000; // 3 minutes in milliseconds
        const texts = config.translations;
        let formSubmitEvent = null;

        // Debug: Check if script is loaded multiple times
        if (window.phoneOtpVerificationLoaded) {
            console.warn('PhoneOtpVerification script already loaded, skipping duplicate initialization');
            return;
        }
        window.phoneOtpVerificationLoaded = true;
        console.log('PhoneOtpVerification initialized');

        // Shared variables for registration flow (accessible from common functions)
        let skipVerification = false;
        let isProcessingOtp = false;
        let originalFormSubmit = null;
        let handlerAttached = false;
        let handleFormSubmitNativeRef = null; // Store reference for removeEventListener

        // Helper function to safely submit the form after OTP verification
        // Defined at top level so it's accessible from all handlers
        function submitFormSafely($form) {
            const formElement = $form[0];

            if (!formElement) {
                return;
            }

            // Get form action URL
            const formAction = $form.attr('action');

            // Serialize form data
            const formData = new FormData(formElement);

            // Submit via AJAX to avoid validation issues
            $.ajax({
                url: formAction,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    // Check if response is HTML (redirect page) or JSON
                    if (typeof response === 'string' && response.indexOf('<!DOCTYPE') !== -1) {
                        // Response is HTML, likely a redirect or success page
                        // Reload the page or redirect
                        window.location.reload();
                    } else if (response && response.redirectUrl) {
                        // JSON response with redirect URL
                        window.location.href = response.redirectUrl;
                    } else {
                        // Default: reload page
                        window.location.reload();
                    }
                },
                error: function(xhr) {
                    // On error, try to parse response
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.message) {
                            alert(response.message);
                        } else {
                            alert(texts.errorSendingOtp || texts.errorOccurred || 'An error occurred. Please try again.');
                        }
                    } catch (e) {
                        // If can't parse, show generic error
                        alert(texts.errorSendingOtp || texts.errorOccurred || 'An error occurred. Please try again.');
                    }
                    // Re-enable form
                    $form.find('input, button').prop('disabled', false);
                    skipVerification = false;
                    isProcessingOtp = false;
                }
            });
        }

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
                $sendButton.prop('disabled', true).text(texts.sendingOtp || texts.sending || 'Sending...');
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
            // Variables and submitFormSafely are already declared at the top level for shared access

            // Function to check if OTP verification is needed
            function needsOtpVerification() {
                const phone = $('#phone').val() || '';
                const isVerified = $('#phone-verified').val() === '1';
                const phoneTrimmed = phone.replace(/\D/g, '');

                // Skip validation if skip was clicked or phone is empty
                if (skipVerification || !phone || phoneTrimmed.length === 0) {
                    return false; // No verification needed
                }

                // If phone is not empty and not verified, verification is needed
                return !isVerified && !isProcessingOtp;
            }

            // Function to handle OTP verification process
            function startOtpVerification(e) {
                const phone = $('#phone').val() || '';
                const phoneTrimmed = phone.replace(/\D/g, '');

                // Validate phone number format
                if (phoneTrimmed.length < 10) {
                    alert(texts.invalidPhone);
                    return false;
                }

                isProcessingOtp = true;
                if (e) {
                    formSubmitEvent = e;
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
                            isProcessingOtp = false;
                        }
                    },
                    error: function() {
                        alert(texts.errorValidating);
                        isProcessingOtp = false;
                    }
                });

                return false;
            }

            // Function to handle form submit - using native event for earliest capture
            handleFormSubmitNativeRef = function(e) {
                if (needsOtpVerification()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    e.stopPropagation();
                    return startOtpVerification(e);
                }

                // If OTP is being processed, prevent submission
                if (isProcessingOtp) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    e.stopPropagation();
                    return false;
                }

                return true;
            };

            // Function to handle form submit - jQuery version
            function handleFormSubmit(e) {
                if (needsOtpVerification()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return startOtpVerification(e);
                }

                // If OTP is being processed, prevent submission
                if (isProcessingOtp) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }

                return true;
            }

            // Override form's submit method for maximum control
            function overrideFormSubmit() {
                const $form = $('form.form-create-account, form#form-validate');
                if ($form.length && !handlerAttached) {
                    const formElement = $form[0];

                    // Store original submit method
                    if (formElement && !originalFormSubmit) {
                        originalFormSubmit = formElement.submit;
                    }

                    // Override native submit method
                    if (formElement && originalFormSubmit) {
                        formElement.submit = function() {
                            // Check if OTP verification is needed
                            if (needsOtpVerification()) {
                                startOtpVerification(null);
                                return false;
                            }

                            if (isProcessingOtp) {
                                return false;
                            }

                            // Call original submit
                            return originalFormSubmit.call(this);
                        };
                    }

                    // Attach native event listener with capture phase (runs first)
                    if (formElement && formElement.addEventListener && handleFormSubmitNativeRef) {
                        formElement.addEventListener('submit', handleFormSubmitNativeRef, true);
                    }

                    // Also attach jQuery handler
                    $form.off('submit.phoneOtp');
                    $form.on('submit.phoneOtp', handleFormSubmit);

                    handlerAttached = true;
                    return true;
                }
                return false;
            }

            // Attach to submit button click - remove any existing onclick handlers first
            function attachSubmitButtonHandler() {
                const $submitButton = $('form.form-create-account button[type="submit"], form#form-validate button[type="submit"]');
                if ($submitButton.length) {
                    // Remove any existing onclick attributes
                    $submitButton.each(function() {
                        const btn = this;
                        if (btn.onclick) {
                            btn.onclick = null;
                        }
                        // Remove onclick attribute
                        $(btn).removeAttr('onclick');
                    });

                    // Remove any existing jQuery handlers
                    $submitButton.off('click.phoneOtp');

                    // Attach our handler
                    $submitButton.on('click.phoneOtp', function(e) {
                        if (needsOtpVerification()) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            e.stopPropagation();
                            return startOtpVerification(e);
                        }

                        if (isProcessingOtp) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            e.stopPropagation();
                            return false;
                        }

                        return true;
                    });

                    return true;
                }
                return false;
            }

            // Try to attach handlers immediately and with retries
            function attachHandlers() {
                const formAttached = overrideFormSubmit();
                const buttonAttached = attachSubmitButtonHandler();
                return formAttached && buttonAttached;
            }

            // Try immediately
            if (!attachHandlers()) {
                // Retry with delays
                setTimeout(function() {
                    if (!attachHandlers()) {
                        setTimeout(function() {
                            attachHandlers();
                        }, 100);
                    }
                }, 50);
            }

            // Also try when DOM is fully ready
            $(document).ready(function() {
                setTimeout(attachHandlers, 100);
            });

            function sendOtp(phone) {
                // Disable the form during OTP sending
                const $form = $('form.form-create-account, form#form-validate');
                $form.find('input, button').prop('disabled', true);

                // Show loading state on submit button
                var $submitButton = $form.find('button[type="submit"]');
                var originalSubmitText = $submitButton.text();
                $submitButton.text(texts.sendingOtp || texts.sending || 'Sending OTP...');

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
                            $form.find('input, button').prop('disabled', false);
                            $('#phone').prop('disabled', true); // Keep phone disabled
                            $submitButton.text(originalSubmitText);
                        } else {
                            alert(response.message);
                            // Re-enable form on error
                            $form.find('input, button').prop('disabled', false);
                            $submitButton.text(originalSubmitText);
                            isProcessingOtp = false;
                        }
                    },
                    error: function() {
                        alert(texts.errorSendingOtp);
                        // Re-enable form on error
                        $form.find('input, button').prop('disabled', false);
                        $submitButton.text(originalSubmitText);
                        isProcessingOtp = false;
                    }
                });
            }

            // Skip verification button (only for optional verification)
            $(document).on('click', '#skip-verification', function() {
                if (config.isOptional) {
                    skipVerification = true;
                    isProcessingOtp = false; // Reset processing flag
                    $('#otp-modal').modal('closeModal');

                    // Wait for modal to close, then submit form
                    setTimeout(function() {
                        const $form = $('form.form-create-account, form#form-validate');
                        const $submitButton = $form.find('button[type="submit"]');

                        if ($submitButton.length) {
                            // Remove our click handler temporarily
                            $submitButton.off('click.phoneOtp');
                            // Click the submit button
                            $submitButton[0].click();
                        } else if ($form.length) {
                            // Fallback: submit form directly
                            const formElement = $form[0];
                            if (formElement && formElement.requestSubmit) {
                                formElement.requestSubmit();
                            } else {
                                $form.submit();
                            }
                        }
                    }, 200);
                }
            });

            // Reset skip flag when modal is closed (with delay to allow form submission)
            $('#otp-modal').on('modalclosed', function() {
                const timer = $(this).data('timer');
                if (timer) {
                    clearInterval(timer);
                }
                // Delay reset to allow form submission to complete
                setTimeout(function() {
                    skipVerification = false;
                }, 1000);
            });
        }

        // Common functionality for both flows
        // Use one() to prevent multiple clicks during processing
        let isVerifyingOtp = false;

        $(document).on('click', '#verify-otp', function(e) {
            // Prevent multiple clicks
            if (isVerifyingOtp) {
                console.log('Already verifying OTP, ignoring click');
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }

            const otp = $('#otp-input').val();
            const $verifyButton = $(this);
            const originalText = $verifyButton.text();

            // Remove any previous error messages
            $('.otp-error').remove();

            // Set flag and disable button
            isVerifyingOtp = true;
            console.log('Starting OTP verification...');
            $verifyButton.prop('disabled', true).text(texts.verifyingOtp || 'Verifying...');
            $('#otp-input').prop('disabled', true);

            $.ajax({
                url: config.verifyOtpUrl,
                type: 'POST',
                dataType: 'json',
                data: { otp: otp },
                success: function(response) {
                    console.log('OTP verification response:', response);

                    if (response && response.success) {
                        console.log('OTP verified successfully');
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
                            isVerifyingOtp = false;
                        } else {
                            // OTP verified successfully - now submit the form
                            // Don't reset isVerifyingOtp yet - keep it locked until form submits
                            console.log('Preparing to submit registration form...');
                            isProcessingOtp = false;
                            skipVerification = true; // Bypass our validation

                            // Close modal first
                            $('#otp-modal').modal('closeModal');

                            // Wait for modal to close, then submit form via AJAX
                            setTimeout(function() {
                                const $form = $('form.form-create-account, form#form-validate');

                                if (!$form.length) {
                                    console.error('Form not found');
                                    isVerifyingOtp = false;
                                    return;
                                }

                                console.log('Submitting form via AJAX...');
                                // Submit form directly via AJAX to bypass validation issues
                                submitFormSafely($form);
                            }, 300); // Delay to ensure modal is closed
                        }
                    } else {
                        // Response received but not successful
                        const errorMsg = (response && response.message) ? response.message : texts.errorVerifyingOtp;
                        console.error('OTP verification failed:', errorMsg);

                        // Show error in modal instead of alert
                        $('#otp-input').after('<div class="otp-error" style="color:red;margin-top:5px;">' + errorMsg + '</div>');

                        // Re-enable on error
                        $verifyButton.prop('disabled', false).text(originalText);
                        $('#otp-input').prop('disabled', false);
                        isVerifyingOtp = false;
                    }
                },
                error: function(xhr, status, error) {
                    // Network or server error
                    console.error('OTP verification error:', status, error, xhr);

                    // Show error in modal instead of alert
                    $('#otp-input').after('<div class="otp-error" style="color:red;margin-top:5px;">' + texts.errorVerifyingOtp + '</div>');

                    // Re-enable on error
                    $verifyButton.prop('disabled', false).text(originalText);
                    $('#otp-input').prop('disabled', false);
                    isVerifyingOtp = false;
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
                            isProcessingOtp = false; // Reset processing flag
                            $('#otp-modal').modal('closeModal');
                            // Re-enable form elements
                            const $form = $('form.form-create-account, form#form-validate');
                            $form.find('input, button').prop('disabled', false);
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