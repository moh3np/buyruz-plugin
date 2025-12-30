/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var grid = box.querySelector('#brz-compare-grid');
  var headerRow = grid ? grid.querySelector('.brz-compare-row--header') : null;
  var rowsWrap = grid ? grid.querySelector('#brz-compare-rows') : null;
  var addRowBtn = box.querySelector('[data-add-row]');
  var removeRowBtn = box.querySelector('[data-remove-row]');

  function renumberRows() {
    if (!rowsWrap) { return; }
    rowsWrap.querySelectorAll('.brz-compare-row').forEach(function(row, idx) {
      var input = row.querySelector('input');
      if (input) {
        input.name = 'brz_compare_rows[' + idx + '][0]';
      }
    });
  }

  function ensureHeader() {
    if (!headerRow) { return; }
    if (!headerRow.querySelector('input')) {
      var cell = document.createElement('div');
      cell.className = 'brz-compare-cell';
      var input = document.createElement('input');
      input.type = 'text';
      input.name = 'brz_compare_columns[]';
      input.placeholder = 'هدر';
      cell.appendChild(input);
      headerRow.appendChild(cell);
    }
  }

  function ensureRow() {
    if (!rowsWrap) { return; }
    if (!rowsWrap.querySelector('.brz-compare-row')) {
      addRow('');
    }
  }

  function addRow(value) {
    if (!rowsWrap) { return; }
    var row = document.createElement('div');
    row.className = 'brz-compare-row';
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'brz-compare-btn brz-compare-btn--danger brz-compare-remove-row';
    removeBtn.setAttribute('aria-label', 'حذف ردیف');
    removeBtn.textContent = '−';
    row.appendChild(removeBtn);

    var cell = document.createElement('div');
    cell.className = 'brz-compare-cell';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'brz_compare_rows[0][0]';
    input.value = value || '';
    cell.appendChild(input);
    row.appendChild(cell);

    rowsWrap.appendChild(row);
    renumberRows();
  }

  function removeRow(target) {
    var row = target ? target.closest('.brz-compare-row') : null;
    if (!row) { return; }
    row.remove();
    renumberRows();
    ensureRow();
  }

  if (addRowBtn) {
    addRowBtn.addEventListener('click', function() { addRow(''); });
  }
  if (removeRowBtn) {
    removeRowBtn.addEventListener('click', function() {
      var last = rowsWrap ? rowsWrap.querySelector('.brz-compare-row:last-child') : null;
      if (last) { removeRow(last); }
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

  ensureHeader();
  ensureRow();
  renumberRows();
})();
