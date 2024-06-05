$(document).ready(function() {
    $("#menu-toggle").click(function(e) {
        e.preventDefault();
        $("#wrapper").toggleClass("toggled");
    });

    $(".list-group-item").click(function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        loadPage(page);
    });

    function loadPage(page) {
        $.ajax({
            url: page + ".php",
            method: "GET",
            success: function(data) {
                $("#page-content").html(data);
            },
            error: function() {
                $("#page-content").html("<h1>Page not found</h1>");
            }
        });
    }
});
