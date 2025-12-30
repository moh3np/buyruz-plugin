/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var rowsTable = document.getElementById('brz-compare-rows');
  var addRowBtn = document.getElementById('brz-compare-add-row');
  var preview = document.getElementById('brz-compare-preview');
  var addColBtn = document.getElementById('brz-compare-add-col');
  var columnsWrap = document.querySelector('.brz-compare-columns');
  var saveBtn = document.querySelector('.brz-compare-save');
  var statusEl = document.querySelector('.brz-compare-status');
  var enabledToggle = document.querySelector('input[name="brz_compare_enabled"]');
  var titleInput = document.getElementById('brz-compare-title');

  var maxColumns = parseInt(box.dataset.maxCols || '6', 10);
  var fixedColumns = columnsWrap ? parseInt(columnsWrap.dataset.fixed || '3', 10) : 3;
  var defaultColumns;
  try {
    defaultColumns = JSON.parse(box.dataset.defaultColumns || '[]');
  } catch (err) {
    defaultColumns = [];
  }

  var debounceTimer = null;
  var saving = false;
  var lastPayload = '';

  function setStatus(text, state) {
    if (!statusEl) { return; }
    statusEl.textContent = text || '';
    statusEl.dataset.state = state || '';
  }

  function toggleDisabled(isOff) {
    box.classList.toggle('is-off', isOff);
  }

  function renumberRows() {
    if (!rowsTable) { return; }
    rowsTable.querySelectorAll('tbody tr').forEach(function(row, rowIndex) {
      row.querySelectorAll('input[type="text"]').forEach(function(input, cellIndex) {
        input.name = 'brz_compare_rows[' + rowIndex + '][' + cellIndex + ']';
      });
    });
  }

  function getColumns(options) {
    if (!columnsWrap) { return []; }
    var cols = Array.prototype.slice.call(columnsWrap.querySelectorAll('input')).map(function(input, index) {
      var value = input.value.trim();
      if (index < fixedColumns && !value && defaultColumns[index]) {
        value = defaultColumns[index];
      }
      return value;
    });
    if (options && options.trimEmpty) {
      cols = cols.filter(function(col, index) {
        return col || index < fixedColumns;
      });
    }
    return cols.slice(0, maxColumns);
  }

  function ensureAtLeastOneRow() {
    if (!rowsTable) { return; }
    var tbody = rowsTable.querySelector('tbody');
    if (tbody && !tbody.querySelector('tr')) {
      addRow();
    }
  }

  function syncColumnMeta() {
    if (!columnsWrap) { return; }
    columnsWrap.querySelectorAll('.brz-compare-col').forEach(function(colEl, index) {
      var idxEl = colEl.querySelector('.brz-compare-col__index');
      if (idxEl) {
        idxEl.textContent = index + 1;
      }
      var badge = colEl.querySelector('.brz-compare-col__badge');
      if (index < fixedColumns) {
        if (!badge) {
          var label = document.createElement('span');
          label.className = 'brz-compare-col__badge';
          label.textContent = 'ستون پایه';
          var meta = colEl.querySelector('.brz-compare-col__meta');
          if (meta) { meta.appendChild(label); }
        }
      } else if (badge) {
        badge.remove();
      }
    });
  }

  function addRow(values) {
    if (!rowsTable) { return; }
    var tbody = rowsTable.querySelector('tbody');
    var row = document.createElement('tr');
    var cols = getColumns();

    cols.forEach(function(_, idx) {
      var td = document.createElement('td');
      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'widefat';
      input.name = 'brz_compare_rows[0][' + idx + ']';
      input.value = values && values[idx] ? values[idx] : '';
      td.appendChild(input);
      row.appendChild(td);
    });

    var removeTd = document.createElement('td');
    removeTd.className = 'brz-compare-remove-cell';
    removeTd.innerHTML = '<button type="button" class="button link-delete brz-compare-remove-row" aria-label="حذف ردیف">&times;</button>';
    row.appendChild(removeTd);

    tbody.appendChild(row);
    renumberRows();
    refreshPreview();
    queueSave();
  }

  function rebuildTableForColumns() {
    if (!rowsTable || !columnsWrap) { return; }

    var cols = getColumns();
    var theadRow = rowsTable.querySelector('thead tr');
    if (theadRow) {
      theadRow.innerHTML = '';
      cols.forEach(function(col) {
        var th = document.createElement('th');
        th.textContent = col || 'ستون';
        theadRow.appendChild(th);
      });
      var removeTh = document.createElement('th');
      removeTh.className = 'brz-compare-remove-col-cell';
      removeTh.textContent = 'حذف';
      theadRow.appendChild(removeTh);
    }

    rowsTable.querySelectorAll('tbody tr').forEach(function(row) {
      var currentInputs = row.querySelectorAll('input[type="text"]');
      var desired = cols.length;
      var removeCell = row.querySelector('.brz-compare-remove-cell');
      while (currentInputs.length < desired) {
        var td = document.createElement('td');
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'widefat';
        input.name = 'brz_compare_rows[0][' + currentInputs.length + ']';
        td.appendChild(input);
        row.insertBefore(td, removeCell);
        currentInputs = row.querySelectorAll('input[type="text"]');
      }
      while (currentInputs.length > desired) {
        var lastIndex = currentInputs.length - 1;
        var cell = currentInputs[lastIndex].parentElement;
        if (cell && cell !== removeCell) {
          cell.remove();
        }
        currentInputs = row.querySelectorAll('input[type="text"]');
      }
    });

    renumberRows();
    syncColumnMeta();
    refreshPreview();
  }

  function serializePayload() {
    var rawCols = getColumns();
    var colCount = rawCols.length;
    var keepMap = new Array(colCount).fill(false);
    var collectedRows = [];

    rawCols.forEach(function(col, index) {
      if (index < fixedColumns || col) {
        keepMap[index] = true;
      }
    });

    if (rowsTable) {
      rowsTable.querySelectorAll('tbody tr').forEach(function(row) {
        var inputs = row.querySelectorAll('input[type="text"]');
        var clean = [];
        var hasValue = false;

        for (var i = 0; i < colCount; i++) {
          var cellValue = inputs[i] ? inputs[i].value.trim() : '';
          clean.push(cellValue);
          if (cellValue !== '') {
            hasValue = true;
            keepMap[i] = true;
          }
        }

        if (hasValue) {
          collectedRows.push(clean);
        }
      });
    }

    var finalCols = [];
    var finalRows = [];
    var indexMap = [];

    keepMap.forEach(function(keep, idx) {
      if (keep) {
        indexMap.push(idx);
        var label = rawCols[idx] || (idx < defaultColumns.length ? defaultColumns[idx] : '');
        if (!label) {
          label = 'ستون ' + (idx + 1);
        }
        finalCols.push(label);
      }
    });

    collectedRows.forEach(function(row) {
      var mapped = [];
      indexMap.forEach(function(oldIndex) {
        mapped.push(row[oldIndex] || '');
      });
      if (mapped.some(function(cell) { return cell !== ''; })) {
        finalRows.push(mapped);
      }
    });

    return {
      enabled: enabledToggle ? (enabledToggle.checked ? 1 : 0) : 0,
      title: titleInput ? titleInput.value.trim() : '',
      columns: finalCols,
      rows: finalRows
    };
  }

  function refreshPreview() {
    if (!preview) { return; }
    var payload = serializePayload();
    var cols = payload.columns;

    preview.innerHTML = '';

    if (!payload.enabled) {
      preview.innerHTML = '<p class="description">جدول غیرفعال است؛ با فعال‌سازی، خروجی در توضیحات محصول رندر می‌شود.</p>';
      return;
    }

    if (!payload.rows.length) {
      preview.innerHTML = '<p class="description">برای مشاهده پیش‌نمایش، حداقل یک ردیف را پر کنید.</p>';
      return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'buyruz-table-wrap';

    if (payload.title) {
      var heading = document.createElement('h4');
      heading.className = 'buyruz-table-title';
      heading.textContent = payload.title;
      wrap.appendChild(heading);
    }

    var table = document.createElement('table');
    table.className = 'buyruz-table buyruz-table--preview';

    var thead = document.createElement('thead');
    var htr = document.createElement('tr');
    cols.forEach(function(col) {
      var th = document.createElement('th');
      th.textContent = col || 'ستون';
      htr.appendChild(th);
    });
    thead.appendChild(htr);
    table.appendChild(thead);

    var tbody = document.createElement('tbody');
    payload.rows.forEach(function(rowCells) {
      var tr = document.createElement('tr');
      for (var i = 0; i < cols.length; i++) {
        var td = document.createElement('td');
        td.textContent = rowCells[i] || '';
        td.setAttribute('data-label', cols[i] || 'ستون');
        tr.appendChild(td);
      }
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);

    preview.appendChild(wrap);
  }

  function queueSave(immediate) {
    if (!box.dataset.nonce || !box.dataset.productId) { return; }
    clearTimeout(debounceTimer);
    if (immediate) {
      persist();
      return;
    }
    setStatus('در حال ذخیره...', 'saving');
    debounceTimer = setTimeout(persist, 700);
  }

  function persist() {
    clearTimeout(debounceTimer);
    var payload = serializePayload();
    var payloadJson = JSON.stringify(payload);

    if (payloadJson === lastPayload) {
      return;
    }

    saving = true;
    setStatus('در حال ذخیره...', 'saving');

    var body = new URLSearchParams();
    body.append('action', 'brz_save_compare_table');
    body.append('nonce', box.dataset.nonce);
    body.append('post_id', box.dataset.productId);
    body.append('payload', payloadJson);

    fetch((window.ajaxurl || '/wp-admin/admin-ajax.php'), {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function(res) {
      try {
        return res.json();
      } catch (err) {
        return {};
      }
    }).then(function(res) {
      if (res && res.success) {
        lastPayload = payloadJson;
        var state = (payload.enabled && payload.rows.length) ? 'saved' : 'muted';
        var message = res.data && res.data.message ? res.data.message : (state === 'saved' ? 'ذخیره شد.' : 'جدول غیرفعال است.');
        setStatus(message, state);
      } else {
        setStatus('ذخیره نشد. دوباره تلاش کنید.', 'error');
      }
    }).catch(function() {
      setStatus('خطا در ارتباط با سرور.', 'error');
    }).finally(function() {
      saving = false;
    });
  }

  if (addRowBtn) {
    addRowBtn.addEventListener('click', function() {
      addRow();
    });
  }

  if (rowsTable) {
    rowsTable.addEventListener('click', function(e) {
      if (e.target && e.target.classList.contains('brz-compare-remove-row')) {
        var row = e.target.closest('tr');
        if (row) {
          row.remove();
          renumberRows();
          refreshPreview();
          queueSave();
        }
      }
    });

    rowsTable.addEventListener('input', function() {
      refreshPreview();
      queueSave();
    });
  }

  if (columnsWrap) {
    columnsWrap.addEventListener('input', function(e) {
      if (e.target && e.target.tagName === 'INPUT') {
        rebuildTableForColumns();
        queueSave();
      }
    });
    columnsWrap.addEventListener('click', function(e) {
      if (e.target && e.target.classList.contains('brz-compare-remove-col')) {
        var col = e.target.closest('.brz-compare-col');
        if (!col) { return; }
        var allCols = columnsWrap.querySelectorAll('.brz-compare-col');
        if (allCols.length <= fixedColumns) { return; }
        var index = Array.prototype.indexOf.call(allCols, col);
        if (index < fixedColumns) { return; }

        if (rowsTable) {
          rowsTable.querySelectorAll('tbody tr').forEach(function(row) {
            var inputs = row.querySelectorAll('input[type="text"]');
            if (inputs[index] && inputs[index].parentElement && inputs[index].parentElement.parentElement === row) {
              inputs[index].parentElement.remove();
            }
          });
        }

        col.remove();
        renumberRows();
        syncColumnMeta();
        rebuildTableForColumns();
        queueSave();
      }
    });
  }

  if (addColBtn && columnsWrap) {
    addColBtn.addEventListener('click', function() {
      var cols = columnsWrap.querySelectorAll('.brz-compare-col');
      if (cols.length >= maxColumns) { return; }
      var col = document.createElement('div');
      col.className = 'brz-compare-col';
      col.innerHTML = '<div class="brz-compare-col__meta"><span class="brz-compare-col__index">' + (cols.length + 1) + '</span></div><input type="text" name="brz_compare_columns[]" class="regular-text" placeholder="ستون ' + (cols.length + 1) + '" /> <button type="button" class="button link-delete brz-compare-remove-col" aria-label="حذف ستون">&times;</button>';
      columnsWrap.appendChild(col);
      syncColumnMeta();
      rebuildTableForColumns();
      queueSave();
    });
  }

  if (titleInput) {
    titleInput.addEventListener('input', function() {
      refreshPreview();
      queueSave();
    });
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', function() {
      queueSave(true);
    });
  }

  if (enabledToggle) {
    toggleDisabled(!enabledToggle.checked);
  }

  rebuildTableForColumns();
  ensureAtLeastOneRow();
  refreshPreview();
})();
