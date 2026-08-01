(function ($, Drupal, once) {
  Drupal.behaviors.mtSlideoutFilters = {
    attach: function (context, settings) {
      once('flashyplus-slideout-filter-window', 'html', document).forEach(function() {
        $(window).on('click', function() {
          $(".view-filters--slideout").removeClass("view-filters--slideout-open");
          $(".main-content").removeClass("slideout-filters-open");
          $("body").removeClass("slideout-filters-open");
        });
      });
      $(once('flashyplus-slideout-filter-open', '.slideout-filters-toggle', context)).on('click', function(event) {
        event.stopPropagation();
        $(".view-filters--slideout").removeClass("view-filters--slideout-open").addClass("view-filters--slideout-open");
        $("body, .main-content").removeClass("slideout-filters-open").addClass("slideout-filters-open");
      });
      $(once('flashyplus-slideout-filter-close', '.slideout-filters-close-toggle', context)).on('click', function(event) {
        event.stopPropagation();
        $(".view-filters--slideout").toggleClass("view-filters--slideout-open");
        $(".main-content").toggleClass("slideout-filters-open");
        $("body").toggleClass("slideout-filters-open");
      });
      $(once('flashyplus-slideout-filter-panel', '.view-filters--slideout', context)).on('click', function(event) {
        event.stopPropagation();
      });
    }
  };
})(jQuery, Drupal, once);
