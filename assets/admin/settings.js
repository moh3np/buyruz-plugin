/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function () {
  var settings = window.brzSettings || {};
  if (!settings.ajaxUrl) {
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

  function applyBrandColor(color) {
    if (!color) {
      return;
    }
    var wrap = document.querySelector('.brz-admin-wrap');
    if (wrap) {
      wrap.style.setProperty('--brz-brand', color);
    }
  }

  function setBusy(btn, isBusy) {
    if (!btn) {
      return;
    }
    var isInput = btn.tagName === 'INPUT';
    if (isBusy) {
      if (!btn.dataset.originalText) {
        btn.dataset.originalText = isInput ? btn.value : btn.textContent;
      }
      var loadingText = settings.savingText || btn.dataset.originalText;
      if (isInput) {
        btn.value = loadingText;
      } else {
        btn.textContent = loadingText;
      }
      btn.classList.add('is-loading');
      btn.disabled = true;
    } else {
      var original = btn.dataset.originalText;
      if (original) {
        if (isInput) {
          btn.value = original;
        } else {
          btn.textContent = original;
        }
      }
      btn.classList.remove('is-loading');
      btn.disabled = false;
    }
  }

  function updateSaveState(el, text, isError) {
    if (!el) {
      return;
    }
    el.textContent = text;
    el.classList.toggle('is-error', !!isError);
    el.classList.toggle('is-success', !isError);
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

  // Toggle modules without refresh
  var toggleForms = document.querySelectorAll('.brz-toggle-form');
  toggleForms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var card = form.closest('.brz-module-card');
      var moduleSlug = form.dataset.module || (card && card.dataset.module) || '';
      var hiddenState = form.querySelector('input[name="state"]');
      var nonceField = form.querySelector('[name="' + (settings.nonceField || '_wpnonce') + '"]');
      var sendState = hiddenState ? hiddenState.value : '';

      if (!moduleSlug || sendState === '') {
        showToast(settings.failText || 'خطا در داده‌ها', true);
        return;
      }

      var payload = new FormData();
      payload.append('action', 'brz_toggle_module');
      payload.append('module', moduleSlug);
      payload.append('state', sendState);
      if (nonceField) {
        payload.append(settings.nonceField || '_wpnonce', nonceField.value);
      }

      fetch(settings.ajaxUrl, {
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
        } else {
          window.location.reload();
        }
        showToast(newState ? (settings.successOn || 'ماژول فعال شد') : (settings.successOff || 'ماژول غیرفعال شد'));
      }).catch(function () {
        showToast(settings.failText || 'تغییر وضعیت انجام نشد. دوباره تلاش کنید.', true);
      });
    });
  });

})();
