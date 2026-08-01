(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.inPageNavScrollspy = {
    attach: function (context, settings) {
      var navigation = document.querySelector('.in-page-navigation');
      var target = navigation ? navigation.closest('.content') : null;

      if (!target || !window.bootstrap || !window.bootstrap.ScrollSpy) {
        return;
      }

      target.classList.add('in-page-navigation-container');
      document.body.classList.add('in-page-navigation-active');

      once('flashyplus-in-page-scrollspy', 'body', document).forEach(function (body) {
        var toolbarHeight = parseInt(window.getComputedStyle(body).paddingTop, 10) || 0;
        window.bootstrap.ScrollSpy.getOrCreateInstance(body, {
          target: target,
          offset: drupalSettings.flashyplus.inPageNavigation.inPageNavigationOffset + toolbarHeight + 1
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
