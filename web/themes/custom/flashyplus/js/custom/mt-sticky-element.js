(function ($, Drupal, once) {
  Drupal.behaviors.mtStickyElement = {
    attach: function (context, settings) {

      //The admin overlay menu height
      var adminHeight = parseInt($('body').css('paddingTop'));

      // The Fixed header height
      var navigationHeight = $('.fixed-header-enabled .header').outerHeight(true);

      var topValue = (adminHeight || 0) + (navigationHeight || 0) + 10;

      $(once('flashyplus-sticky-element', '.mt-sticky-element', context)).each(function() {
        $(this).css("top", (topValue)+"px");
      });

    }
  };
})(jQuery, Drupal, once);
