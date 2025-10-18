// pwa-register.js
(function () {
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js')
      .then(function (reg) {
        // 可选：监听更新
        if (reg && reg.waiting) {
          console.log('SW waiting to activate');
        }
      })
      .catch(function (e) { console.warn('SW register failed:', e); });
  });
})();
