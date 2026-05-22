/**
 * "Other" package flow for Record Application Payment modal.
 * Expects #paymentModal, #package_select, #customPackageFields, etc.
 */
(function ($) {
  'use strict';

  const CUSTOM_ITEM_KEY = 'custom';

  function escHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function isOtherPackageSelected() {
    return $('#package_select').val() === 'other';
  }

  function getCustomPackageData() {
    return {
      name: ($('#custom_package_name').val() || '').trim(),
      itemName: ($('#custom_item_name').val() || '').trim(),
      currency: ($('#custom_package_currency').val() || 'CAD').trim(),
      total: Number($('#custom_package_amount').val() || 0)
    };
  }

  window.refreshCustomPackageUI = function (itemPaymentsRef, updateGrandTotal) {
    const data = getCustomPackageData();
    if (!data.name || data.total <= 0) {
      $('#expected_total, #paid_total, #remaining_total').val('');
      $('#feeItemsWrapper').html(
        '<div class="text-muted text-center py-4">Fill in package name and proposed price above</div>'
      );
      return '';
    }

    window.paymentCurrency = data.currency;
    $('#expected_total').val(`${data.currency} ${data.total.toFixed(2)}`);
    $('#paid_total').val(`${data.currency} 0.00`);
    $('#remaining_total').val(`${data.currency} ${data.total.toFixed(2)}`);

    const itemLabel = escHtml(data.itemName || data.name);
    const html = `
      <div class="list-group list-group-flush">
        <div class="list-group-item py-3">
          <div class="row align-items-center">
            <div class="col-md-5">
              <strong>${itemLabel}</strong><br>
              <small class="text-muted">Remaining: ${escHtml(data.currency)} ${data.total.toFixed(2)}</small>
            </div>
            <div class="col-md-4">
              <input type="number" class="form-control form-control-sm item-payment-input"
                min="0.01" max="${data.total}" step="0.01" data-item-id="${CUSTOM_ITEM_KEY}" data-max="${data.total}"
                placeholder="0.00">
            </div>
            <div class="col-md-3 text-end">
              <span class="badge bg-warning text-dark">Other</span>
            </div>
          </div>
        </div>
      </div>`;
    $('#feeItemsWrapper').html(html);

    if (itemPaymentsRef && itemPaymentsRef[CUSTOM_ITEM_KEY]) {
      $('.custom-item-payment-input').val(Number(itemPaymentsRef[CUSTOM_ITEM_KEY]).toFixed(2));
    }

    if (typeof updateGrandTotal === 'function') {
      updateGrandTotal();
    }

    return data.currency;
  };

  window.resetCustomPackageFields = function () {
    $('#customPackageFields').addClass('d-none');
    $('#custom_package_name, #custom_item_name, #custom_package_amount').val('');
    $('#custom_package_currency').val('CAD');
  };

  window.appendOtherPackageOption = function (pkgOptionsHtml) {
    return (
      pkgOptionsHtml +
      '<option value="other">Other (not listed) — enter manually</option>'
    );
  };

  window.buildCustomPaymentPayload = function (basePayload, itemPayments) {
    const data = getCustomPackageData();
    if (!data.name) {
      alert('Please enter a package / service name');
      return null;
    }
    if (data.total <= 0) {
      alert('Please enter a valid proposed total price');
      return null;
    }
    const pay = Number(itemPayments.custom || 0);
    if (pay <= 0) {
      alert('Please enter the amount you are recording now');
      return null;
    }
    if (pay > data.total) {
      alert('Payment cannot exceed the proposed total price');
      return null;
    }

    basePayload.custom_package = true;
    basePayload.custom_title = data.name;
    basePayload.custom_item_name = data.itemName || data.name;
    basePayload.custom_currency = data.currency;
    basePayload.custom_amount = data.total;
    basePayload.package_id = 0;
    basePayload.items = { custom: pay };
    return basePayload;
  };

  window.isOtherPackageSelected = isOtherPackageSelected;

  $(document).on('input', '#custom_package_name, #custom_item_name, #custom_package_currency, #custom_package_amount', function () {
    if (!isOtherPackageSelected()) return;
    if (typeof window._paymentOtherRefresh === 'function') {
      window._paymentOtherRefresh();
    }
  });
})(jQuery);
