/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var grid = box.querySelector('#brz-compare-grid');
  var headerRow = grid ? grid.querySelector('.brz-compare-row--header') : null;
  var body = grid;
  var addRowBtn = box.querySelector('[data-add-row]');
  var removeRowBtn = box.querySelector('[data-remove-row]');

  var maxColumns = parseInt(box.dataset.maxCols || '6', 10);
  var defaultColumns = 2;

  function columnCount() {
    if (!headerRow) { return 0; }
    return headerRow.querySelectorAll('input').length;
  }

  function ensureAtLeastOneRow() {
    if (!body) { return; }
    var dataRows = body.querySelectorAll('.brz-compare-row:not(.brz-compare-row--header)');
    if (dataRows.length === 0) {
      addRow();
    }
  }

  function renumberRowInputs() {
    if (!body) { return; }
    var totalCols = columnCount();
    body.style.setProperty('--cols', totalCols);
    var dataRows = body.querySelectorAll('.brz-compare-row:not(.brz-compare-row--header)');
    Array.prototype.slice.call(dataRows).forEach(function(row, rIndex) {
      var inputs = row.querySelectorAll('.brz-compare-cell input');
      for (var c = 0; c < inputs.length; c++) {
        inputs[c].name = 'brz_compare_rows[' + rIndex + '][' + c + ']';
      }
      while (inputs.length < totalCols) {
        var cell = document.createElement('div');
        cell.className = 'brz-compare-cell';
        var input = document.createElement('input');
        input.type = 'text';
        input.name = 'brz_compare_rows[' + rIndex + '][' + inputs.length + ']';
        cell.appendChild(input);
        row.appendChild(cell);
        inputs = row.querySelectorAll('.brz-compare-cell input');
      }
      while (inputs.length > totalCols && totalCols > 0) {
        var last = inputs[inputs.length - 1];
        if (last && last.parentElement) {
          last.parentElement.remove();
        }
        inputs = row.querySelectorAll('.brz-compare-cell input');
      }
    });
  }

  function addRow(values) {
    if (!body || !headerRow) { return; }
    var row = document.createElement('div');
    row.className = 'brz-compare-row';
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'brz-compare-btn brz-compare-btn--danger brz-compare-remove-row';
    removeBtn.setAttribute('aria-label', 'حذف ردیف');
    removeBtn.textContent = '−';
    row.appendChild(removeBtn);

    var td = document.createElement('div');
    td.className = 'brz-compare-cell';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'brz_compare_rows[0][0]';
    input.value = values && values[0] ? values[0] : '';
    td.appendChild(input);
    row.appendChild(td);

    body.appendChild(row);
    renumberRowInputs();
  }

  function removeRow(target) {
    var row = target ? target.closest('.brz-compare-row') : null;
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
  if (grid) {
    grid.addEventListener('click', function(e) {
      if (e.target && e.target.classList.contains('brz-compare-remove-row')) {
        e.preventDefault();
        removeRow(e.target);
      }
    });
  }

  // هم‌سان‌سازی اولیه
  // اطمینان از وجود هدر
  if (headerRow && columnCount() === 0) {
    var cell = document.createElement('div');
    cell.className = 'brz-compare-cell';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'brz_compare_columns[]';
    input.placeholder = 'هدر';
    cell.appendChild(input);
    headerRow.appendChild(cell);
  }
  ensureAtLeastOneRow();
  renumberRowInputs();

  // نگه داشتن هدر روی یک سطر بدون فضای خاکستری
  if (grid) {
    grid.classList.add('brz-compare-grid--hydrated');
  }
})();
