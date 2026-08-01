(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.quantumUsStateMap = {
    attach: function (context) {
      once('quantum-us-state-map-canvas', '.quantum-us-state-map-canvas', context).forEach(function (element) {
        const mapId = element.getAttribute('data-map-id') || element.getAttribute('id');

        if (!mapId) {
          element.classList.add('quantum-us-state-map--error');
          element.textContent = Drupal.t('The U.S. map container is missing a map ID.');
          return;
        }

        const allSettings = drupalSettings.quantumUsStateMap || {};
        const settings = allSettings[mapId] || {};
        const links = settings.links || {};
        const activeRegionCodes = Array.isArray(settings.activeRegionCodes)
          ? settings.activeRegionCodes
          : Object.keys(links);

        if (typeof window.jsVectorMap !== 'function') {
          element.classList.add('quantum-us-state-map--error');
          element.textContent = Drupal.t('The U.S. map library did not load.');
          return;
        }

        try {
          element.innerHTML = '';

          new window.jsVectorMap({
            selector: '#' + mapId,
            map: 'us_merc_en',
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

          element.classList.add('quantum-us-state-map--loaded');
        }
        catch (error) {
          element.classList.add('quantum-us-state-map--error');
          element.textContent = Drupal.t('The U.S. map could not be rendered.');

          if (window.console && typeof window.console.error === 'function') {
            window.console.error('Quantum US State Map error:', error);
          }
        }
      });
    }
  };

})(Drupal, drupalSettings, once);