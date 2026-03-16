(function ($) {
	'use strict';

	// ── Toggle rules wrap ─────────────────────────────────────────────────────

	$(document).on('change', '.lt-vend-override-cb', function () {
		var $form = $(this).closest('.lt-vend-product-form');
		$form.find('.lt-vend-rules-wrap').toggle(this.checked);
		if (this.checked) updateTotal($form);
	});

	// ── Show / hide inline editor ─────────────────────────────────────────────

	$(document).on('click', '.lt-vend-edit-btn', function () {
		var pid  = $(this).data('product');
		var $row = $('#lt-vend-editor-' + pid);
		$row.toggle();
		if ($row.is(':visible')) {
			updateTotal($row.find('.lt-vend-product-form'));
		}
	});

	$(document).on('click', '.lt-vend-cancel-btn', function () {
		$('#lt-vend-editor-' + $(this).data('product')).hide();
	});

	// ── Add row ───────────────────────────────────────────────────────────────

	$(document).on('click', '.lt-vend-add-row', function () {
		var $form  = $(this).closest('.lt-vend-product-form');
		var $table = $form.find('.lt-vend-splits-table');
		var $tbody = $table.find('tbody');

		// Read and bump the per-table row counter.
		var nextIdx = parseInt($table.data('next-idx') || $tbody.find('tr').length, 10);
		$table.data('next-idx', nextIdx + 1);

		// Clone first row as template, then update name attributes.
		var $newRow = $tbody.find('tr').first().clone();

		$newRow.find('input, select').each(function () {
			var name = $(this).attr('name');
			if (!name) return;
			// Names look like: lt_comm_rules[PID][ROW_IDX][field]
			// Replace the second bracketed integer (row index), leave product ID intact.
			$(this).attr('name', name.replace(/(\[\d+\])(\[\d+\])/, '$1[' + nextIdx + ']'));
		});

		// Reset values on the new row.
		$newRow.find('input[type="number"]').val(0);
		$newRow.find('input[type="text"]').val('');
		$newRow.find('select').prop('selectedIndex', 0);

		$tbody.append($newRow);
		updateTotal($form);
	});

	// ── Remove row ────────────────────────────────────────────────────────────

	$(document).on('click', '.lt-vend-remove-row', function () {
		var $form = $(this).closest('.lt-vend-product-form');
		$(this).closest('tr').remove();
		updateTotal($form);
	});

	// ── Live pct total ────────────────────────────────────────────────────────

	$(document).on('input change', '.lt-vend-value, .lt-vend-type-select', function () {
		updateTotal($(this).closest('.lt-vend-product-form'));
	});

	function updateTotal($form) {
		var pctTotal = 0;
		var hasFlat  = false;

		$form.find('.lt-vend-splits-table tbody tr').each(function () {
			var type  = $(this).find('.lt-vend-type-select').val();
			var value = parseFloat($(this).find('.lt-vend-value').val()) || 0;
			if (type === 'percentage') {
				pctTotal += value;
			} else {
				hasFlat = true;
			}
		});

		var $total = $form.find('.lt-vend-pct-total');
		if (pctTotal === 0 && hasFlat) {
			$total.text('').css('color', '');
			return;
		}
		if (Math.abs(pctTotal - 100) < 0.01) {
			$total.text('\u2713 100%').css('color', '#2ecc71');
		} else {
			$total.text('Total: ' + pctTotal.toFixed(2) + '% \u2014 must equal 100%').css('color', '#e74c3c');
		}
	}

}(jQuery));
