/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function () {
  if (!window.brzSettings || !window.brzSettings.ajaxUrl) {
    return;
  }

  var forms = document.querySelectorAll('.brz-toggle-form');
  if (!forms.length) {
    return;
  }

  var toast = document.getElementById('brz-snackbar');
  var toastTimer;

  function showToast(message, isError) {
    if (!toast) {
      return;
    }
    toast.textContent = message;
    toast.style.background = isError ? '#be123c' : '#0f172a';
    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toast.classList.remove('is-visible');
    }, 2600);
  }

  function updateCardState(card, newState) {
    var toggle = card.querySelector('.brz-toggle-switch');
    var label = card.querySelector('.brz-toggle-label');
    var hiddenState = card.querySelector('input[name="state"]');

    if (newState) {
      card.classList.add('is-active');
      if (toggle) { toggle.classList.add('is-on'); toggle.classList.remove('is-off'); }
      if (label) { label.textContent = 'روشن'; }
      if (hiddenState) { hiddenState.value = '0'; }
    } else {
      card.classList.remove('is-active');
      if (toggle) { toggle.classList.remove('is-on'); toggle.classList.add('is-off'); }
      if (label) { label.textContent = 'خاموش'; }
      if (hiddenState) { hiddenState.value = '1'; }
    }
  }

  forms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var card = form.closest('.brz-module-card');
      var moduleSlug = form.dataset.module || (card && card.dataset.module) || '';
      var hiddenState = form.querySelector('input[name="state"]');
      var nonceField = form.querySelector('[name="' + (brzSettings.nonceField || '_wpnonce') + '"]');
      var sendState = hiddenState ? hiddenState.value : '';

      if (!moduleSlug || sendState === '') {
        showToast(brzSettings.failText || 'خطا در داده‌ها', true);
        return;
      }

      var payload = new FormData();
      payload.append('action', 'brz_toggle_module');
      payload.append('module', moduleSlug);
      payload.append('state', sendState);
      if (nonceField) {
        payload.append(brzSettings.nonceField || '_wpnonce', nonceField.value);
      }

      fetch(brzSettings.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: payload
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('bad_status');
        }
        return response.json();
      }).then(function (json) {
        if (!json || !json.success || !json.data) {
          throw new Error('bad_response');
        }
        var newState = json.data.state ? 1 : 0;
        if (card) {
          updateCardState(card, newState);
        }
        showToast(newState ? (brzSettings.successOn || 'ماژول فعال شد') : (brzSettings.successOff || 'ماژول غیرفعال شد'));
      }).catch(function () {
        showToast(brzSettings.failText || 'تغییر وضعیت انجام نشد. دوباره تلاش کنید.', true);
      });
    });
  });
})();
