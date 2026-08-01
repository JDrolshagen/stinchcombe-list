(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.mtowlCarouselTestimonials = {
    attach: function (context, settings) {
      once('mtowlCarouselTestimonialsInit', ".mt-carousel-testimonials", context).forEach(function(item) {
        $(item).owlCarousel({
          items: 1,
          autoplay: drupalSettings.flashyplus.owlCarouselTestimonialsInit.owlTestimonialsAutoPlay,
          autoplayTimeout: drupalSettings.flashyplus.owlCarouselTestimonialsInit.owlTestimonialsEffectTime,
          nav: true,
          dots: false,
          loop: false,
          navText: false
        });
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
