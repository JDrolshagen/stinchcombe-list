(function ($, Drupal, once) {
  Drupal.behaviors.mtWaypointsStickyFooter = {
    attach: function (context, settings) {
      once('flashyplus-sticky-footer', '.sticky-footer-container', context).forEach(function() {
        var header = document.querySelector('.header-container');
        var placeholder = document.querySelector('.sticky-footer-placeholder');
        var followingElement = placeholder ? placeholder.nextElementSibling : null;

        if (!header || !placeholder || !followingElement || !window.Waypoint || !window.Waypoint.Inview) {
          return;
        }

        new Waypoint.Inview({
        element: header,
        exit: function(direction) {
          if (direction === 'down') {
            var stickyFooterHeight = $(".sticky-footer-container").outerHeight(true);
            $(".sticky-footer-container").addClass("slideToTop sticky-footer-container--fixed");
            $(".sticky-footer-container").removeClass("slideToBottom");
            $(".sticky-footer-placeholder").css("height", (stickyFooterHeight)+"px");
          }
        },
        entered: function(direction) {
          if (direction === 'up') {
            $(".sticky-footer-container").removeClass("slideToTop");
            $(".sticky-footer-container").addClass("slideToBottom sticky-footer-container--fixed");
          }
        }
      });
        new Waypoint.Inview({
        element: followingElement,
        enter: function(direction) {
          if (direction === 'down') {
            $(".sticky-footer-container").removeClass("sticky-footer-container--fixed");
            $(".sticky-footer-placeholder").css("height", "0px");
          }
        },
        exited: function(direction) {
          if (direction === 'up') {
            var stickyFooterHeight = $(".sticky-footer-container").outerHeight(true);
            $(".sticky-footer-container").addClass("sticky-footer-container--fixed");
            $(".sticky-footer-placeholder").css("height", (stickyFooterHeight)+"px");
          }
        }
      });
      });
    }
  };
})(jQuery, Drupal, once);
