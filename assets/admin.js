(function ($) {
    'use strict';

    function currentProductId() {
        return ecAgrupamento.productId;
    }

    function swapGroupState(resp) {
        if (!resp || !resp.success) {
            return;
        }
        $('#ec-grupo-lista').html(resp.data.list);
        $('#ec-grupo-box .notice').remove();
        $(resp.data.warnings).insertBefore('#ec-grupo-busca');
    }

    function bindRemove() {
        $('#ec-grupo-lista').on('click', '.ec-grupo-remover', function () {
            var targetId = $(this).data('product-id');
            if (!window.confirm(ecAgrupamento.i18n.confirmRemove)) {
                return;
            }

            $.post(ecAgrupamento.ajaxUrl, {
                action: 'ec_grupo_remover',
                nonce: ecAgrupamento.nonce,
                product_id: currentProductId(),
                target_id: targetId
            }).done(swapGroupState);
        });
    }

    function bindSearch() {
        var $input = $('#ec-grupo-busca');
        var $results = $('#ec-grupo-resultados');
        var timer = null;

        $input.on('input', function () {
            var term = $input.val().trim();
            clearTimeout(timer);

            if (term.length < 2) {
                $results.empty();
                return;
            }

            timer = setTimeout(function () {
                $results.text(ecAgrupamento.i18n.searching);
                $.post(ecAgrupamento.ajaxUrl, {
                    action: 'ec_grupo_buscar',
                    nonce: ecAgrupamento.nonce,
                    product_id: currentProductId(),
                    term: term
                }).done(function (resp) {
                    $results.empty();
                    if (!resp || !resp.success || !resp.data.length) {
                        $results.text(ecAgrupamento.i18n.noResults);
                        return;
                    }
                    resp.data.forEach(function (item) {
                        $('<div class="ec-grupo-resultado-item"></div>')
                            .text(item.title + (item.sku ? ' (' + item.sku + ')' : ''))
                            .attr('data-product-id', item.id)
                            .appendTo($results);
                    });
                });
            }, 300);
        });

        $results.on('click', '.ec-grupo-resultado-item', function () {
            var targetId = $(this).data('product-id');
            $.post(ecAgrupamento.ajaxUrl, {
                action: 'ec_grupo_adicionar',
                nonce: ecAgrupamento.nonce,
                product_id: currentProductId(),
                target_id: targetId
            }).done(function (resp) {
                swapGroupState(resp);
                $input.val('');
                $results.empty();
            });
        });
    }

    $(function () {
        if (!$('#ec-grupo-box').length) {
            return;
        }
        bindSearch();
        bindRemove();
    });
})(jQuery);
