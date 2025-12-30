/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var columnsWrap = box.querySelector('.brz-compare-columns');
  var rowsTable = box.querySelector('#brz-compare-rows');
  var addColBtn = box.querySelector('#brz-compare-add-col');
  var addRowBtn = box.querySelector('#brz-compare-add-row');
  var enabledToggle = box.querySelector('input[name="brz_compare_enabled"]');
  var titleInput = box.querySelector('#brz-compare-title');

  var maxColumns = parseInt(box.dataset.maxCols || '6', 10);
  var fixedColumns = columnsWrap ? parseInt(columnsWrap.dataset.fixed || '3', 10) : 3;
  var defaultColumns = [];
  try { defaultColumns = JSON.parse(box.dataset.defaultColumns || '[]'); } catch (err) { defaultColumns = []; }

  function setDisabledState() {
    if (!enabledToggle) { return; }
    box.classList.toggle('is-off', !enabledToggle.checked);
  }

  function renumberColumns() {
    if (!columnsWrap) { return; }
    var cols = columnsWrap.querySelectorAll('.brz-compare-col');
    cols.forEach(function(colEl, index) {
      var idxEl = colEl.querySelector('.brz-compare-col__index');
      if (idxEl) { idxEl.textContent = index + 1; }
      var badge = colEl.querySelector('.brz-compare-col__badge');
      var removeBtn = colEl.querySelector('.brz-compare-remove-col');
      if (index < fixedColumns) {
        if (!badge) {
          var b = document.createElement('span');
          b.className = 'brz-compare-col__badge';
          b.textContent = 'ستون پایه';
          var meta = colEl.querySelector('.brz-compare-col__meta');
          if (meta) { meta.appendChild(b); }
        }
        if (removeBtn) { removeBtn.style.display = 'none'; }
      } else {
        if (badge) { badge.remove(); }
        if (removeBtn) { removeBtn.style.display = ''; }
      }
    });
  }

  function getColumns(options) {
    if (!columnsWrap) { return []; }
    var cols = Array.prototype.slice.call(columnsWrap.querySelectorAll('input')).map(function(input, index) {
      var val = input.value.trim();
      if (!val && index < defaultColumns.length) {
        val = defaultColumns[index];
      }
      return val;
    });
    if (options && options.trimEmpty) {
      cols = cols.filter(function(col, index) {
        return col || index < fixedColumns;
      });
    }
    return cols.slice(0, maxColumns);
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
      var inputs = row.querySelectorAll('input[type="text"]');
      var removeCell = row.querySelector('.brz-compare-remove-cell');
      while (inputs.length < cols.length) {
        var td = document.createElement('td');
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'widefat';
        input.name = 'brz_compare_rows[0][' + inputs.length + ']';
        td.appendChild(input);
        row.insertBefore(td, removeCell);
        inputs = row.querySelectorAll('input[type="text"]');
      }
      while (inputs.length > cols.length) {
        var last = inputs[inputs.length - 1];
        if (last && last.parentElement && last.parentElement !== removeCell) {
          last.parentElement.remove();
        }
        inputs = row.querySelectorAll('input[type="text"]');
      }
    });

    renumberRows();
  }

  function renumberRows() {
    if (!rowsTable) { return; }
    rowsTable.querySelectorAll('tbody tr').forEach(function(row, rIndex) {
      row.querySelectorAll('input[type="text"]').forEach(function(input, cIndex) {
        input.name = 'brz_compare_rows[' + rIndex + '][' + cIndex + ']';
      });
    });
  }

  function addRow(values) {
    if (!rowsTable || !columnsWrap) { return; }
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
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'button link-delete brz-compare-remove-row';
    btn.setAttribute('aria-label', 'حذف ردیف');
    btn.innerHTML = '&times;';
    removeTd.appendChild(btn);
    row.appendChild(removeTd);

    tbody.appendChild(row);
    renumberRows();
  }

  function collectState() {
    var cols = getColumns({ trimEmpty: true });
    var rows = [];
    if (rowsTable) {
      rowsTable.querySelectorAll('tbody tr').forEach(function(row) {
        var inputs = row.querySelectorAll('input[type="text"]');
        var clean = [];
        var hasValue = false;
        for (var i = 0; i < cols.length; i++) {
          var val = inputs[i] ? inputs[i].value.trim() : '';
          clean.push(val);
          if (val !== '') { hasValue = true; }
        }
        if (hasValue) { rows.push(clean); }
      });
    }
    return {
      enabled: enabledToggle ? enabledToggle.checked : true,
      title: titleInput ? titleInput.value.trim() : '',
      columns: cols,
      rows: rows
    };
  }

  if (addColBtn && columnsWrap) {
    addColBtn.addEventListener('click', function() {
      var cols = columnsWrap.querySelectorAll('.brz-compare-col');
      if (cols.length >= maxColumns) { return; }
      var col = document.createElement('div');
      col.className = 'brz-compare-col';
      col.innerHTML = '<div class="brz-compare-col__meta"><span class="brz-compare-col__index">' + (cols.length + 1) + '</span></div><input type="text" name="brz_compare_columns[]" class="regular-text" placeholder="ستون ' + (cols.length + 1) + '" /> <button type="button" class="button link-delete brz-compare-remove-col" aria-label="حذف ستون">&times;</button>';
      columnsWrap.appendChild(col);
      renumberColumns();
      rebuildTableForColumns();
    });
  }

  if (columnsWrap) {
    columnsWrap.addEventListener('click', function(e) {
      if (e.target && e.target.classList.contains('brz-compare-remove-col')) {
        var col = e.target.closest('.brz-compare-col');
        if (!col) { return; }
        var allCols = columnsWrap.querySelectorAll('.brz-compare-col');
        var idx = Array.prototype.indexOf.call(allCols, col);
        if (idx < fixedColumns) { return; }
        col.remove();
        renumberColumns();
        rebuildTableForColumns();
      }
    });
    columnsWrap.addEventListener('input', function(e) {
      if (e.target && e.target.tagName === 'INPUT') {
        rebuildTableForColumns();
      }
    });
  }

  if (addRowBtn) {
    addRowBtn.addEventListener('click', function() { addRow(); });
  }

  if (rowsTable) {
    rowsTable.addEventListener('click', function(e) {
      if (e.target && e.target.classList.contains('brz-compare-remove-row')) {
        var row = e.target.closest('tr');
        if (row) { row.remove(); renumberRows(); refreshPreview(); }
        if (row) { row.remove(); renumberRows(); }
      }
    });
    rowsTable.addEventListener('input', function() {});
  }

  if (titleInput) {
    titleInput.addEventListener('input', function() {});
  }
  if (enabledToggle) {
    enabledToggle.addEventListener('change', function() { setDisabledState(); });
    setDisabledState();
  }

  rebuildTableForColumns();
  renumberColumns();
  if (rowsTable && !rowsTable.querySelector('tbody tr')) { addRow(); }
})();
