(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.mtowlCarouselRelatedNodes = {
    attach: function (context, settings) {
      once('mtowlCarouselRelatedNodesInit', ".mt-carousel-related-nodes", context).forEach(function(item) {
        $(item).owlCarousel({
          items: 2,
          responsive:{
            0:{
              items:1,
            },
            480:{
              items:1,
            },
            768:{
              items:2,
            },
            992:{
              items:4,
            },
            1200:{
              items:4,
            },
            1680:{
              items:4,
            }
          },
          autoplay: drupalSettings.flashyplus.owlCarouselRelatedNodesInit.owlRelatedNodesAutoPlay,
          autoplayTimeout: drupalSettings.flashyplus.owlCarouselRelatedNodesInit.owlRelatedNodesEffectTime,
          nav: true,
          dots: true,
          loop: false,
          navText: false
        });
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
