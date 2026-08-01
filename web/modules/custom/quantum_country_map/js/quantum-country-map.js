(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.quantumCountryMap = {
    attach: function (context) {
      once('quantum-country-map', '#quantum-world-country-map-canvas', context).forEach(function (element) {
        if (typeof jsVectorMap === 'undefined') {
          console.error('Quantum Country Map: jsVectorMap is not loaded.');
          return;
        }

        const mapSettings = drupalSettings.quantumCountryMap || {};
        const links = mapSettings.links || {};
        const activeRegionCodes = mapSettings.activeRegionCodes || Object.keys(links);

        new jsVectorMap({
          selector: element,
          map: 'world',
          backgroundColor: 'transparent',
          zoomButtons: false,
          zoomOnScroll: false,
          draggable: false,
          showTooltip: true,

          selectedRegions: activeRegionCodes,

          regionStyle: {
            initial: {
              fill: '#d7dde8',
              stroke: '#ffffff',
              strokeWidth: 0.75,
              fillOpacity: 1
            },
            hover: {
              fill: '#b6c0d1',
              fillOpacity: 1
            },
            selected: {
              fill: '#3f557a',
              fillOpacity: 1
            },
            selectedHover: {
              fill: '#2f4163',
              fillOpacity: 1
            }
          },

          onRegionClick: function (event, code) {
            const normalizedCode = String(code).toUpperCase();
            const target = links[normalizedCode];

            if (!target) {
              event.preventDefault();
              return false;
            }

            window.location.href = target;
          },

          onLoaded: function (map) {
            window.addEventListener('resize', function () {
              map.updateSize();
            });
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);