(function ($, Drupal, once) {
  Drupal.behaviors.mtWaypointsFixedShareLinks = {
    attach: function (context, settings) {
      once('flashyplus-fixed-share-links', '.node__main-content', context).forEach(function(element) {
        if (!window.Waypoint || !window.Waypoint.Inview) {
          return;
        }
        new Waypoint.Inview({
          element: element,
          entered: function() {
            $('body').removeClass('js-share-links-fixed');
          },
          exit: function(direction) {
            if (direction === 'down') {
              $('body').addClass('js-share-links-fixed');
            }
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
