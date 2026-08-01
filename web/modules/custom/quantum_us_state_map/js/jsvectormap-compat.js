(function (window) {
  'use strict';

  if (window.jsVectorMap && !window.JsVectorMap) {
    window.JsVectorMap = {
      prototype: {
        addMap: function (name, map) {
          window.jsVectorMap.addMap(name, map);
        }
      }
    };
  }
})(window);