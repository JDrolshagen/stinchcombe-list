(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.mtTransparentHeader = {
    attach: function (context, settings) {
      once('flashyplus-transparent-header', '.header-container', context).forEach(function() {

        if ($('.banner .slideshow-fullscreen').length > 0 || $('.banner .main-slideshow-block').length > 0) {
          $(".header-container").addClass("js-transparent-header");
        } else {
          $(".header-container").removeClass("js-transparent-header");
        }

        if ($('.header-container.js-transparent-header header.header').length > 0) {
          var header_color = $(".header-container.js-transparent-header header.header").css("background-color").replace(")", "," + drupalSettings.flashyplus.transparentHeader.transparentHeaderOpacity + ")").replace("rgb", "rgba");
          $(".header-container.js-transparent-header header.header").css("background-color", header_color);
        }

        if ($('.header-container.js-transparent-header .header-top').length > 0) {
          var header_top_color = $(".header-container.js-transparent-header .header-top").css("background-color").replace(")", "," + drupalSettings.flashyplus.transparentHeader.transparentHeaderOpacity + ")").replace("rgb", "rgba");
          $(".header-container.js-transparent-header .header-top").css("background-color", header_top_color);
        }

        if ($('.header-container.js-transparent-header .header-top-highlighted').length > 0) {
          var header_top_highlighted_color = $(".header-container.js-transparent-header .header-top-highlighted").css("background-color").replace(")", "," + drupalSettings.flashyplus.transparentHeader.transparentHeaderOpacity + ")").replace("rgb", "rgba");
          $(".header-container.js-transparent-header .header-top-highlighted").css("background-color", header_top_highlighted_color);
        }
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
