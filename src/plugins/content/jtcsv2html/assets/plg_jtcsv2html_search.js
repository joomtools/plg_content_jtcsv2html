var jtCsv2HtmlDomIsReady = function jtCsv2HtmlDomIsReady(fn) {
	if (document.readyState !== 'loading') {
		console.info('Dom is ready');
		fn();
	} else if (document.addEventListener) {
		document.addEventListener('DOMContentLoaded', fn);
	} else {
		document.attachEvent('onreadystatechange', function () {
				if (document.readyState !== 'loading') {
					console.info('Dom is ready');
					fn();
				}
			}
		);
	}
};

jtCsv2HtmlDomIsReady(function () {
	var $jtcsv2html_wrapper = document.querySelectorAll('.jtcsv2html_wrapper');

	Array.prototype.forEach.call($jtcsv2html_wrapper, function (el) {

		var $search = el.querySelector('.search');
		var $rows = el.querySelectorAll('tbody > tr');

		$search.addEventListener('keyup', function () {
			var val = this.value.trim().replace(/ +/g, ' ').toLowerCase();

			Array.prototype.forEach.call($rows, function (row) {
				var text = row.innerText.replace(/\s+/g, ' ').toLowerCase();

				if (text.match(val)) {
					row.style.display = "table-row"
				} else {
					row.style.display = "none"
				}
			});
		});
	});
});

