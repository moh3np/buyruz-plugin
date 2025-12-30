/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var grid = box.querySelector('#brz-compare-grid');
  var headerRow = grid ? grid.querySelector('thead tr') : null;
  var body = grid ? grid.querySelector('tbody') : null;
  var addRowBtn = box.querySelector('[data-add-row]');
  var removeRowBtn = box.querySelector('[data-remove-row]');
  var addColBtn = box.querySelector('[data-add-col]');
  var removeColBtn = box.querySelector('[data-remove-col]');

  var maxColumns = parseInt(box.dataset.maxCols || '6', 10);
  var defaultColumns = 2;

  function columnCount() {
    if (!headerRow) { return 0; }
    return headerRow.querySelectorAll('input').length;
  }

  function ensureAtLeastOneRow() {
    if (!body) { return; }
    if (!body.querySelector('tr')) {
      addRow();
    }
  }

  function renumberRowInputs() {
    if (!body) { return; }
    var totalCols = columnCount();
    Array.prototype.slice.call(body.querySelectorAll('tr')).forEach(function(row, rIndex) {
      var inputs = row.querySelectorAll('td input');
      for (var c = 0; c < inputs.length; c++) {
        inputs[c].name = 'brz_compare_rows[' + rIndex + '][' + c + ']';
      }
      // همسان‌سازی تعداد ستون‌ها برای هر ردیف
      while (inputs.length < totalCols) {
        var td = document.createElement('td');
        var input = document.createElement('input');
        input.type = 'text';
        input.name = 'brz_compare_rows[' + rIndex + '][' + inputs.length + ']';
        td.appendChild(input);
        row.appendChild(td);
        inputs = row.querySelectorAll('td input');
      }
      while (inputs.length > totalCols && totalCols > 0) {
        var last = inputs[inputs.length - 1];
        if (last && last.parentElement) {
          last.parentElement.remove();
        }
        inputs = row.querySelectorAll('td input');
      }
    });
  }

  function addColumn(value) {
    if (!headerRow || !body) { return; }
    var count = columnCount();
    if (count >= maxColumns) { return; }
    var th = document.createElement('th');
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'brz_compare_columns[]';
    input.placeholder = 'ستون ' + (count + 1);
    if (value) { input.value = value; }
    th.appendChild(input);
    headerRow.appendChild(th);

    Array.prototype.slice.call(body.querySelectorAll('tr')).forEach(function(row) {
      var td = document.createElement('td');
      var cellInput = document.createElement('input');
      cellInput.type = 'text';
      cellInput.name = 'brz_compare_rows[0][' + count + ']';
      td.appendChild(cellInput);
      row.appendChild(td);
    });
    renumberRowInputs();
  }

  function removeColumn() {
    if (!headerRow || !body) { return; }
    var count = columnCount();
    if (count <= 1) { return; }
    var thList = headerRow.querySelectorAll('th');
    var lastTh = thList[thList.length - 1];
    if (lastTh) { lastTh.remove(); }
    Array.prototype.slice.call(body.querySelectorAll('tr')).forEach(function(row) {
      var cells = row.querySelectorAll('td');
      if (cells.length > 0) {
        cells[cells.length - 1].remove();
      }
    });
    renumberRowInputs();
  }

  function addRow(values) {
    if (!body || !headerRow) { return; }
    var row = document.createElement('tr');
    var actionCell = document.createElement('td');
    actionCell.className = 'brz-compare-grid__actions-cell';
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'brz-compare-btn brz-compare-btn--danger brz-compare-remove-row';
    removeBtn.setAttribute('aria-label', 'حذف ردیف');
    removeBtn.textContent = '−';
    actionCell.appendChild(removeBtn);
    row.appendChild(actionCell);

    var count = columnCount() || defaultColumns;
    if (columnCount() === 0) {
      for (var x = 0; x < defaultColumns; x++) {
        addColumn('');
      }
      count = columnCount();
    }

    for (var i = 0; i < count; i++) {
      var td = document.createElement('td');
      var input = document.createElement('input');
      input.type = 'text';
      input.name = 'brz_compare_rows[0][' + i + ']';
      input.value = values && values[i] ? values[i] : '';
      td.appendChild(input);
      row.appendChild(td);
    }

    body.appendChild(row);
    renumberRowInputs();
  }

  function removeRow(target) {
    var row = target ? target.closest('tr') : null;
    if (!row) { return; }
    row.remove();
    renumberRowInputs();
    ensureAtLeastOneRow();
  }

  // رویدادها
  if (addRowBtn) {
    addRowBtn.addEventListener('click', function() {
      addRow();
    });
  }
  if (removeRowBtn) {
    removeRowBtn.addEventListener('click', function() {
      var last = body ? body.querySelector('tr:last-child') : null;
      if (last) { removeRow(last.querySelector('.brz-compare-remove-row')); }
    });
  }
  if (addColBtn) {
    addColBtn.addEventListener('click', function() { addColumn(''); });
  }
  if (removeColBtn) {
    removeColBtn.addEventListener('click', function() { removeColumn(); });
  }
  if (grid) {
    grid.addEventListener('click', function(e) {
      if (e.target && e.target.classList.contains('brz-compare-remove-row')) {
        e.preventDefault();
        removeRow(e.target);
      }
    });
  }

  // هم‌سان‌سازی اولیه
  if (headerRow && columnCount() === 0) {
    addColumn('');
    addColumn('');
  }
  ensureAtLeastOneRow();
  renumberRowInputs();
})();
