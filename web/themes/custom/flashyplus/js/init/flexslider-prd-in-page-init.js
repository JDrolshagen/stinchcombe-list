(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.mtflexsliderInPage = {
    attach: function (context, settings) {

      once('mtflexsliderInPageSliderInit', ".in-page-images-slider", context).forEach(function(item) {
        $(item).flexslider({
          animation: drupalSettings.flashyplus.flexsliderInPageInit.inPageSliderEffect,
          controlNav: true,
          directionNav: false,
          slideshow: true,
        });

        $(item).fadeIn("slow");

      });

    }
  };
})(jQuery, Drupal, drupalSettings, once);