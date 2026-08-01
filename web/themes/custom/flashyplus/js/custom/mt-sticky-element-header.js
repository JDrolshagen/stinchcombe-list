(function ($, Drupal, once) {
  Drupal.behaviors.mtStickyElementHeader = {
    attach: function (context, settings) {

      //The admin overlay menu height
      var adminHeight = parseInt($('body').css('paddingTop'));

      var topValue = adminHeight || 0;

      $(once('flashyplus-sticky-element-header', '.mt-sticky-element-header', context)).each(function() {
        $(this).css("top", (topValue)+"px");
      });

    }
  };
})(jQuery, Drupal, once);
