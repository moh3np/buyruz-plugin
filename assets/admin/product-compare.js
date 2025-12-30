/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var rowsTable = document.getElementById('brz-compare-rows');
  var addRowBtn = document.getElementById('brz-compare-add-row');
  var preview = document.getElementById('brz-compare-preview');

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
    for (var i = 0; i < 3; i++) {
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

  function refreshPreview() {
    if (!preview) { return; }
    var cols = Array.prototype.slice.call(document.querySelectorAll('.brz-compare-columns input')).map(function(input) {
      return input.value.trim();
    });
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
  var columnInputs = document.querySelectorAll('.brz-compare-columns input');
  columnInputs.forEach(function(input) {
    input.addEventListener('input', refreshPreview);
  });

  refreshPreview();
})();
