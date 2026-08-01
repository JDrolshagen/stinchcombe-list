(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.inPageNav = {
    attach: function (context, settings) {
      $(once('flashyplus-smooth-scroll', '.link--smooth-scroll', context)).on('click', function(e) {
        var adminHeight = parseInt($('body').css('paddingTop')) || 0;
        var anchorDestination = this.hash;
        var destination = anchorDestination ? document.querySelector(anchorDestination) : null;
        if (destination) {
          e.preventDefault();
          $('html, body').animate({
            scrollTop: $(destination).offset().top - drupalSettings.flashyplus.inPageNavigation.inPageNavigationOffset - adminHeight
          }, 1000);
          if (history.pushState) {
            history.pushState(null, null, anchorDestination);
          } else {
            location.hash = anchorDestination;
          }
        }
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
