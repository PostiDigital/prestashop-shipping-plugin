(function() {
    'use strict';

    const PostiPickups = {
        getTodayStr: function() {
            let now = new Date();
            let y = now.getFullYear();
            let m = String(now.getMonth() + 1).padStart(2, '0');
            let d = String(now.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + d;
        },

        getCurrentTimeStr: function() {
            let now = new Date();
            return String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        },

        showModalError: function(msg) {
            let el = document.querySelector('.pickup-modal-error');
            if (el) {
                el.querySelector('p').textContent = msg;
                el.style.display = '';
            }
        },

        hideModalError: function() {
            let el = document.querySelector('.pickup-modal-error');
            if (el) {
                el.style.display = 'none';
            }
        },

        showSuccess: function(msg) {
            let el = document.querySelector('.posti-pickups-success');
            if (el) {
                el.querySelector('p').innerHTML = msg;
                el.style.display = '';
            }
        },

        showError: function(msg) {
            let el = document.querySelector('.posti-pickups-errors');
            if (el) {
                el.querySelector('p').textContent = msg;
                el.style.display = '';
            }
        },

        initModal: function() {
            let self = this;

            document.addEventListener('click', function(e) {
                let btn = e.target.closest('[data-postishipping-place-pickup]');
                if (!btn) return;

                let manifestId = btn.getAttribute('data-postishipping-place-pickup');
                document.getElementById('pickup-manifest-id').value = manifestId;

                let dateInput = document.getElementById('pickup-date');
                dateInput.min = self.getTodayStr();
                dateInput.value = self.getTodayStr();

                document.getElementById('pickup-time-from').value = '';
                document.getElementById('pickup-time-to').value = '';
                document.getElementById('pickup-additional-info').value = '';
                self.hideModalError();
            });

            let dateInput = document.getElementById('pickup-date');
            if (dateInput) {
                dateInput.addEventListener('change', function() {
                    let timeFrom = document.getElementById('pickup-time-from');
                    if (this.value === self.getTodayStr()) {
                        timeFrom.min = self.getCurrentTimeStr();
                    } else {
                        timeFrom.min = '00:00';
                    }
                });
            }

            let timeFromInput = document.getElementById('pickup-time-from');
            if (timeFromInput) {
                timeFromInput.addEventListener('change', function() {
                    let timeTo = document.getElementById('pickup-time-to');
                    timeTo.min = this.value;
                    if (timeTo.value && timeTo.value <= this.value) {
                        timeTo.value = '';
                    }
                });
            }
        },

        initSubmit: function() {
            let self = this;
            let submitBtn = document.getElementById('pickup-submit-btn');
            if (!submitBtn) return;

            submitBtn.addEventListener('click', function() {
                self.hideModalError();

                let manifestId = document.getElementById('pickup-manifest-id').value;
                let date = document.getElementById('pickup-date').value;
                let timeFrom = document.getElementById('pickup-time-from').value;
                let timeTo = document.getElementById('pickup-time-to').value;
                let additionalInfo = document.getElementById('pickup-additional-info').value;

                if (!date || !timeFrom || !timeTo) {
                    self.showModalError(postiPickupsTranslations.fillRequired);
                    return;
                }

                if (date === self.getTodayStr() && timeFrom < self.getCurrentTimeStr()) {
                    self.showModalError(postiPickupsTranslations.timeInPast);
                    return;
                }

                if (timeTo <= timeFrom) {
                    self.showModalError(postiPickupsTranslations.timeAfter);
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.textContent = postiPickupsTranslations.processing;

                let formData = new FormData();
                formData.append('action', 'placePickupOrder');
                formData.append('manifest_id', manifestId);
                formData.append('date', date);
                formData.append('time_from', timeFrom);
                formData.append('time_to', timeTo);
                formData.append('additional_info', additionalInfo);

                fetch(postiPickupsAjaxUrl, {
                    method: 'POST',
                    body: formData,
                })
                .then(function(resp) { return resp.json(); })
                .then(function(data) {
                    if (data.error) {
                        self.showModalError(data.error);
                        submitBtn.disabled = false;
                        submitBtn.textContent = postiPickupsTranslations.submit;
                        return;
                    }

                    let modal = document.getElementById('postishipping-pickup-modal');
                    if (modal && typeof $ !== 'undefined') {
                        $(modal).modal('hide');
                    }

                    let row = document.querySelector('tr[data-manifest-id="' + manifestId + '"]');
                    if (row) {
                        let statusBadge = row.querySelector('.manifest-status');
                        if (statusBadge) {
                            statusBadge.className = 'badge badge-secondary manifest-status';
                            statusBadge.textContent = postiPickupsTranslations.closed;
                        }
                        let pickupTimeCell = row.querySelector('.manifest-pickup-time');
                        if (pickupTimeCell && data.content && data.content.pickupTime) {
                            pickupTimeCell.textContent = data.content.pickupTime;
                        }
                        let actionsCell = row.querySelector('.manifest-actions');
                        if (actionsCell) {
                            actionsCell.textContent = postiPickupsTranslations.pickupPlaced;
                        }
                    }

                    self.showSuccess(data.content ? data.content.message : postiPickupsTranslations.pickupSuccess);

                    submitBtn.disabled = false;
                    submitBtn.textContent = postiPickupsTranslations.submit;
                })
                .catch(function(err) {
                    self.showModalError(err.message || 'Request failed');
                    submitBtn.disabled = false;
                    submitBtn.textContent = postiPickupsTranslations.submit;
                });
            });
        },

        initCancel: function() {
            let self = this;

            document.addEventListener('click', function(e) {
                let btn = e.target.closest('[data-postishipping-cancel-pickup]');
                if (!btn) return;

                if (!confirm(postiPickupsTranslations.cancelConfirm)) {
                    return;
                }

                let manifestId = btn.getAttribute('data-postishipping-cancel-pickup');
                btn.disabled = true;

                let formData = new FormData();
                formData.append('action', 'cancelPickupOrder');
                formData.append('manifest_id', manifestId);

                fetch(postiPickupsAjaxUrl, {
                    method: 'POST',
                    body: formData,
                })
                .then(function(resp) { return resp.json(); })
                .then(function(data) {
                    if (data.error) {
                        self.showError(data.error);
                        btn.disabled = false;
                        return;
                    }

                    let row = document.querySelector('tr[data-manifest-id="' + manifestId + '"]');
                    if (row) {
                        let statusBadge = row.querySelector('.manifest-status');
                        if (statusBadge) {
                            statusBadge.className = 'badge badge-danger manifest-status';
                            statusBadge.textContent = postiPickupsTranslations.canceled;
                        }
                        let pickupTimeCell = row.querySelector('.manifest-pickup-time');
                        if (pickupTimeCell) {
                            pickupTimeCell.textContent = '-';
                        }
                        let actionsCell = row.querySelector('.manifest-actions');
                        if (actionsCell && data.content && data.content.canceledBy) {
                            actionsCell.textContent = postiPickupsTranslations.canceledByText.replace('%name%', data.content.canceledBy);
                        }
                    }

                    self.showSuccess(data.content ? data.content.message : postiPickupsTranslations.cancelSuccess);
                })
                .catch(function(err) {
                    self.showError(err.message || 'Request failed');
                    btn.disabled = false;
                });
            });
        },

        initFilterDateSync: function() {
            let pairs = [
                ['filter_title_from', 'filter_title_to'],
                ['filter_pickup_from', 'filter_pickup_to']
            ];
            pairs.forEach(function(pair) {
                let fromEl = document.getElementById(pair[0]);
                let toEl = document.getElementById(pair[1]);
                if (!fromEl || !toEl) return;

                function syncMin() {
                    toEl.min = fromEl.value || '';
                    if (toEl.value && fromEl.value && toEl.value < fromEl.value) {
                        toEl.value = '';
                    }
                }

                fromEl.addEventListener('change', syncMin);
                syncMin();
            });
        },

        init: function() {
            this.initModal();
            this.initSubmit();
            this.initCancel();
            this.initFilterDateSync();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { PostiPickups.init(); });
    } else {
        PostiPickups.init();
    }
})();
