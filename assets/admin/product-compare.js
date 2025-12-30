/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var rowsTable = document.getElementById('brz-compare-rows');
  var addRowBtn = document.getElementById('brz-compare-add-row');
  var preview = document.getElementById('brz-compare-preview');
  var addColBtn = document.getElementById('brz-compare-add-col');
  var columnsWrap = document.querySelector('.brz-compare-columns');

  function renumberRows() {
    var rows = rowsTable.querySelectorAll('tbody tr');
    rows.forEach(function(row, rowIndex) {
      var inputs = row.querySelectorAll('input[type="text"]');
      inputs.forEach(function(input, cellIndex) {
        input.name = 'brz_compare_rows[' + rowIndex + '][' + cellIndex + ']';
      });
    });
  }

  function addRow(values) {
    var tbody = rowsTable.querySelector('tbody');
    var row = document.createElement('tr');
    var cols = getColumns().length;
    for (var i = 0; i < cols; i++) {
      var td = document.createElement('td');
      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'widefat';
      input.name = 'brz_compare_rows[0][' + i + ']';
      input.value = values && values[i] ? values[i] : '';
      td.appendChild(input);
      row.appendChild(td);
    }
    var removeTd = document.createElement('td');
    removeTd.innerHTML = '<button type="button" class="button link-delete brz-compare-remove-row">&times;</button>';
    row.appendChild(removeTd);
    tbody.appendChild(row);
    renumberRows();
    refreshPreview();
  }

  function getColumns() {
    if (!columnsWrap) { return []; }
    return Array.prototype.slice.call(columnsWrap.querySelectorAll('input')).map(function(input) {
      return input.value.trim();
    });
  }

  function rebuildTableForColumns() {
    var cols = getColumns();
    var theadRow = rowsTable.querySelector('thead tr');
    theadRow.innerHTML = '';
    cols.forEach(function(col) {
      var th = document.createElement('th');
      th.textContent = col || 'ستون';
      theadRow.appendChild(th);
    });
    var removeTh = document.createElement('th');
    removeTh.style.width = '90px';
    removeTh.textContent = 'حذف';
    theadRow.appendChild(removeTh);

    rowsTable.querySelectorAll('tbody tr').forEach(function(row) {
      var currentInputs = row.querySelectorAll('input[type="text"]');
      var desired = cols.length;
      var removeCell = row.querySelector('td:last-child');
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
  }

  function refreshPreview() {
    if (!preview) { return; }
    var cols = getColumns();
    var rows = [];
    rowsTable.querySelectorAll('tbody tr').forEach(function(row) {
      var cells = [];
      row.querySelectorAll('input[type="text"]').forEach(function(input) {
        cells.push(input.value.trim());
      });
      if (cells.some(function(c) { return c !== ''; })) {
        rows.push(cells);
      }
    });

    if (!rows.length) {
      preview.innerHTML = '<p class="description">برای مشاهده پیش‌نمایش، حداقل یک ردیف را پر کنید.</p>';
      return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'buyruz-table-wrap';
    var table = document.createElement('table');
    table.className = 'buyruz-table';

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
    rows.forEach(function(rowCells) {
      var tr = document.createElement('tr');
      for (var i = 0; i < cols.length; i++) {
        var td = document.createElement('td');
        td.textContent = rowCells[i] || '';
        tr.appendChild(td);
      }
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);

    preview.innerHTML = '';
    preview.appendChild(wrap);
  }

  if (addRowBtn) {
    addRowBtn.addEventListener('click', function() {
      addRow(['', '', '']);
    });
  }

  rowsTable.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('brz-compare-remove-row')) {
      var row = e.target.closest('tr');
      if (row && rowsTable.querySelectorAll('tbody tr').length > 1) {
        row.remove();
        renumberRows();
        refreshPreview();
      }
    }
  });

  rowsTable.addEventListener('input', refreshPreview);
  if (columnsWrap) {
    columnsWrap.addEventListener('input', function(e) {
      if (e.target && e.target.tagName === 'INPUT') {
        rebuildTableForColumns();
        refreshPreview();
      }
    });
    columnsWrap.addEventListener('click', function(e) {
      if (e.target && e.target.classList.contains('brz-compare-remove-col')) {
        var col = e.target.closest('.brz-compare-col');
        if (!col) { return; }
        var cols = columnsWrap.querySelectorAll('.brz-compare-col');
        if (cols.length <= 3) { return; }
        col.remove();
        rebuildTableForColumns();
        refreshPreview();
      }
    });
  }

  if (addColBtn) {
    addColBtn.addEventListener('click', function() {
      var max = parseInt(columnsWrap.dataset.max || '6', 10);
      var cols = columnsWrap.querySelectorAll('.brz-compare-col');
      if (cols.length >= max) { return; }
      var col = document.createElement('div');
      col.className = 'brz-compare-col';
      col.innerHTML = '<input type="text" name="brz_compare_columns[]" class="regular-text" placeholder="ستون ' + (cols.length + 1) + '" /> <button type="button" class="button link-delete brz-compare-remove-col" aria-label="حذف ستون">&times;</button>';
      columnsWrap.appendChild(col);
      rebuildTableForColumns();
      refreshPreview();
    });
  }

  rebuildTableForColumns();
  refreshPreview();
})();
