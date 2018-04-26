(function ($) {
    $(document).ready(function ()
    {
        {
            $('.jtcsv2html_wrapper').each(function () {

                var $search = $(this).find('.search');
                var $rows = $(this).find('tbody > tr');

                $search.keyup(function () {
                    var val = $.trim($(this).val()).replace(/ +/g, ' ').toLowerCase();

                    $rows.show().filter(function () {
                        var text = $(this).text().replace(/\s+/g, ' ').toLowerCase();
                        return !~text.indexOf(val);
                    }).hide();
                });
            });
        }
    });
})(jQuery);
