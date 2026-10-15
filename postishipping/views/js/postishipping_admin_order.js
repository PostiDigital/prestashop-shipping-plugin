const POSTISHIPPING_ADMIN_ORDER = {
    panel: document,
    overlay: null,
    errors: null,
    content: null,
    modalPp: null,
    initialized: null,
    registered: false,
    availableServices: null, // cached from getAvailableServices AJAX
    _customFormSnapshot: null, // snapshot of custom section form state for dirty tracking
    init: function (panel) {
        if (POSTISHIPPING_ADMIN_ORDER.initialized) {
            return;
        }

        POSTISHIPPING_ADMIN_ORDER.panel = panel;
        POSTISHIPPING_ADMIN_ORDER.overlay = $(panel).find(`div.overlay`);
        POSTISHIPPING_ADMIN_ORDER.errors = $(panel).find(`.posti-errors`);
        POSTISHIPPING_ADMIN_ORDER.success = $(panel).find(`.posti-success`);
        POSTISHIPPING_ADMIN_ORDER.modalPp = $('#postishipping-pp-change-modal');
        POSTISHIPPING_ADMIN_ORDER.content = panel.querySelector(`[data-postishipping-content]`);

        POSTISHIPPING_ADMIN_ORDER.registered = 1 === $(panel).find('[data-posti-registered="true"]').length;

        $(POSTISHIPPING_ADMIN_ORDER.panel)
            .on("click", "button[data-postishipping-save-meta]", POSTISHIPPING_ADMIN_ORDER.saveMeta)
            .on("click", "button[data-postishipping-register]", POSTISHIPPING_ADMIN_ORDER.registerLabel)
            .on("click", "button[data-postishipping-register-custom]", POSTISHIPPING_ADMIN_ORDER.registerCustomLabel)
            .on("click", "button[data-postishipping-print-label]", POSTISHIPPING_ADMIN_ORDER.printLabel)
            .on("click", "button[data-postishipping-delete-label]", POSTISHIPPING_ADMIN_ORDER.deleteLabel)
            .on("click", "button[data-postishipping-register-return]", POSTISHIPPING_ADMIN_ORDER.registerReturnLabel)
            .on("click", "button[data-postishipping-delete-return-label]", POSTISHIPPING_ADMIN_ORDER.deleteReturnLabel)
            .on("click", "button[data-postishipping-refresh-label]", POSTISHIPPING_ADMIN_ORDER.refreshLabel)
            .on("click", "button[data-postishipping-pickup-label]", POSTISHIPPING_ADMIN_ORDER.toPickup)
            .on("click", "button[data-postishipping-load-services]", POSTISHIPPING_ADMIN_ORDER.loadAvailableServices)
            .on("change", "select[data-postishipping-service-selector]", POSTISHIPPING_ADMIN_ORDER.onServiceChange)
            .on("click", "button[data-postishipping-toggle-mode]", POSTISHIPPING_ADMIN_ORDER.toggleShippingMode);

        $(POSTISHIPPING_ADMIN_ORDER.modalPp)
            .on("click", "button.posti-pp-change-btn", POSTISHIPPING_ADMIN_ORDER.getPickupPointsBtn)
            .on("click", "button[data-postishipping-update-pp]", POSTISHIPPING_ADMIN_ORDER.updatePickupPointBtn);
        $(POSTISHIPPING_ADMIN_ORDER.modalPp)
            .on("change", "input.posti-radio", POSTISHIPPING_ADMIN_ORDER.modalUpdBtnState)
            .on("change", "select.postishipping-pickup-select", POSTISHIPPING_ADMIN_ORDER.modalUpdBtnState);
        POSTISHIPPING_ADMIN_ORDER.modalUpdBtnState();

        POSTISHIPPING_ADMIN_ORDER.initialized = true;

        // Save original selected services for restore on service revert
        postishipping._originalSelectedServices = JSON.parse(JSON.stringify(postishipping.selectedServices || []));
        postishipping._originalSelectedServicesSpec = JSON.parse(JSON.stringify(postishipping.selectedServicesSpec || {}));

        // Render custom section additional services from current shipping method
        const data = JSON.parse(atob(postishipping.cartMeta.meta.shippingMethod));
        POSTISHIPPING_ADMIN_ORDER.renderAdditionalServices(data);

        // Render default section additional services info
        POSTISHIPPING_ADMIN_ORDER.renderDefaultAdditionalServices();

        // Snapshot initial custom form state and bind change tracking
        POSTISHIPPING_ADMIN_ORDER.snapshotCustomForm();

        // Restore custom section if it was active before page reload
        if (sessionStorage.getItem('postishipping_show_custom') === '1') {
            sessionStorage.removeItem('postishipping_show_custom');
            POSTISHIPPING_ADMIN_ORDER.toggleShippingMode();
        }
        $(POSTISHIPPING_ADMIN_ORDER.panel)
            .on('change input', '[data-postishipping-custom-section] select, [data-postishipping-custom-section] input', POSTISHIPPING_ADMIN_ORDER.checkCustomFormDirty);
    },
    showOverlay: function () {
        POSTISHIPPING_ADMIN_ORDER.overlay && POSTISHIPPING_ADMIN_ORDER.overlay.show();
    },
    hideOverlay: function () {
        POSTISHIPPING_ADMIN_ORDER.overlay && POSTISHIPPING_ADMIN_ORDER.overlay.hide();
    },
    showErrors: function (errorMsg) {
        POSTISHIPPING_ADMIN_ORDER.errors
            && POSTISHIPPING_ADMIN_ORDER.errors.find("p").html(errorMsg)
            && POSTISHIPPING_ADMIN_ORDER.errors.slideDown(300, function () {
                let remOffset = parseFloat(getComputedStyle(document.documentElement).fontSize) * 12;
                let elementTop = this.getBoundingClientRect().top + window.pageYOffset - remOffset;
                window.scrollTo({ top: elementTop, behavior: 'smooth' });
            });
    },
    hideErrors: function () {
        POSTISHIPPING_ADMIN_ORDER.errors
            && POSTISHIPPING_ADMIN_ORDER.errors.slideUp(300);
    },
    showSuccess: function (successMsg) {
        POSTISHIPPING_ADMIN_ORDER.success
            && POSTISHIPPING_ADMIN_ORDER.success.find("p").html(successMsg)
            && POSTISHIPPING_ADMIN_ORDER.success.slideDown(300, function () {
                let remOffset = parseFloat(getComputedStyle(document.documentElement).fontSize) * 12;
                let elementTop = this.getBoundingClientRect().top + window.pageYOffset - remOffset;
                window.scrollTo({ top: elementTop, behavior: 'smooth' });
            });
    },
    hideSuccess: function () {
        POSTISHIPPING_ADMIN_ORDER.success
            && POSTISHIPPING_ADMIN_ORDER.success.slideUp(300);
    },
    /**
     * Replaces the order block form HTML with fresh server-rendered content,
     * updates the JS state, and re-initializes dynamic parts.
     * Called after state-changing AJAX actions succeed.
     */
    refreshBlock: function (response, successMessage) {
        if (!response.blockHtml) {
            // Fallback: if no block HTML returned, just show success message
            POSTISHIPPING_ADMIN_ORDER.showSuccess(successMessage);
            return;
        }

        // Remember if custom section was visible before refresh
        let wasCustomVisible = $(POSTISHIPPING_ADMIN_ORDER.panel).find('[data-postishipping-custom-section]').is(':visible');

        // Replace form HTML
        let form = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('form[data-posti-form]');
        if (form) {
            let temp = document.createElement('div');
            temp.innerHTML = response.blockHtml;
            let newForm = temp.querySelector('form[data-posti-form]');
            if (newForm) {
                form.parentNode.replaceChild(newForm, form);
            } else {
                form.outerHTML = response.blockHtml;
            }
        }

        // Update JS state from server response
        if (response.blockState) {
            let state = response.blockState;
            postishipping.cartMeta = state.cartMeta;
            postishipping.metaId = state.metaId;
            postishipping.selectedServices = state.selectedServices;
            postishipping.selectedServicesSpec = state.selectedServicesSpec;
            postishipping.selectedPickupPoint = state.selectedPickupPoint;
            postishipping.shippingMethod = state.shippingMethod;
            postishipping.codData = state.codData;
            postishipping.dgData = state.dgData;
            postishipping.customSpecifiersMap = state.customSpecifiersMap;
            postishipping.orderTotal = state.orderTotal;
            postishipping.shippingAddress = state.shippingAddress;
            postishipping.defaultRegistration = state.defaultRegistration;
            postishipping.orderProducts = state.orderProducts;
            postishipping.postiAccess = state.postiAccess;
        }

        // Re-initialize dynamic parts after DOM replacement
        POSTISHIPPING_ADMIN_ORDER.reinitAfterRefresh();

        // Restore custom section visibility if it was active
        if (wasCustomVisible) {
            POSTISHIPPING_ADMIN_ORDER.toggleShippingMode();
        }

        // Show success message
        POSTISHIPPING_ADMIN_ORDER.showSuccess(successMessage);
    },
    /**
     * Re-queries DOM references and re-renders dynamic parts after block HTML replacement.
     */
    reinitAfterRefresh: function () {
        let panel = POSTISHIPPING_ADMIN_ORDER.panel;

        // Re-query DOM references that live inside the replaced form
        POSTISHIPPING_ADMIN_ORDER.errors = $(panel).find('.posti-errors');
        POSTISHIPPING_ADMIN_ORDER.success = $(panel).find('.posti-success');
        POSTISHIPPING_ADMIN_ORDER.registered = 1 === $(panel).find('[data-posti-registered="true"]').length;

        // Update originals for dirty tracking
        postishipping._originalSelectedServices = JSON.parse(JSON.stringify(postishipping.selectedServices || []));
        postishipping._originalSelectedServicesSpec = JSON.parse(JSON.stringify(postishipping.selectedServicesSpec || {}));

        // Re-render dynamic parts
        if (postishipping.cartMeta && postishipping.cartMeta.meta && postishipping.cartMeta.meta.shippingMethod) {
            try {
                const data = JSON.parse(atob(postishipping.cartMeta.meta.shippingMethod));
                POSTISHIPPING_ADMIN_ORDER.renderAdditionalServices(data);
            } catch (e) { /* ignore parse errors */ }
        }
        POSTISHIPPING_ADMIN_ORDER.renderDefaultAdditionalServices();
        POSTISHIPPING_ADMIN_ORDER.snapshotCustomForm();

        // Re-populate service selector if services were previously loaded
        if (POSTISHIPPING_ADMIN_ORDER.availableServices) {
            POSTISHIPPING_ADMIN_ORDER.populateServiceSelector();
        }
    },
    refreshMessage: function (seconds) {
        return postishipping.strings.refresh.replace('{seconds}', '<span class="posti-countdown">' + seconds + '</span>');
    },
    startCountdown: function (seconds) {
        let remaining = seconds;
        let countdownEl = POSTISHIPPING_ADMIN_ORDER.success && POSTISHIPPING_ADMIN_ORDER.success.find('.posti-countdown');
        let interval = setInterval(function () {
            remaining--;
            if (countdownEl && countdownEl.length) {
                countdownEl.text(remaining);
            }
            if (remaining <= 0) {
                clearInterval(interval);
                window.location.reload();
            }
        }, 1000);
    },
    capitalize: function (string) {
        string = string.replaceAll('_', ' ');
        return string.charAt(0).toUpperCase() + string.slice(1);
    },
    getSelectedServiceSpec: function (addSeriveId, spec) {
        if (!postishipping?.selectedServicesSpec) {
            return '';
        }

        if (postishipping.selectedServicesSpec.hasOwnProperty(addSeriveId)
            && postishipping.selectedServicesSpec[addSeriveId].hasOwnProperty(spec)) {
            return postishipping.selectedServicesSpec[addSeriveId][spec];
        }

        return '';
    },
    renderAdditionalServices: function (data) {
        const selectedServices = postishipping.selectedServices;

        if (data.additional_services) {
            let additionalServicesContainer = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('[data-postishipping-additional-services]');
            if (!additionalServicesContainer) {
                additionalServicesContainer = document.createElement('div');
                additionalServicesContainer.dataset.postishippingAdditionalServices = '';
            }

            additionalServicesContainer.innerHTML = '';
            data.additional_services.forEach(element => {
                const label = document.createElement('label');
                if (element.name) {
                    label.innerText = element.name;
                } else {
                    const codeSpan = document.createElement('span');
                    codeSpan.className = 'text-muted font-italic';
                    codeSpan.innerText = element.service_code;
                    label.append(codeSpan);
                }
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'posti_additional_service[]';
                input.value = element.service_code;
                input.checked = selectedServices.includes(element.service_code);
                // Auto-check DG service if order has products with DG weight and meta was not yet saved by user
                if (!input.checked && postishipping.dgData && postishipping.dgData.autoSelect && element.service_code === postishipping.dgData.serviceCode) {
                    input.checked = true;
                }
                label.prepend(input);
                additionalServicesContainer.append(label);
                if (element.specifiers && typeof element.specifiers.forEach !== 'function') {
                    element.specifiers = [element.specifiers];
                }
                // Inject specifiers from custom service definitions if API did not provide them
                if (postishipping.customSpecifiersMap && postishipping.customSpecifiersMap[element.service_code]) {
                    if (!element.specifiers || !element.specifiers.length) {
                        element.specifiers = postishipping.customSpecifiersMap[element.service_code];
                    }
                }
                if (element.specifiers && typeof element.specifiers.forEach === 'function') {
                    const specContainer = document.createElement('div');
                    specContainer.dataset.postishippingSpec = '';
                    element.specifiers.forEach(spec => {
                        const specTitle = document.createElement('label');
                        specTitle.innerText = POSTISHIPPING_ADMIN_ORDER.capitalize(spec.name + ':');
                        const specInput = document.createElement('input');
                        specInput.type = spec.type;
                        specInput.name = `posti_additional_service_spec[${element.service_code}][${spec.name}]`;
                        specInput.classList.add('form-control');

                        let metaValue = POSTISHIPPING_ADMIN_ORDER.getSelectedServiceSpec(element.service_code, spec.name);
                        if (!metaValue && element.service_code === postishipping.codData.serviceCode) {
                            metaValue = postishipping.codData[spec.name];
                        }
                        if (!metaValue && postishipping.dgData && element.service_code === postishipping.dgData.serviceCode) {
                            metaValue = postishipping.dgData[spec.name];
                        }
                        specInput.value = metaValue;

                        if ('pickup_point_id' === spec.name) {
                            specInput.type = 'hidden';
                            let pickupPointEl = document.createElement('div');
                            pickupPointEl.classList.add('posti-selected-pp');
                            pickupPointEl.innerText = postishipping.strings?.notSelected || 'Not selected';
                            if (postishipping.selectedPickupPoint && postishipping.selectedPickupPoint.name) {
                                let point = postishipping.selectedPickupPoint;
                                pickupPointEl.innerHTML = `<div class="posti-pp-title">${point.name}</div><div class="posti-pp-address">${point.street_address}, ${point.postcode}, ${point.city}</div>`;
                            }
                            specTitle.append(pickupPointEl);

                            let button = document.createElement('button');
                            button.type = 'button';
                            button.innerText = postishipping.strings.change || 'Change';
                            button.dataset.toggle = "modal"
                            button.dataset.target = "#postishipping-pp-change-modal"
                            button.classList.add('btn', 'btn-primary', 'btn-sm', 'posti-pp-change-btn');

                            specTitle.append(button);
                        }

                        specTitle.append(specInput);
                        specContainer.append(specTitle);
                    });
                    additionalServicesContainer.append(specContainer);
                }
            });
        }
    },
    /**
     * Renders default additional services info (read-only) in the Default Registration section
     */
    renderDefaultAdditionalServices: function () {
        const container = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('[data-postishipping-default-additional-services]');
        if (!container) return;

        const defaultCodes = postishipping.defaultRegistration.additionalServiceCodes || [];
        if (!defaultCodes.length) {
            container.innerHTML = '<span class="text-muted font-italic">No additional services</span>';
            return;
        }

        // Try to get names from the default shipping method data
        let serviceNames = {};
        if (postishipping.defaultRegistration.shippingMethodBase64) {
            try {
                const data = JSON.parse(atob(postishipping.defaultRegistration.shippingMethodBase64));
                if (data.additional_services) {
                    data.additional_services.forEach(function (svc) {
                        serviceNames[svc.service_code] = svc.name || svc.service_code;
                    });
                }
            } catch (e) { /* ignore */ }
        }

        container.innerHTML = '';
        defaultCodes.forEach(function (code) {
            const div = document.createElement('div');
            div.className = 'mb-1';
            const name = serviceNames[code] || code;
            let html = '<span class="badge badge-secondary" title="' + code + '">' + name + '</span>';
            if (postishipping.defaultRegistration.servicesInfo && postishipping.defaultRegistration.servicesInfo[code]) {
                html += ' <span class="text-muted font-italic small">' + postishipping.defaultRegistration.servicesInfo[code] + '</span>';
            }
            div.innerHTML = html;
            container.append(div);
        });
    },
    /**
     * Captures a snapshot of the custom section form state for dirty checking
     */
    snapshotCustomForm: function () {
        let customSection = $(POSTISHIPPING_ADMIN_ORDER.panel).find('[data-postishipping-custom-section]');
        let state = {};

        // Service selector
        let selector = customSection.find('select[data-postishipping-service-selector]');
        if (selector.length) state.serviceCode = String(selector.val() || '');

        // Checkboxes
        state.checkboxes = {};
        customSection.find('input[type="checkbox"]').each(function () {
            state.checkboxes[this.name + '=' + this.value] = this.checked;
        });

        // Text/hidden inputs (specifiers), exclude product quantity inputs
        state.inputs = {};
        customSection.find('input[type="text"], input[type="number"], input[type="hidden"]').not('[name^="posti_custom_product"]').each(function () {
            state.inputs[this.name] = this.value;
        });

        POSTISHIPPING_ADMIN_ORDER._customFormSnapshot = state;
    },

    /**
     * Compares current custom form state with snapshot and toggles Update/Register buttons
     */
    checkCustomFormDirty: function () {
        let snapshot = POSTISHIPPING_ADMIN_ORDER._customFormSnapshot;
        if (!snapshot) return;

        let panel = $(POSTISHIPPING_ADMIN_ORDER.panel);
        let customSection = panel.find('[data-postishipping-custom-section]');
        let dirty = false;

        // Check service selector
        let selector = customSection.find('select[data-postishipping-service-selector]');
        if (selector.length && String(selector.val() || '') !== snapshot.serviceCode) {
            dirty = true;
        }

        // Check checkboxes
        if (!dirty) {
            let currentCheckboxes = {};
            customSection.find('input[type="checkbox"]').each(function () {
                currentCheckboxes[this.name + '=' + this.value] = this.checked;
            });
            // Check for differences (added, removed, or changed)
            let allKeys = Object.keys(Object.assign({}, snapshot.checkboxes, currentCheckboxes));
            for (let i = 0; i < allKeys.length; i++) {
                if (!!snapshot.checkboxes[allKeys[i]] !== !!currentCheckboxes[allKeys[i]]) {
                    dirty = true;
                    break;
                }
            }
        }

        // Check text/hidden inputs (exclude product quantity inputs)
        if (!dirty) {
            let currentInputs = {};
            customSection.find('input[type="text"], input[type="number"], input[type="hidden"]').not('[name^="posti_custom_product"]').each(function () {
                currentInputs[this.name] = this.value;
            });
            let allInputKeys = Object.keys(Object.assign({}, snapshot.inputs, currentInputs));
            for (let j = 0; j < allInputKeys.length; j++) {
                if ((snapshot.inputs[allInputKeys[j]] || '') !== (currentInputs[allInputKeys[j]] || '')) {
                    dirty = true;
                    break;
                }
            }
        }

        let updateBtn = panel.find('button[data-postishipping-save-meta]');
        let registerCustomBtn = panel.find('button[data-postishipping-register-custom]');

        if (dirty) {
            updateBtn.prop('disabled', false);
            registerCustomBtn.prop('disabled', true);
        } else {
            updateBtn.prop('disabled', true);
            registerCustomBtn.prop('disabled', false);
        }
    },

    /**
     * Toggle between default and custom shipping modes
     */
    toggleShippingMode: function () {
        let panel = $(POSTISHIPPING_ADMIN_ORDER.panel);
        let defaultSection = panel.find('[data-postishipping-default-section]');
        let customSection = panel.find('[data-postishipping-custom-section]');
        let defaultButtons = panel.find('[data-postishipping-default-buttons]');
        let customButtons = panel.find('[data-postishipping-custom-buttons]');
        let toggleBtn = panel.find('[data-postishipping-toggle-mode]');

        let isCustomVisible = customSection.is(':visible');

        if (isCustomVisible) {
            // Switch to default mode
            customSection.hide();
            customButtons.hide();
            defaultSection.show();
            defaultButtons.show();
            toggleBtn.text(postishipping.strings.customShipping || 'Custom shipping...');
        } else {
            // Switch to custom mode
            defaultSection.hide();
            defaultButtons.hide();
            customSection.show();
            customButtons.show();
            toggleBtn.text(postishipping.strings.originalShipping || 'Original shipping...');
        }
    },

    /**
     * Loads all available Posti services via AJAX and populates the service selector dropdown
     */
    loadAvailableServices: function () {
        if (POSTISHIPPING_ADMIN_ORDER.availableServices) {
            // Already loaded
            return;
        }

        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: { action: 'getAvailableServices' },
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors();
                POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                POSTISHIPPING_ADMIN_ORDER.availableServices = e.content || [];
                POSTISHIPPING_ADMIN_ORDER.populateServiceSelector();
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });
    },
    /**
     * Populates the service selector dropdown with available services
     */
    populateServiceSelector: function () {
        const selector = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('select[data-postishipping-service-selector]');
        if (!selector || !POSTISHIPPING_ADMIN_ORDER.availableServices) return;

        const currentCode = String(selector.value);
        selector.innerHTML = '';

        let found = false;
        POSTISHIPPING_ADMIN_ORDER.availableServices.forEach(function (svc) {
            const option = document.createElement('option');
            option.value = svc.shipping_method_code;
            const provider = svc.service_provider || '';
            const name = svc.name || '';
            option.textContent = provider + ': ' + name + ' (' + svc.shipping_method_code + ')';
            if (String(svc.shipping_method_code) === currentCode) {
                option.selected = true;
                found = true;
            }
            selector.append(option);
        });

        // If not found (shouldn't happen), select first
        if (!found && selector.options.length > 0) {
            selector.options[0].selected = true;
        }
    },
    /**
     * Handles service selector change - renders additional services from cached data
     */
    onServiceChange: function () {
        const selector = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('select[data-postishipping-service-selector]');
        if (!selector) return;

        const selectedCode = String(selector.value);
        const originalCode = String(postishipping.shippingMethod?.values?.shipping_method_code || '');
        const additionalServicesContainer = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('[data-postishipping-additional-services]');

        if (!additionalServicesContainer) return;

        if (selectedCode === originalCode) {
            // Reverted to original — restore original selected services and render from saved data
            postishipping.selectedServices = JSON.parse(JSON.stringify(postishipping._originalSelectedServices || []));
            postishipping.selectedServicesSpec = JSON.parse(JSON.stringify(postishipping._originalSelectedServicesSpec || {}));
            const data = JSON.parse(atob(postishipping.cartMeta.meta.shippingMethod));
            POSTISHIPPING_ADMIN_ORDER.renderAdditionalServices(data);
            POSTISHIPPING_ADMIN_ORDER.snapshotCustomForm();
        } else {
            // Different service — try to render from cached data
            let cachedService = null;
            if (POSTISHIPPING_ADMIN_ORDER.availableServices) {
                cachedService = POSTISHIPPING_ADMIN_ORDER.availableServices.find(function (svc) {
                    return String(svc.shipping_method_code) === selectedCode;
                });
            }

            if (cachedService) {
                if (cachedService.additional_services && cachedService.additional_services.length) {
                    // Render with no pre-checked services (new service, no saved selections)
                    postishipping.selectedServices = [];
                    postishipping.selectedServicesSpec = {};
                    POSTISHIPPING_ADMIN_ORDER.renderAdditionalServices(cachedService);
                } else {
                    additionalServicesContainer.innerHTML = '<span class="text-muted font-italic">' + (postishipping.strings?.noAdditionalServices || 'No additional services') + '</span>';
                }
            } else {
                // Services not loaded yet — show info text
                additionalServicesContainer.innerHTML = '<span class="text-muted font-italic">' + (postishipping.strings?.additionalServicesAfterSave || 'Additional services will be available after saving') + '</span>';
            }
        }

        POSTISHIPPING_ADMIN_ORDER.checkCustomFormDirty();
    },
    /**
     * Registers label using custom (form) values
     */
    registerCustomLabel: function (event) {
        const form = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('form');
        const formData = new FormData(form);
        formData.append('action', 'registerCustomLabel');
        formData.append('id_order', postishipping.order.id);

        // Add selected service code from the dropdown
        const selector = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('select[data-postishipping-service-selector]');
        if (selector) {
            formData.append('posti_service_code', selector.value);
        }

        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                POSTISHIPPING_ADMIN_ORDER.refreshBlock(e, e.content?.message || '');
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    },
    getPickupPointsBtn: function () {
        const modal = POSTISHIPPING_ADMIN_ORDER.modalPp;
        const addressInput = modal.find('#postishipping-address-input').val() || postishipping.shippingAddress;
        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: {
                address: addressInput,
                id_cart: postishipping.cartMeta.id_cart,
                provider: postishipping.shippingMethod.values.service_provider,
                action: 'searchPP'
            },
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                } else {
                    modal.find(".posti-pp-list").html(e.content);
                    POSTISHIPPING_ADMIN_ORDER.modalUpdBtnState();
                }
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        })
    },
    updatePickupPointBtn: function (event) {
        const modal = POSTISHIPPING_ADMIN_ORDER.modalPp;

        let selectedValue = '';
        let title = '';
        let address = '';

        // Try radio mode
        const selectedRadio = modal.find('input[type=radio].posti-radio:checked');
        if (selectedRadio.length) {
            selectedValue = selectedRadio.val();
            const labelEl = modal.find('label[for="' + selectedRadio.attr('id') + '"]').first();
            title = labelEl.find('.pickup-address-title').text();
            address = labelEl.find('.pickup-address').text();
        }

        // Try select/dropdown mode
        if (!selectedValue) {
            const selectEl = modal.find('select.postishipping-pickup-select');
            if (selectEl.length && selectEl.val()) {
                selectedValue = selectEl.val();
                const optionText = selectEl.find('option:selected').text();
                const colonIdx = optionText.indexOf(':');
                if (colonIdx > -1) {
                    title = optionText.substring(0, colonIdx).trim();
                    address = optionText.substring(colonIdx + 1).trim();
                } else {
                    title = optionText;
                }
            }
        }

        if (!selectedValue || selectedValue === 'no_pickup') {
            return;
        }

        const panel = POSTISHIPPING_ADMIN_ORDER.panel;
        const customSection = panel.querySelector('[data-postishipping-custom-section]');

        let ppInput = customSection.querySelector('input[name$="[pickup_point_id]"]');
        if (ppInput) ppInput.value = selectedValue;

        let ppTitle = customSection.querySelector('.posti-pp-title');
        let ppAddress = customSection.querySelector('.posti-pp-address');
        let ppContainer = customSection.querySelector('.posti-selected-pp');

        if (ppTitle) {
            ppTitle.innerText = title;
        }
        if (ppAddress) {
            ppAddress.innerText = address;
        }
        // If no structured elements yet (e.g. "Not selected" text), rebuild the container
        if (!ppTitle && ppContainer) {
            ppContainer.innerHTML = '<div class="posti-pp-title">' + title + '</div><div class="posti-pp-address">' + address + '</div>';
        }

        POSTISHIPPING_ADMIN_ORDER.checkCustomFormDirty();
    },
    modalUpdBtnState: function () {
        const updBtn = POSTISHIPPING_ADMIN_ORDER.modalPp.find('button[data-postishipping-update-pp]');
        // Radio list mode
        const isRadioChecked = POSTISHIPPING_ADMIN_ORDER.modalPp.find("input[type=radio].posti-radio:checked").length > 0;
        // Select/dropdown menu mode
        const selectEl = POSTISHIPPING_ADMIN_ORDER.modalPp.find("select.postishipping-pickup-select");
        const isSelectChosen = selectEl.length > 0 && !!selectEl.val() && selectEl.val() !== '';
        updBtn.prop("disabled", !isRadioChecked && !isSelectChosen);
    },
    saveMeta: function (event) {
        const form = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('form');
        const formData = new FormData(form);
        formData.append('action', 'saveMeta');
        formData.append('meta_id', postishipping.metaId);
        formData.append('id_order', postishipping.order.id);
        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                if (e.content?.changed && e.blockHtml) {
                    POSTISHIPPING_ADMIN_ORDER.refreshBlock(e, e.content?.message || '');
                } else {
                    POSTISHIPPING_ADMIN_ORDER.showSuccess(e.content?.message);
                }
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    },

    registerLabel: function (event) {
        const form = POSTISHIPPING_ADMIN_ORDER.panel.querySelector('form');
        const formData = new FormData(form);
        formData.append('action', 'registerLabel');
        formData.append('id_order', postishipping.order.id);
        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                POSTISHIPPING_ADMIN_ORDER.refreshBlock(e, e.content?.message || '');
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    },
    printLabel: function (event) {
        if (!event.target.dataset.postishippingPrintLabel) {
            return false;
        }

        const trackingCode = event.target.dataset.postishippingPrintLabel;

        const formData = new FormData();
        formData.append('action', 'printLabel');
        formData.append('tracking_code', trackingCode);

        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                POSTISHIPPING_ADMIN_ORDER.showSuccess(e.content?.message);
                if (e.content?.base64) {
                    POSTISHIPPING_ADMIN_ORDER.downloadPdf(`${trackingCode}_label.pdf`, e.content.base64);
                }
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    },

    downloadPdf: function (filename, base64) {
        const pdfContent = `data:application/pdf;base64,${base64}`;
        const encodedUri = encodeURI(pdfContent);
        const link = document.createElement("a");

        link.setAttribute("href", encodedUri);
        link.setAttribute("download", filename.replace('.pdf', '') + ".pdf");
        document.body.appendChild(link); // Required for FF
        // This will download the data file
        link.click();
        // cleanup
        link.remove();
    },

    deleteLabel: function (event) {
        const trackingCode = event.target.dataset.postishippingDeleteLabel;
        if (!trackingCode) {
            return false;
        }

        if (!window.confirm(postishipping.strings.deleteWarning || 'Are you sure you want to delete this label?')) {
            return false;
        }

        const formData = new FormData();
        formData.append('action', 'deleteLabel');
        formData.append('id_order', postishipping.order.id);
        formData.append('tracking_code', trackingCode);

        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                let msg = e.content?.message || '';
                if (e.content?.trackingCode) {
                    msg += ': <b>' + e.content.trackingCode + '</b>';
                }
                POSTISHIPPING_ADMIN_ORDER.refreshBlock(e, msg);
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    },

    registerReturnLabel: function (event) {
        const formData = new FormData();
        formData.append('action', 'registerReturnLabel');
        formData.append('id_order', postishipping.order.id);
        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                POSTISHIPPING_ADMIN_ORDER.refreshBlock(e, e.content?.message || '');
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    },

    deleteReturnLabel: function (event) {
        const trackingCode = event.target.dataset.postishippingDeleteReturnLabel;
        if (!trackingCode) {
            return false;
        }

        if (!window.confirm(postishipping.strings.deleteReturnWarning || 'Are you sure you want to delete this return label?')) {
            return false;
        }

        const formData = new FormData();
        formData.append('action', 'deleteReturnLabel');
        formData.append('id_order', postishipping.order.id);
        formData.append('tracking_code', trackingCode);

        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                let msg = e.content?.message || '';
                if (e.content?.trackingCode) {
                    msg += ': <b>' + e.content.trackingCode + '</b>';
                }
                POSTISHIPPING_ADMIN_ORDER.refreshBlock(e, msg);
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    },

    refreshLabel: function (event) {
        const trackingCode = event.target.dataset.postishippingRefreshLabel;
        if (!trackingCode) {
            return false;
        }

        const formData = new FormData();
        formData.append('action', 'refreshLabel');
        formData.append('id_order', postishipping.order.id);
        formData.append('tracking_code', trackingCode);

        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                // Update status text in the DOM
                const row = POSTISHIPPING_ADMIN_ORDER.panel.querySelector(
                    `.posti-registration-row[data-posti-tracking="${trackingCode}"]`
                );
                if (row && e.content?.status) {
                    const statusEl = row.querySelector('.posti-label-status');
                    if (statusEl) {
                        statusEl.textContent = e.content.status;
                    }
                }

                POSTISHIPPING_ADMIN_ORDER.showSuccess(e.content?.message || 'Status updated');
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    },

    toPickup: function (event) {
        const trackingCode = event.target.dataset.postishippingPickupLabel;
        if (!trackingCode) {
            return false;
        }

        const formData = new FormData();
        formData.append('action', 'toPickup');
        formData.append('id_order', postishipping.order.id);
        formData.append('tracking_code', trackingCode);

        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: formData,
            processData: false,
            contentType: false,
            method: 'POST',
            beforeSend: function () {
                POSTISHIPPING_ADMIN_ORDER.hideErrors(),
                    POSTISHIPPING_ADMIN_ORDER.hideSuccess(),
                    POSTISHIPPING_ADMIN_ORDER.showOverlay();
            },
            success: function (e) {
                if (e.error) {
                    POSTISHIPPING_ADMIN_ORDER.showErrors(e.error);
                    return;
                }

                POSTISHIPPING_ADMIN_ORDER.showSuccess(e.content?.message || 'Added to pickup');

                // Add checkmark to the button
                let $btn = $(event.target).closest('button[data-postishipping-pickup-label]');
                if ($btn.length && !$btn.find('.posti-pickup-check').length) {
                    $btn.prepend('<span style="color: inherit" class="posti-pickup-check">&#10003;</span> ');
                }
            },
            complete: function () {
                POSTISHIPPING_ADMIN_ORDER.hideOverlay();
            }
        });

        return false;
    }
};

document.addEventListener('DOMContentLoaded', function (ev) {
    const panel = document.getElementById('order-postishipping');

    if (!panel) {
        return;
    }

    POSTISHIPPING_ADMIN_ORDER.init(panel);
});