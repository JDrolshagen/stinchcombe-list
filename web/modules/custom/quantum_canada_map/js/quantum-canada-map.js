(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.quantumCanadaMap = {
    attach: function (context) {
      once('quantum-canada-map-canvas', '.quantum-canada-map-canvas', context).forEach(function (element) {
        const mapId = element.getAttribute('data-map-id') || element.id;

        if (!mapId) {
          return;
        }

        if (typeof window.jsVectorMap !== 'function') {
          console.error('The Canada map library did not load: window.jsVectorMap is unavailable.');
          return;
        }

        const allSettings = drupalSettings.quantumCanadaMap || {};
        const settings = allSettings[mapId] || {};
        const links = settings.links || {};
        const activeRegionCodes = Array.isArray(settings.activeRegionCodes)
          ? settings.activeRegionCodes
          : Object.keys(links);
        const mapRegionCodes = activeRegionCodes.map(function (code) {
          return String(code).replace(/^CA-/i, '').toLowerCase();
        });

        element.innerHTML = '';

        new window.jsVectorMap({
          selector: '#' + mapId,
          map: 'canada',
          backgroundColor: 'transparent',
          zoomButtons: false,
          zoomOnScroll: false,
          draggable: false,
          showTooltip: true,

          selectedRegions: mapRegionCodes,

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
            const normalizedCode = 'CA-' + String(code).replace(/^CA-/i, '').toUpperCase();
            const target = links[normalizedCode];

            if (!target) {
              event.preventDefault();
              return false;
            }

            window.open(target, '_blank', 'noopener,noreferrer');
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
