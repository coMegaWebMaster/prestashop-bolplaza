/*
 * NOTICE OF LICENSE
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author    Mark Wienk
 *  @copyright 2013-2017 Wienk IT
 *  @license   LICENSE.txt
 */
(function($) {

    var BolPlaza = {
        listen: function() {
            $('table.bolplaza_product tbody td:nth-child(4)').each(function (idx, item) {
                var $item = $(item),
                    id = BolPlaza.getIdFromOnclickAttribute($item.attr('onclick'));
                $item.attr('bolplaza-id', id)
                    .removeAttr('onclick')
                    .append("<a><i class=\"icon-pencil\"></i></a>")
                    .click(function(e) {
                        e.preventDefault();
                        if(!($item.attr("editmode") === "true")) {
                            BolPlaza.enableEditMode($item);
                        }
                    });
            });
        },

        getIdFromOnclickAttribute: function(attr) {
            attr = attr.replace('document.location = \'', '').replace('\'', '');
            var sPageURL = decodeURIComponent(attr),
                sURLVariables = sPageURL.split('&'),
                i;

            for (i = 0; i < sURLVariables.length; i++) {
                sParameterName = sURLVariables[i].split('=');

                if (sParameterName[0] === 'id_bolplaza_product') {
                    return sParameterName[1] === undefined ? true : sParameterName[1];
                }
            }
            return false;
        },

        enableEditMode: function($item) {
            var text = $.trim($item.text()).replace(/[^\d\.,]/g, '');
            $item.empty();
            var $inner = $("<div class=\"input-group\" />")
            $inner.append("<input type=\"text\" value=\"" + text + "\"/>");
            var button = $("<div class=\"input-group-addon btn-success\"><i class=\"icon-save\"></i></div>");
            button.click(function(e) {
                e.preventDefault();
                e.stopPropagation();
                BolPlaza.save($item);
            });
            $inner.append(button);
            $item.append($inner);
            $item.attr("editmode", "true");
        },

        save: function($item) {
            var text = $item.find('input').val();

            $.post('ajax-tab.php', {
                controller:'AdminBolPlazaProducts',
                token:token,
                action:'updateBolPrice',
                id_bolplaza_product: $item.attr("bolplaza-id"),
                price:text
            }).done(function(content) {
                content = JSON.parse(content);
                if(content.failed) {
                    $.growl.error({ title: "", message: content.message});
                } else {
                    $.growl.notice({title: "", message: content.message});
                    $item.empty()
                        .text(content.price)
                        .append("<a><i class=\"icon-pencil\"></i></a>")
                        .attr("editmode", "false");
                }
            }).fail(function(xhr, status, error) {
                $.growl.error({ title: "", message:error});
            });

        }

    };

    $(document).ready(function () {
        BolPlaza.listen();
    });

})(jQuery);