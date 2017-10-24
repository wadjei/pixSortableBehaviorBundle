(function($){
    $(document).ready(function(){
        var btnClose, divOkOpen, divInfoOpen, divErrOpen, divWarnOpen, alertPlace;
        btnClose    = '<button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>';
        divOkOpen   = '<div class="list-dnd alert alert-success alert-dismissable">';
        divInfoOpen = '<div class="list-dnd alert alert-info alert-dismissable">';
        divWarnOpen = '<div class="list-dnd alert alert-warning alert-dismissable">';
        divErrOpen  = '<div class="list-dnd alert alert-danger alert-dismissable">';
        alertPlace  = $("section.content > div.row").first();

        var permitDnD = function() {
            return $(".bhbadmin-table-header-sorting.sonata-ba-list-field-header-order-asc.sonata-ba-list-field-order-active").length > 0;
        };

        var computeSortColumnNumber = function() {
            var found = false, columns = $("form table > thead > tr > th");

            for (var col in columns) {
                if ($(columns[col]).hasClass("bhbadmin-table-header-sorting")) {
                    found = true;
                    break;
                }
            }
            return found ? +col + 1 : 0;
        };

        $("form table > tbody")
            .sortable({
              top_item: null,
              helper: function(e, tr) {
                    // this is supposed to keep the width the same. Doesnt seem to work properly.
                    var $originals = tr.children();
                    var $helper = tr.clone();
                    $helper.children().each(function(index) {
                      $(this).width($originals.eq(index).width());
                    })
                    return $helper;
                },
                start: function (event, ui) {
                    var sortColumn = computeSortColumnNumber();
                    this.top_item = $("form table > tbody tr:first-child td:nth-child(" + sortColumn + ")");
                },
                stop: function (event, ui) {
                    if (!permitDnD()) {
                        console.log('Drag and drop sorting disabled due to inconsistent sort mode');
                        $(this).sortable('cancel');
                        alertPlace.before(
                            divErrOpen + btnClose + 'Drag ordering is can only be done when the list is in ascending sorting order.' + '</div>'
                        );
                    }
                },
                update: function(e, tr) {
                    if (!permitDnD()) {
                        e.preventDefault();
                        return;
                    }

                    // do stuff
                    var params = {order: []}
                    var sortColumn = computeSortColumnNumber();
                    $("form table > tbody tr").each(function(i,el){
                        params.order.push($(this).find("td:first-child").attr("objectid"));
                    });

                    params.first_sorting = (this.top_item || $("form table > tbody tr:first-child td:nth-child(" + sortColumn + ")")).text().trim();
                    $.ajax({
                        'type': 'POST',
                        'url':  window.location.href.replace(/\/list.*$/, "/resort"),
                        'data': JSON.stringify(params),
                        'success': function(response) {
                            $("section.content .list-dnd").remove();
                            alertPlace.before(
                                divOkOpen + btnClose + response.message + '</div>'
                            );

                            if (response.redraw_recommended) {
                                window.location.reload();
                            } else {
                                var rows = $("form table > tbody tr");
                                // use remap information to update the cached sort information
                                for (let objectId in response.remap) {
                                    if (sortColumn) {
                                        rows
                                          .find("td[objectId=" + objectId + "]:nth-child(" + sortColumn + ")")
                                          .text(response.remap[objectId]);
                                    }
                                }
                            }
                        },
                        'error': function(jqXHR) {
                            var message, response;

                            response = JSON.parse(jqXHR.responseText);
                            message  = "Page order update was not successful - ";

                            if (typeof(response.message) == "undefined") {
                                message += jqXHR.statusText;
                            } else {
                                message += response.message;
                            }

                            $("section.content .list-dnd").remove();
                            alertPlace.before(
                                divErrOpen + btnClose + message + '</div>'
                            );
                        }
                    });
                 }
            })
            .disableSelection()
            ;
    })
})(jQuery);
