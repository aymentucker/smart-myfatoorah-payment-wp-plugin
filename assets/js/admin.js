(function ($) {
  "use strict";

  function runAdminAction(
    buttonSelector,
    resultSelector,
    action,
    busyText,
    failFallback
  ) {
    var $button = $(buttonSelector);
    var $result = $(resultSelector);

    $button.prop("disabled", true);
    $result.removeClass("smf-test-ok smf-test-error").text(busyText);

    $.post(SMFAdmin.ajaxUrl, {
      action: action,
      nonce: SMFAdmin.nonce
    })
      .done(function (response) {
        if (response && response.success) {
          $result.addClass("smf-test-ok").text(response.data.message);
        } else {
          $result
            .addClass("smf-test-error")
            .text(
              response && response.data ? response.data.message : failFallback
            );
        }
      })
      .fail(function (xhr) {
        var message = failFallback;
        if (
          xhr.responseJSON &&
          xhr.responseJSON.data &&
          xhr.responseJSON.data.message
        ) {
          message = xhr.responseJSON.data.message;
        }
        $result.addClass("smf-test-error").text(message);
      })
      .always(function () {
        $button.prop("disabled", false);
      });
  }

  function syncQatarOnlySettings() {
    var $country = $("#woocommerce_smart_myfatoorah_merchant_country");
    if (!$country.length) {
      return;
    }
    var isQatar = String($country.val() || "") === "QAT";
    $("#woocommerce_smart_myfatoorah_qpay_label").closest("tr").toggle(isQatar);
  }

  function syncStyleCustomSettings() {
    var $mode = $("#woocommerce_smart_myfatoorah_style_mode");
    if (!$mode.length) {
      return;
    }
    var isCustom = String($mode.val() || "") === "custom";
    $('[data-smf-style-custom="1"]').closest("tr").toggle(isCustom);
    $("tr.smf-style-custom").toggle(isCustom);
    syncCustomFontField();
  }

  function syncCustomFontField() {
    var $family = $("#woocommerce_smart_myfatoorah_style_font_family");
    var $custom = $("#woocommerce_smart_myfatoorah_style_font_custom");
    if (!$family.length || !$custom.length) {
      return;
    }
    var modeCustom =
      String($("#woocommerce_smart_myfatoorah_style_mode").val() || "") ===
      "custom";
    var show = modeCustom && String($family.val() || "") === "custom";
    $custom.closest("tr").toggle(show);
  }

  function syncDescriptionMode() {
    var $mode = $("#woocommerce_smart_myfatoorah_description_mode");
    if (!$mode.length) {
      return;
    }
    var isCustom = String($mode.val() || "") === "custom";
    $("#woocommerce_smart_myfatoorah_description").closest("tr").toggle(isCustom);
  }

  function syncDisplayLayoutFields() {
    var $style = $("#woocommerce_smart_myfatoorah_route_display_style");
    if (!$style.length) {
      return;
    }
    var isLogos = String($style.val() || "") === "logos";
    $("#woocommerce_smart_myfatoorah_logo_layout").closest("tr").toggle(isLogos);
    $("#woocommerce_smart_myfatoorah_text_layout").closest("tr").toggle(!isLogos);
  }

  function initSettingsTabs() {
    var $wrap = $(".smf-gateway-settings");
    if (!$wrap.length) {
      return;
    }

    $wrap.on("click", ".smf-settings-tabs .nav-tab", function (event) {
      event.preventDefault();
      var tab = String($(this).data("smf-tab") || "settings");

      $wrap
        .find(".smf-settings-tabs .nav-tab")
        .removeClass("nav-tab-active")
        .attr("aria-selected", "false");
      $(this).addClass("nav-tab-active").attr("aria-selected", "true");

      $wrap.find(".smf-tab-panel").removeClass("is-active").attr("hidden", "hidden");
      $wrap
        .find('.smf-tab-panel[data-smf-panel="' + tab + '"]')
        .addClass("is-active")
        .removeAttr("hidden");
    });
  }

  function bindColorPickers() {
    $(document).on("input change", ".smf-color-picker", function () {
      var target = $(this).data("smf-color-target");
      if (!target) {
        return;
      }
      $("#" + target)
        .val($(this).val())
        .trigger("change");
    });

    $(document).on("input change", ".smf-color-text", function () {
      var val = String($(this).val() || "").trim();
      var $picker = $(this)
        .closest(".smf-color-field")
        .find(".smf-color-picker");
      if (/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/.test(val)) {
        if (val.length === 4) {
          val = "#" + val[1] + val[1] + val[2] + val[2] + val[3] + val[3];
        }
        $picker.val(val.toLowerCase());
      }
    });
  }

  $(document).on("click", "#smf-test-connection", function () {
    runAdminAction(
      "#smf-test-connection",
      "#smf-test-result",
      "smf_test_connection",
      SMFAdmin.testing,
      "Connection failed."
    );
  });

  $(document).on("click", "#smf-register-apple-pay", function () {
    runAdminAction(
      "#smf-register-apple-pay",
      "#smf-apple-pay-result",
      "smf_register_apple_pay",
      SMFAdmin.registeringApplePay || "Registering…",
      "Apple Pay domain registration failed."
    );
  });

  $(document).on(
    "change",
    "#woocommerce_smart_myfatoorah_merchant_country",
    syncQatarOnlySettings
  );
  $(document).on(
    "change",
    "#woocommerce_smart_myfatoorah_style_mode",
    syncStyleCustomSettings
  );
  $(document).on(
    "change",
    "#woocommerce_smart_myfatoorah_style_font_family",
    syncCustomFontField
  );
  $(document).on(
    "change",
    "#woocommerce_smart_myfatoorah_description_mode",
    syncDescriptionMode
  );
  $(document).on(
    "change",
    "#woocommerce_smart_myfatoorah_route_display_style",
    syncDisplayLayoutFields
  );

  $(function () {
    syncQatarOnlySettings();
    syncStyleCustomSettings();
    syncDescriptionMode();
    syncDisplayLayoutFields();
    initSettingsTabs();
    bindColorPickers();
  });
})(jQuery);
