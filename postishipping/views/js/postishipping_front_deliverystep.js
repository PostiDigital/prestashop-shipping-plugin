(() => {
    "use strict";

    postishipping.isPrivatePickupPoint = function (element) {
        let pointType = '';
        if (element.tagName === 'SELECT') {
            let selectedOption = element.options[element.selectedIndex];
            pointType = selectedOption ? selectedOption.getAttribute('data-point-type') : '';
        } else {
            pointType = element.getAttribute('data-point-type') || '';
        }
        return pointType === 'PRIVATE_LOCKER';
    };

    postishipping.confirmPrivatePickupPoint = function (element, previousValue) {
        if (!postishipping.isPrivatePickupPoint(element)) {
            return true;
        }
        let confirmed = confirm(postishipping.privatePickupPointConfirm || 'The pickup point you have chosen is a private locker. You will need a PIN code to access it. Do you want to continue?');
        if (!confirmed) {
            if (element.tagName === 'SELECT') {
                element.value = previousValue || 'no_pickup';
            } else {
                element.checked = false;
            }
            return false;
        }
        return true;
    };

    postishipping.initFilter = function (cid) {
        let filter = $(`#postishipping-wrapper-${cid} div.posti-filters`);
        filter.on("click", "button.posti", (function () {
            postishipping.getPickupPoints(this.dataset.postishippingTarget)
        }))
    };

    postishipping.getPickupPoints = function (cid) {
        const errorEl = $(`#postishipping-wrapper-${cid} div.postishipping.posti-errors`);
        const overlay = $(`#postishipping-wrapper-${cid} div.overlay`);
        const addressInput = document.querySelector(`#postishipping-wrapper-${cid} input[name=postishipping_address]`);
        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: {
                action: "getPickupPoints",
                address: addressInput.value,
                provider: postishipping.carriersConfig[cid].provider
            },
            beforeSend: function () {
                errorEl.slideUp(300),
                    overlay.show();
            },
            success: function (response) {
                response.error
                    ? (errorEl.find("p").text(response.error), errorEl.slideDown(300))
                    : "function" == typeof postishipping.showList && postishipping.showList(response.content, cid);
            },
            complete: function () {
                overlay.hide();
                postishipping.enableDisableConfirmBtn(cid);
            }
        })
    };
    postishipping.savePickupPointId = function (id, cid) {
        const errorEl = $(`#postishipping-wrapper-${cid} div.postishipping.posti-errors`);
        const overlay = $(`#postishipping-wrapper-${cid} div.overlay`);
        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: {
                action: "savePickupPointId",
                pickup_point_id: id,
                carrier_id: cid,
            },
            beforeSend: function () {
                errorEl.slideUp(300),
                    overlay.show();
            },
            success: function (response) {
                response.error
                    ? (errorEl.find("p").text(response.error), errorEl.slideDown(300))
                    : "";
            },
            complete: function () {
                overlay.hide(),
                    postishipping.enableDisableConfirmBtn(cid);
            }
        })
    };
    postishipping.clearPickupPoint = function (cid) {
        const errorEl = $(`#postishipping-wrapper-${cid} div.postishipping.posti-errors`);
        const overlay = $(`#postishipping-wrapper-${cid} div.overlay`);
        $.ajax({
            url: postishipping.ajaxController,
            dataType: "json",
            data: {
                action: "clearPickupPoint",
                carrier_id: cid,
            },
            beforeSend: function () {
                errorEl.slideUp(300);
                overlay.show();
            },
            success: function (response) {
                response.error
                    ? (errorEl.find("p").text(response.error), errorEl.slideDown(300))
                    : "";
            },
            complete: function () {
                overlay.hide();
                postishipping.enableDisableConfirmBtn(cid);
            }
        })
    };
    postishipping.enableDisableConfirmBtn = function (event) {
        let cid = 'string' === typeof event ? event : '';
        if ('object' === typeof event) {
            cid = event?.data?.cid;
        }
        let list = $(`div#postishipping-wrapper-${cid} div.postishipping-list`),
            confirmButton = $("button[name=confirmDeliveryOption]"),
            isDeliveryOption = 0 !== $("input#delivery_option_" + cid + ":checked").length,
            isPointChecked = 0 !== list.find("input[type=radio]:checked").length;

        if (!isPointChecked) {
            let selectEl = list.find("select[data-postishipping-select]");
            if (selectEl.length && selectEl.val()) {
                isPointChecked = true;
            }
        }

        confirmButton.prop("disabled", isDeliveryOption && !isPointChecked).text(isDeliveryOption && !isPointChecked ? postishipping.selectPickupText : postishipping.continueText);
    };

    postishipping.initPointsWrapper = function (cid) {
        if (!postishipping.carriersConfig[cid]) {
            return;
        }
        if (!postishipping.carriersConfig[cid].initialized) {
            postishipping.initFilter(cid);
            const addressInput = document.querySelector(`#postishipping-wrapper-${cid} input[name=postishipping_address]`);
            if (!addressInput.value) {
                addressInput.value = postishipping.selectedAddress;
            }

            postishipping.getPickupPoints(cid);
            postishipping.carriersConfig[cid].initialized = true;
        }
    };
    postishipping.showList = function (points, cid) {
        const listEl = document.querySelector(`#postishipping-wrapper-${cid} .postishipping-list`);
        listEl.innerHTML = points;
        // Track initial value of select for private locker revert
        let selectEl = listEl.querySelector('select[data-postishipping-select]');
        if (selectEl) {
            selectEl._prevValue = selectEl.value || 'no_pickup';
        }
    };
    postishipping.selectPickupPoint = function (event) {
        const cid = event.target.closest('.postishipping-list').dataset.postishippingCid;
        if (event.target.value === 'no_pickup') {
            postishipping.clearPickupPoint(cid);
        } else {
            if (!postishipping.confirmPrivatePickupPoint(event.target, null)) {
                return false;
            }
            postishipping.savePickupPointId(event.target.value, cid);
        }
        return false; // prevent bubling
    };
    postishipping.selectPickupPointFromDropdown = function (event) {
        const cid = event.target.closest('.postishipping-list').dataset.postishippingCid;
        let val = event.target.value;
        if (val === 'no_pickup') {
            postishipping.clearPickupPoint(cid);
        } else if (val) {
            if (!postishipping.confirmPrivatePickupPoint(event.target, event.target._prevValue || 'no_pickup')) {
                return false;
            }
            postishipping.savePickupPointId(val, cid);
        }
        event.target._prevValue = val;
        return false;
    };

    document.addEventListener("DOMContentLoaded", (function () {
        document.querySelectorAll("[data-postishipping-wrapper]").forEach(function (wrapper) {
            $(wrapper).find("div.postishipping-list")
                .on("change", "input[type=radio]", postishipping.selectPickupPoint)
                .on("change", "select[data-postishipping-select]", postishipping.selectPickupPointFromDropdown);
        });
        $("input[id^=delivery_option_]")
            .on("change", (function (e) {
                let cid = e.target.id.replace('delivery_option_', '');
                if (postishipping.carriersConfig.hasOwnProperty(cid)) {
                    postishipping.initPointsWrapper(cid);
                    postishipping.enableDisableConfirmBtn(cid);
                } else {
                    // Selected carrier is not a Posti pickup point carrier — restore confirm button
                    let confirmButton = $("button[name=confirmDeliveryOption]");
                    confirmButton.prop("disabled", false).text(postishipping.continueText);
                }
            }))
            .filter(":checked").trigger("change");
    }))
})();
