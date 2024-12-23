jQuery(function ($) {
  const solid_gateway = {
    _form: null,
    _params: null,
    _modal: null,
    initModal: function () {
      this._modal = $("#solid-checkout-modal");
      this._modal.appendTo("body").modal({
        escapeClose: false,
        clickClose: false,
        showClose: true,
      });
    },
    initForm: function (params) {
      console.log("🚀 ~ solid_gateway.params:", params);
      this._params = params;
      this._form = PaymentFormSdk.init({
        iframeParams: {
          width: "100%",
          containerId: "solid-payment-form-container",
        },
        merchantData: params.form,
      });
    },
    attachEvents: function () {
      this._form.on("success", () => {
        window.location.href = this._params.redirects.success_url;
      });
      this._form.on("fail", () => {
        window.location.href = this._params.redirects.fail_url;
      });
    },
  };

  let $body = $(document.body);
  let $form = $("form.woocommerce-checkout");
  let $order_review = $("#order_review");

  //let reload_checkout = 0

  /**
   * Stop processing
   */
  function stopProcessing() {
    $.unblockUI();
    $(".blockUI.blockOverlay").hide();
  }

  $form.on("checkout_place_order", function () {
    $.ajax({
      type: "POST",
      url: wc_checkout_params.checkout_url,
      data: $form.serialize(),
      dataType: "json",
      success: function (orderSubmission) {
        processWooCommerceOrderSubmissionSuccess(orderSubmission, () => {});
      },
      error: function (jqXHR, textStatus, errorThrown) {
        displayWooCommerceError(
          `<div class="woocommerce-error">${errorThrown}</div>`
        );
      },
    });
    return false;
  });

  function processWooCommerceOrderSubmissionSuccess(orderSubmission, resolve) {
    console.log("🚀 ~ orderSubmission", orderSubmission)
    //reload_checkout = orderSubmission.reload === true ? 1 : 0
    if (orderSubmission.result === "success") {
      solid_gateway.initForm(orderSubmission);
      solid_gateway.attachEvents();
      solid_gateway.initModal();

      return resolve(true);
    }

    if (
      orderSubmission.result === "fail" ||
      orderSubmission.result === "failure"
    ) {
      stopProcessing();
      resolve(false);

      if (!orderSubmission.messages) {
        orderSubmission.messages = `<div class="woocommerce-error">${wc_checkout_params.i18n_checkout_error}</div>`;
      }

      displayWooCommerceError(orderSubmission.messages);
      return false;
    }

    stopProcessing();
    resolve(false);
    displayWooCommerceError(
      '<div class="woocommerce-error">Invalid response</div>'
    );
  }

  /**
   * Show validation errors
   * @param errorMessage
   */
  function displayWooCommerceError(errorMessage) {
    let payment_form = $form.length ? $form : $order_review;

    $(
      ".woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message"
    ).remove();
    payment_form.prepend(
      `<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">${errorMessage}</div>`
    ); // eslint-disable-line max-len
    payment_form.removeClass("processing").unblock();
    payment_form
      .find(".input-text, select, input:checkbox")
      .trigger("validate")
      .blur();
    var scrollElement = $(
      ".woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout"
    );
    if (!scrollElement.length) {
      scrollElement = $(".form.checkout");
    }
    $.scroll_to_notices(scrollElement);
    $(document.body).trigger("checkout_error");
  }
});
