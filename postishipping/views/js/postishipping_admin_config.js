const POSTISHIPPING_ADMIN = {
    initialService: null,
    capitalize: function(string) {
        string = string.replaceAll('_', ' ');
        return string.charAt(0).toUpperCase() + string.slice(1);
    },
    getSelectedServiceSpec: function(addSeriveId, spec) {
        if (!window['PostiShippingSelectedAddServicesSpec']) {
            return '';
        }

        if (PostiShippingSelectedAddServicesSpec.hasOwnProperty(addSeriveId)
            && PostiShippingSelectedAddServicesSpec[addSeriveId].hasOwnProperty(spec)) {
            return PostiShippingSelectedAddServicesSpec[addSeriveId][spec];
        }

        return '';
    },
    renderAdditionalServices: function (data, parent) {
        const selectedServices = window['PostiShippingSelectedAddServices'] ? PostiShippingSelectedAddServices : [];

        if (data.additional_services) {
            let additionalServicesContainer = document.querySelector('[data-postishipping-additional-services]');
            if (!additionalServicesContainer) {
                additionalServicesContainer = document.createElement('div');
                additionalServicesContainer.dataset.postishippingAdditionalServices = '';
            }

            additionalServicesContainer.innerHTML = '';
            data.additional_services.forEach(element => {
                const label = document.createElement('label');
                label.innerText = element.name;
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'posti_additional_service[]';
                input.value = element.service_code;
                input.checked = POSTISHIPPING_ADMIN.initialService === data.shipping_method_code.toString()
                    ? selectedServices.includes(element.service_code)
                    : (window['PostiShippingPickupPointServiceCode'] && element.service_code === PostiShippingPickupPointServiceCode);
                label.prepend(input);
                additionalServicesContainer.append(label);
            });
            parent.append(additionalServicesContainer);
        }
    },
    updateAvailableCountries: function (data) {
        const availableCountriesEl = document.getElementById('posti_supported_countries_container');
        if (!availableCountriesEl) {
            return;
        }

        if (data.supported_countries) {
            availableCountriesEl.innerText = data.supported_countries.join(', ');
        }
    }
};

document.addEventListener('click', function (ev) {
    const btn = ev.target.closest('.posti-shortcode-btn');
    if (!btn) {
        return;
    }
    const textarea = document.getElementById('POSTISHIPPING_LABEL_ADDITIONAL_INFO');
    if (!textarea) {
        return;
    }
    const shortcode = btn.dataset.shortcode;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    textarea.value = textarea.value.substring(0, start) + shortcode + textarea.value.substring(end);
    textarea.selectionStart = textarea.selectionEnd = start + shortcode.length;
    textarea.focus();
});

document.addEventListener('DOMContentLoaded', function (ev) {
    const serviceEl = document.getElementById('service_base64');
    const serviceSelectorEl = document.getElementById('id_service');

    if (!serviceEl || !serviceSelectorEl) {
        return;
    }

    if (!serviceEl.value) {
        serviceEl.value = PostiShippingMethods[serviceSelectorEl.value];
    }

    POSTISHIPPING_ADMIN.initialService = serviceSelectorEl.value;

    const data = JSON.parse(atob(serviceEl.value));
    POSTISHIPPING_ADMIN.renderAdditionalServices(data, serviceSelectorEl.parentElement);
    POSTISHIPPING_ADMIN.updateAvailableCountries(data);
});

document.addEventListener('change', function (ev) {
    if (!window['PostiShippingMethods']) {
        return;
    }

    const serviceEl = document.getElementById('service_base64');
    if (!serviceEl || ev.target.id !== 'id_service') {
        return;
    }

    serviceEl.value = PostiShippingMethods[ev.target.value];

    const data = JSON.parse(atob(PostiShippingMethods[ev.target.value]));

    POSTISHIPPING_ADMIN.renderAdditionalServices(data, ev.target.parentElement);
    POSTISHIPPING_ADMIN.updateAvailableCountries(data);
});