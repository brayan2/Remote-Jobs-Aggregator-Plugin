jQuery(document).ready(function($) {
    $('.nav-tab').click(function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        var tabContent = $(this).attr('href').substring(1);
        $('.tab-content').hide();
        $('#' + tabContent).show();
    });

    // Show the first tab by default
    var currentTab = window.location.href.split('tab=')[1];
    if (currentTab) {
        $('.nav-tab').removeClass('nav-tab-active');
        $('.nav-tab[href="?page=rjobs-settings&tab=' + currentTab + '"]').addClass('nav-tab-active');
        $('.tab-content').hide();
        $('#' + currentTab).show();
    } else {
        $('.nav-tab:first').addClass('nav-tab-active');
        $('.tab-content:first').show();
    }
});
