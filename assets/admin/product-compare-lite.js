/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var grid = box.querySelector('#brz-compare-grid');
  if (!grid) { return; }

  var headerRow = grid.querySelector('.brz-compare-row--header');
  var maxColumns = parseInt(box.dataset.maxCols || '6', 10);
  var defaultColumns = 2;

  function headerCells() {
    return headerRow ? headerRow.querySelectorAll('.brz-compare-cell--header') : [];
  }

  function dataRows() {
    return grid.querySelectorAll('.brz-compare-row:not(.brz-compare-row--header)');
  }

  function columnCount() {
    return headerCells().length;
  }

  function ensureHeaderRow() {
    if (headerRow) { return; }
    headerRow = document.createElement('div');
    headerRow.className = 'brz-compare-row brz-compare-row--header';
    headerRow.setAttribute('data-row', 'header');
    var actions = document.createElement('div');
    actions.className = 'brz-compare-row-actions';
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'brz-compare-btn brz-compare-btn--success';
    addBtn.textContent = '+';
    addBtn.setAttribute('data-add-row', 'header');
    addBtn.setAttribute('aria-label', 'افزودن ردیف بعد از هدر');
    actions.appendChild(addBtn);
    headerRow.appendChild(actions);
    grid.insertBefore(headerRow, grid.firstChild || null);
  }

  function setColsVar() {
    grid.style.setProperty('--cols', Math.max(columnCount(), 1));
  }

  function buildHeaderCell(value) {
    var cell = document.createElement('div');
    cell.className = 'brz-compare-cell brz-compare-cell--header';

    var actions = document.createElement('div');
    actions.className = 'brz-compare-col-actions';

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'brz-compare-btn brz-compare-btn--success';
    addBtn.textContent = '+';
    addBtn.setAttribute('data-add-col', '0');
    addBtn.setAttribute('aria-label', 'افزودن ستون');

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'brz-compare-btn brz-compare-btn--danger';
    removeBtn.textContent = '−';
    removeBtn.setAttribute('data-remove-col', '0');
    removeBtn.setAttribute('aria-label', 'حذف ستون');

    actions.appendChild(addBtn);
    actions.appendChild(removeBtn);
    cell.appendChild(actions);

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'brz_compare_columns[]';
    input.placeholder = 'هدر ' + (columnCount() + 1);
    input.value = value || '';
    cell.appendChild(input);
    return cell;
  }

  function buildDataCell(value) {
    var cell = document.createElement('div');
    cell.className = 'brz-compare-cell';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'brz_compare_rows[0][0]';
    input.value = value || '';
    cell.appendChild(input);
    return cell;
  }

  function buildRow(values) {
    ensureHeaderRow();
    var row = document.createElement('div');
    row.className = 'brz-compare-row';

    var actions = document.createElement('div');
    actions.className = 'brz-compare-row-actions';
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'brz-compare-btn brz-compare-btn--success';
    addBtn.textContent = '+';
    addBtn.setAttribute('data-add-row', '0');
    addBtn.setAttribute('aria-label', 'افزودن ردیف بعد از این ردیف');
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'brz-compare-btn brz-compare-btn--danger';
    removeBtn.textContent = '−';
    removeBtn.setAttribute('data-remove-row', '0');
    removeBtn.setAttribute('aria-label', 'حذف این ردیف');
    actions.appendChild(addBtn);
    actions.appendChild(removeBtn);
    row.appendChild(actions);

    var cols = columnCount() || defaultColumns;
    if (columnCount() === 0) {
      for (var x = 0; x < defaultColumns; x++) {
        headerRow.appendChild(buildHeaderCell(''));
      }
      cols = columnCount();
    }

    for (var i = 0; i < cols; i++) {
      var cell = buildDataCell(values && values[i] ? values[i] : '');
      row.appendChild(cell);
    }

    return row;
  }

  function addRow(afterKey, values) {
    ensureHeaderRow();
    var row = buildRow(values || null);
    var rows = Array.prototype.slice.call(dataRows());

    if (afterKey === 'header') {
      if (rows.length > 0) {
        grid.insertBefore(row, rows[0]);
      } else {
        grid.appendChild(row);
      }
    } else {
      var index = parseInt(afterKey, 10);
      if (isNaN(index) || index < 0 || index >= rows.length) {
        grid.appendChild(row);
      } else {
        var anchor = rows[index];
        if (anchor && anchor.nextSibling) {
          grid.insertBefore(row, anchor.nextSibling);
        } else {
          grid.appendChild(row);
        }
      }
    }
    renumber();
  }

  function removeRow(index) {
    var rows = Array.prototype.slice.call(dataRows());
    if (rows.length <= 1) { return; }
    var targetIndex = parseInt(index, 10);
    if (isNaN(targetIndex) || targetIndex < 0 || targetIndex >= rows.length) {
      return;
    }
    rows[targetIndex].remove();
    renumber();
  }

  function addColumn(afterIndex) {
    ensureHeaderRow();
    var current = columnCount();
    if (current >= maxColumns) { return; }
    var idx = parseInt(afterIndex, 10);
    if (isNaN(idx) || idx < 0 || idx >= current) {
      idx = current - 1;
    }

    var headerCell = buildHeaderCell('');
    var headers = headerCells();
    var target = headers[idx];
    if (target && target.nextSibling) {
      headerRow.insertBefore(headerCell, target.nextSibling);
    } else {
      headerRow.appendChild(headerCell);
    }

    Array.prototype.slice.call(dataRows()).forEach(function(row) {
      var dataCell = buildDataCell('');
      var cells = row.querySelectorAll('.brz-compare-cell');
      var anchor = cells[idx];
      if (anchor && anchor.nextSibling) {
        row.insertBefore(dataCell, anchor.nextSibling);
      } else {
        row.appendChild(dataCell);
      }
    });
    renumber();
  }

  function removeColumn(index) {
    ensureHeaderRow();
    var current = columnCount();
    if (current <= 1) { return; }
    var idx = parseInt(index, 10);
    if (isNaN(idx) || idx < 0 || idx >= current) {
      idx = current - 1;
    }

    var headers = headerCells();
    if (headers[idx]) { headers[idx].remove(); }

    Array.prototype.slice.call(dataRows()).forEach(function(row) {
      var cells = row.querySelectorAll('.brz-compare-cell');
      if (cells[idx]) {
        cells[idx].remove();
      }
    });
    renumber();
  }

  function ensureAtLeastOneRow() {
    if (dataRows().length === 0) {
      addRow('header');
    }
  }

  function renumber() {
    ensureHeaderRow();
    var headerAdd = headerRow.querySelector('.brz-compare-row-actions [data-add-row]');
    if (headerAdd) {
      headerAdd.setAttribute('data-add-row', 'header');
    }
    var headers = headerCells();
    Array.prototype.slice.call(headers).forEach(function(cell, idx) {
      cell.setAttribute('data-col', idx);
      var addBtn = cell.querySelector('[data-add-col]');
      if (addBtn) {
        addBtn.setAttribute('data-add-col', idx);
        addBtn.setAttribute('aria-label', 'افزودن ستون بعد از ستون ' + (idx + 1));
        addBtn.disabled = headers.length >= maxColumns;
      }
      var removeBtn = cell.querySelector('[data-remove-col]');
      if (removeBtn) {
        removeBtn.setAttribute('data-remove-col', idx);
        removeBtn.disabled = headers.length <= 1;
        removeBtn.setAttribute('aria-label', 'حذف ستون ' + (idx + 1));
      }
      var input = cell.querySelector('input');
      if (input) {
        input.name = 'brz_compare_columns[]';
        input.placeholder = 'هدر ' + (idx + 1);
      }
    });

    setColsVar();

    var rows = Array.prototype.slice.call(dataRows());
    Array.prototype.forEach.call(rows, function(row, rIndex) {
      row.setAttribute('data-row', rIndex);
      var addBtn = row.querySelector('[data-add-row]');
      if (addBtn) {
        addBtn.setAttribute('data-add-row', rIndex);
      }
      var removeBtn = row.querySelector('[data-remove-row]');
      if (removeBtn) {
        removeBtn.setAttribute('data-remove-row', rIndex);
        removeBtn.disabled = rows.length <= 1;
      }

      var inputs = row.querySelectorAll('.brz-compare-cell input');
      for (var c = 0; c < inputs.length; c++) {
        inputs[c].name = 'brz_compare_rows[' + rIndex + '][' + c + ']';
      }

      while (inputs.length < columnCount()) {
        var filler = buildDataCell('');
        row.appendChild(filler);
        inputs = row.querySelectorAll('.brz-compare-cell input');
      }
      while (inputs.length > columnCount() && inputs.length > 0) {
        var last = inputs[inputs.length - 1];
        if (last && last.parentElement) {
          last.parentElement.remove();
        }
        inputs = row.querySelectorAll('.brz-compare-cell input');
      }
    });
  }

  grid.addEventListener('click', function(e) {
    var addColBtn = e.target.closest('[data-add-col]');
    if (addColBtn) {
      e.preventDefault();
      addColumn(addColBtn.getAttribute('data-add-col'));
      return;
    }

    var removeColBtn = e.target.closest('[data-remove-col]');
    if (removeColBtn) {
      e.preventDefault();
      removeColumn(removeColBtn.getAttribute('data-remove-col'));
      return;
    }

    var addRowBtn = e.target.closest('[data-add-row]');
    if (addRowBtn) {
      e.preventDefault();
      addRow(addRowBtn.getAttribute('data-add-row'));
      return;
    }

    var removeRowBtn = e.target.closest('[data-remove-row]');
    if (removeRowBtn) {
      e.preventDefault();
      removeRow(removeRowBtn.getAttribute('data-remove-row'));
    }
  });

  ensureHeaderRow();
  if (columnCount() === 0) {
    for (var i = 0; i < defaultColumns; i++) {
      headerRow.appendChild(buildHeaderCell(''));
    }
  }
  ensureAtLeastOneRow();
  renumber();

  grid.classList.add('brz-compare-grid--hydrated');
})();
