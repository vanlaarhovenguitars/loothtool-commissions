(function ($) {
	'use strict';

	if (!$('#lt-comm-splits-table').length) return;

	var rowIndex = $('#lt-comm-splits-table tbody tr').length;

	// Toggle split rules section when override checkbox changes.
	$('input[name="lt_comm_override_enabled"]').on('change', function () {
		$('#lt-comm-splits-wrap').toggle(this.checked);
		if (this.checked) updateTotal();
	});

	// Add new row.
	$('#lt-comm-add-row').on('click', function () {
		var template = $('#lt-comm-row-template tr').first().clone();
		// Replace __IDX__ placeholder with actual index.
		template.find('input, select').each(function () {
			var name = $(this).attr('name');
			if (name) {
				$(this).attr('name', name.replace('__IDX__', rowIndex));
			}
		});
		template.find('input[type="number"]').val(0);
		template.find('input[type="text"]').val('');
		$('#lt-comm-splits-table tbody').append(template);
		rowIndex++;
		updateTotal();
	});

	// Remove row.
	$(document).on('click', '.lt-comm-remove-row', function () {
		$(this).closest('tr').remove();
		updateTotal();
	});

	// Recalculate total on value change.
	$(document).on('input change', '.lt-comm-value, .lt-comm-type-select', function () {
		updateTotal();
	});

	function updateTotal() {
		var pctTotal = 0;
		var hasFlat  = false;
		$('#lt-comm-splits-table tbody tr').each(function () {
			var type  = $(this).find('.lt-comm-type-select').val();
			var value = parseFloat($(this).find('.lt-comm-value').val()) || 0;
			if (type === 'percentage') {
				pctTotal += value;
			} else {
				hasFlat = true;
			}
		});

		var $total = $('#lt-comm-pct-total');
		if (pctTotal === 0 && hasFlat) {
			$total.text('').css('color', '');
			return;
		}
		var diff = Math.abs(pctTotal - 100);
		if (diff < 0.01) {
			$total.text('✓ 100%').css('color', '#2ecc71');
		} else {
			$total.text('Total: ' + pctTotal.toFixed(2) + '% — must equal 100%').css('color', '#e74c3c');
		}
	}

	// Run on load.
	updateTotal();

}(jQuery));
