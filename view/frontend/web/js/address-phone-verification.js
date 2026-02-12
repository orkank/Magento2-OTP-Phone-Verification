define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/modal'
], function ($, $t, modal) {
    'use strict';

    return function (config, element) {
        const EXPIRY_TIME = 3 * 60 * 1000; // 3 minutes
        const $form = $(element);
        const $phoneField = $form.find('input[name="telephone"]');
        const $modal = $('#address-otp-modal');
        const $sendOtpButton = $('#address-send-otp');
        const $verifyOtpButton = $('#address-verify-otp');
        const $otpInput = $('#address-otp-input');
        const $phoneVerified = $('#address-phone-verified');
        const $phoneNumber = $('#address-phone-number');
        const $errorMessage = $('#address-otp-error');
        const $otpTimer = $('#address-otp-timer');
        const $modalPhone = $('#address-modal-phone');

        let isVerifying = false;
        let timerInterval = null;
        let otpModal = null;
        let pendingSubmit = false;
        let originalMagentoSubmitHandler = null;
        const verifiedPhoneCache = {}; // key: normalized phone => true/false

        // Initialize modal
        if ($modal.length) {
            const modalOptions = {
                type: 'popup',
                responsive: true,
                innerScroll: true,
                title: config.translations.phoneVerificationRequired || $t('Phone Verification Required'),
                buttons: [],
                modalClass: 'address-otp-modal'
            };
            otpModal = modal(modalOptions, $modal);
        }

        function normalizePhone(phone) {
            return String(phone || '').replace(/\D/g, '');
        }

        function setVerifiedState(phone) {
            $phoneVerified.val('1');
            $phoneNumber.val(phone);
            $('.phone-verified-indicator').remove();
            $phoneField.after(
                '<span class="phone-verified-indicator" style="color: green; margin-left: 10px; display: block; margin-top: 5px;">✓ ' +
                    (config.translations.phoneVerified || $t('Phone Verified')) +
                '</span>'
            );
        }

        function appendVerifiedHiddenInputs() {
            $form.find('input[name="address_phone_verified"]').remove();
            $form.find('input[name="phone_verified"]').remove();
            $form.append('<input type="hidden" name="address_phone_verified" value="1"/>');
            $form.append('<input type="hidden" name="phone_verified" value="1"/>');
        }

        function submitFormUsingMagentoOrFallback() {
            const formValidator = $form.data('validator');

            // Prefer original Magento submit handler to keep validation behavior consistent.
            if (originalMagentoSubmitHandler) {
                try {
                    return originalMagentoSubmitHandler.call(formValidator ? formValidator.settings : this, $form[0]);
                } catch (e) {
                    console.error('Address Phone Verification: Error in original submit handler', e);
                }
            }

            if (formValidator && formValidator.settings && typeof formValidator.settings.submitHandler === 'function') {
                try {
                    return formValidator.settings.submitHandler($form[0]);
                } catch (e2) {
                    console.error('Address Phone Verification: Error in current submit handler', e2);
                }
            }

            if ($form.valid && typeof $form.valid === 'function') {
                if ($form.valid()) {
                    $form[0].submit();
                }
                return;
            }

            $form[0].submit();
        }

        function submitPendingFormWithVerifiedFlags() {
            if (!pendingSubmit) {
                return;
            }
            pendingSubmit = false;
            setTimeout(function () {
                appendVerifiedHiddenInputs();
                console.log('Address Phone Verification: Submitting form with verified flags');
                submitFormUsingMagentoOrFallback();
            }, 0);
        }

        function checkPhoneAlreadyVerifiedForCustomer(phone) {
            const deferred = $.Deferred();
            const normalized = normalizePhone(phone);
            if (!normalized || normalized.length < 10) {
                deferred.resolve(false);
                return deferred.promise();
            }

            // Profile phone shortcut (already handled in needsVerification, but keep safe here)
            if (config.customerPhoneVerified == 1 && config.customerPhoneNumber) {
                const customerPhoneTrimmed = normalizePhone(config.customerPhoneNumber);
                if (customerPhoneTrimmed && customerPhoneTrimmed === normalized) {
                    verifiedPhoneCache[normalized] = true;
                    deferred.resolve(true);
                    return deferred.promise();
                }
            }

            if (verifiedPhoneCache.hasOwnProperty(normalized)) {
                deferred.resolve(!!verifiedPhoneCache[normalized]);
                return deferred.promise();
            }

            if (!config.isVerifiedUrl) {
                verifiedPhoneCache[normalized] = false;
                deferred.resolve(false);
                return deferred.promise();
            }

            $.ajax({
                url: config.isVerifiedUrl,
                type: 'GET',
                dataType: 'json',
                data: { phone: phone }
            }).done(function (resp) {
                const isVerified = !!(resp && resp.verified);
                verifiedPhoneCache[normalized] = isVerified;
                deferred.resolve(isVerified);
            }).fail(function () {
                // don't block UX; treat as not verified and proceed with OTP
                verifiedPhoneCache[normalized] = false;
                deferred.resolve(false);
            });

            return deferred.promise();
        }

        // Check if phone verification is needed
        function needsVerification() {
            const phone = $phoneField.val() || '';
            const phoneTrimmed = normalizePhone(phone);
            
            // If phone is empty, no verification needed
            if (!phone || phoneTrimmed.length < 10) {
                return false;
            }

            // If customer already has this phone verified in profile, no re-verification required
            if (config.customerPhoneVerified == 1 && config.customerPhoneNumber) {
                const customerPhoneTrimmed = normalizePhone(config.customerPhoneNumber);
                if (customerPhoneTrimmed && customerPhoneTrimmed === phoneTrimmed) {
                    // keep hidden state consistent so UI doesn't re-open modal
                    setVerifiedState(phone);
                    return false;
                }
            }

            // Per-phone skip rule (cached): if this phone was verified on any address before, skip OTP.
            if (verifiedPhoneCache[phoneTrimmed] === true) {
                setVerifiedState(phone);
                return false;
            }

            // Check if already verified for this phone number
            if ($phoneVerified.val() === '1' && $phoneNumber.val() === phone) {
                return false;
            }

            return true;
        }

        function startVerificationFlowOrSubmit() {
            const phone = $phoneField.val() || '';
            pendingSubmit = true;

            checkPhoneAlreadyVerifiedForCustomer(phone).done(function (alreadyVerified) {
                if (alreadyVerified) {
                    setVerifiedState(phone);
                    submitPendingFormWithVerifiedFlags();
                    return;
                }

                showVerificationModal();
            });

            return false;
        }

        // Show verification modal
        function showVerificationModal() {
            console.log('Address Phone Verification: Showing modal');
            const phone = $phoneField.val();
            $modalPhone.val(phone);
            $phoneNumber.val(phone);
            $phoneVerified.val('0');
            $otpInput.val('');
            $errorMessage.hide();
            $sendOtpButton.show();
            $verifyOtpButton.hide();
            $otpTimer.hide();
            if (timerInterval) {
                clearInterval(timerInterval);
            }
            
            if (otpModal) {
                otpModal.openModal();
                // Automatically send OTP when modal opens
                setTimeout(function() {
                    if ($sendOtpButton.is(':visible') && !$sendOtpButton.prop('disabled')) {
                        console.log('Address Phone Verification: Auto-sending OTP');
                        $sendOtpButton.click();
                    }
                }, 300); // Small delay to ensure modal is fully opened
            } else {
                console.error('Address Phone Verification: Modal not initialized');
            }
        }

        // Close verification modal
        function closeVerificationModal() {
            if (otpModal) {
                otpModal.closeModal();
            }
        }

        // Show error message
        function showError(message) {
            $errorMessage.text(message).show();
            setTimeout(function() {
                $errorMessage.hide();
            }, 5000);
        }

        // Start expiry timer
        function startTimer() {
            let timeLeft = EXPIRY_TIME / 1000;
            $otpTimer.show();

            if (timerInterval) {
                clearInterval(timerInterval);
            }

            timerInterval = setInterval(function() {
                timeLeft--;
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                $otpTimer.text(config.translations.timeRemaining + 
                    minutes + ':' + (seconds < 10 ? '0' : '') + seconds);

                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    $otpTimer.hide();
                    $verifyOtpButton.hide();
                    $sendOtpButton.show();
                    $phoneVerified.val('0');
                    showError(config.translations.otpExpired);
                }
            }, 1000);
        }

        // Send OTP
        $sendOtpButton.on('click', function() {
            const phone = $modalPhone.val() || $phoneField.val();
            const phoneTrimmed = phone.replace(/\D/g, '');

            if (phoneTrimmed.length < 10) {
                showError($t('Please enter a valid phone number'));
                return;
            }

            $sendOtpButton.prop('disabled', true).text(config.translations.sendingOtp || $t('Sending OTP...'));
            $otpInput.prop('disabled', true);

            $.ajax({
                url: config.sendOtpUrl,
                type: 'POST',
                dataType: 'json',
                // Address verification: allow phones used by other customers too
                data: { phone: phone, context: 'address' },
                success: function(response) {
                    if (response.success) {
                        $sendOtpButton.hide();
                        $verifyOtpButton.show().prop('disabled', false);
                        $otpInput.prop('disabled', false).focus();
                        startTimer();
                    } else {
                        showError(response.message || config.translations.errorSendingOtp || $t('Error sending OTP'));
                        $sendOtpButton.prop('disabled', false).text(config.translations.sendOtp || $t('Send OTP'));
                        $otpInput.prop('disabled', false);
                    }
                },
                error: function() {
                    showError(config.translations.errorSendingOtp || $t('Error sending OTP. Please try again.'));
                    $sendOtpButton.prop('disabled', false).text(config.translations.sendOtp || $t('Send OTP'));
                    $otpInput.prop('disabled', false);
                }
            });
        });

        // Verify OTP
        $verifyOtpButton.on('click', function() {
            if (isVerifying) {
                return;
            }

            const otp = $otpInput.val();
            if (!otp || otp.length !== 6) {
                showError($t('Please enter a valid 6-digit OTP code'));
                return;
            }

            const phone = $modalPhone.val() || $phoneField.val();

            isVerifying = true;
            $verifyOtpButton.prop('disabled', true).text(config.translations.verifyingOtp || $t('Verifying...'));
            $otpInput.prop('disabled', true);

            $.ajax({
                url: config.verifyOtpUrl,
                type: 'POST',
                dataType: 'json',
                data: { 
                    otp: otp,
                    address_phone_verified: 1,
                    phone: phone
                },
                success: function(response) {
                    if (response.success) {
                        setVerifiedState(phone);
                        
                        // Close modal
                        closeVerificationModal();
                        
                        // If form submission was pending, submit it now
                        submitPendingFormWithVerifiedFlags();
                    } else {
                        showError(response.message || config.translations.invalidOtp || $t('Invalid OTP code'));
                        $verifyOtpButton.prop('disabled', false).text(config.translations.verifyOtp || $t('Verify OTP'));
                        $otpInput.prop('disabled', false);
                    }
                    isVerifying = false;
                },
                error: function() {
                    showError(config.translations.errorVerifyingOtp || $t('Error verifying OTP. Please try again.'));
                    $verifyOtpButton.prop('disabled', false).text(config.translations.verifyOtp || $t('Verify OTP'));
                    $otpInput.prop('disabled', false);
                    isVerifying = false;
                }
            });
        });

        // Monitor phone field changes - reset verification if phone changed
        $phoneField.on('blur change', function() {
            const currentPhone = $(this).val();
            if ($phoneNumber.val() && $phoneNumber.val() !== currentPhone) {
                $phoneVerified.val('0');
                $phoneNumber.val('');
                $('.phone-verified-indicator').remove();
            }

            // Proactively warm cache (best effort; avoids OTP if already verified)
            if (currentPhone) {
                checkPhoneAlreadyVerifiedForCustomer(currentPhone).done(function (alreadyVerified) {
                    if (alreadyVerified) {
                        setVerifiedState(currentPhone);
                    }
                });
            }
        });

        // Override Magento's form validation submitHandler - try multiple times
        function overrideSubmitHandler() {
            const formValidator = $form.data('validator');
            if (formValidator && formValidator.settings) {
                // Only override if not already overridden
                if (!formValidator.settings._phoneVerificationOverridden) {
                    const originalSubmitHandler = formValidator.settings.submitHandler;
                    originalMagentoSubmitHandler = originalSubmitHandler || null;
                    
                    formValidator.settings.submitHandler = function(form) {
                        // Check if phone verification is needed
                        if (needsVerification() && $phoneVerified.val() !== '1') {
                            return startVerificationFlowOrSubmit();
                        }

                        // Add verified flag to form if verified
                        if ($phoneVerified.val() === '1') {
                            appendVerifiedHiddenInputs();
                        }

                        // Call original submit handler if verification passed
                        if (originalSubmitHandler) {
                            return originalSubmitHandler.call(this, form);
                        } else {
                            form.submit();
                        }
                    };
                    
                    formValidator.settings._phoneVerificationOverridden = true;
                    return true;
                }
            }
            return false;
        }

        // Try to override immediately and with delays
        if (!overrideSubmitHandler()) {
            setTimeout(function() {
                if (!overrideSubmitHandler()) {
                    setTimeout(function() {
                        overrideSubmitHandler();
                    }, 500);
                }
            }, 200);
        }

        // Intercept submit button click BEFORE form validation - use capture phase
        const $submitButton = $form.find('button[type="submit"], button[data-action="save-address"]');
        console.log('Address Phone Verification: Found submit button', $submitButton.length);
        
        // Remove any existing handlers first
        $submitButton.off('click.addressPhoneVerification');
        
        // Attach with capture phase to run before other handlers
        $submitButton.each(function() {
            const button = this;
            if (button.addEventListener) {
                button.addEventListener('click', function(e) {
                    console.log('Address Phone Verification: Submit button clicked (capture)');
                    // Check if phone verification is needed
                    if (needsVerification() && $phoneVerified.val() !== '1') {
                        console.log('Address Phone Verification: Phone verification needed, preventing submit');
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        e.stopPropagation();
                        
                        return startVerificationFlowOrSubmit();
                    }
                }, true); // Use capture phase
            }
        });
        
        // Also attach jQuery handler as fallback
        $submitButton.on('click.addressPhoneVerification', function(e) {
            console.log('Address Phone Verification: Submit button clicked (jQuery)');
            // Check if phone verification is needed
            if (needsVerification() && $phoneVerified.val() !== '1') {
                console.log('Address Phone Verification: Phone verification needed, preventing submit');
                e.preventDefault();
                e.stopImmediatePropagation();
                e.stopPropagation();
                
                return startVerificationFlowOrSubmit();
            }
        });

        // Override form's native submit method
        const formElement = $form[0];
        if (formElement) {
            const originalSubmit = formElement.submit;
            formElement.submit = function() {
                console.log('Address Phone Verification: Form submit() called');
                // Check if phone verification is needed
                if (needsVerification() && $phoneVerified.val() !== '1') {
                    console.log('Address Phone Verification: Phone verification needed, preventing submit');
                    return startVerificationFlowOrSubmit();
                }

                // Add verified flag to form if verified BEFORE submitting
                if ($phoneVerified.val() === '1') {
                    appendVerifiedHiddenInputs();
                    console.log('Address Phone Verification: Added verification flags to form');
                }

                // Call original submit
                return originalSubmit.call(this);
            };
        }

        // Intercept form submission as additional safety - use capture phase
        if (formElement && formElement.addEventListener) {
            formElement.addEventListener('submit', function(e) {
                console.log('Address Phone Verification: Form submit event (capture)');
                // Check if phone verification is needed
                if (needsVerification() && $phoneVerified.val() !== '1') {
                    console.log('Address Phone Verification: Phone verification needed, preventing submit');
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    e.stopPropagation();
                    
                    return startVerificationFlowOrSubmit();
                }

                // Add verified flag to form if verified
                if ($phoneVerified.val() === '1') {
                    appendVerifiedHiddenInputs();
                }
            }, true); // Use capture phase
        }

        // Also attach jQuery handler
        $form.off('submit.addressPhoneVerification').on('submit.addressPhoneVerification', function(e) {
            console.log('Address Phone Verification: Form submit event (jQuery)');
            // Check if phone verification is needed
            if (needsVerification() && $phoneVerified.val() !== '1') {
                console.log('Address Phone Verification: Phone verification needed, preventing submit');
                e.preventDefault();
                e.stopImmediatePropagation();
                e.stopPropagation();
                
                return startVerificationFlowOrSubmit();
            }

            // Add verified flag to form if verified BEFORE submitting
            if ($phoneVerified.val() === '1') {
                appendVerifiedHiddenInputs();
                console.log('Address Phone Verification: Added verification flags to form (jQuery handler)');
            }
        });
        
        console.log('Address Phone Verification: Initialized for form', $form.attr('id'));

        // Handle Enter key in OTP input
        $otpInput.on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                if ($verifyOtpButton.is(':visible') && !$verifyOtpButton.prop('disabled')) {
                    $verifyOtpButton.click();
                }
            }
        });
    };
});
