/* sw.js - minimal PWA service worker */
const SW_VERSION = 'v1.0.0';
const STATIC_CACHE = `static-${SW_VERSION}`;
const DYNAMIC_CACHE = `dynamic-${SW_VERSION}`;
const OFFLINE_URL = '/offline.html';

// 按需调整：静态资源白名单（尽量只放不常改的文件）
const STATIC_ASSETS = [
  '/',               // 可改为首页，如 '/index.php'
  '/offline.html',
  '/manifest.webmanifest',
  '/style/tabler.min.css',   // 如你使用本地 Tabler
  '/style/tabler.min.js'     // 如你使用本地 Tabler
];

// 判定是否为敏感/不缓存的 URL（私有页、API、安装器等）
function isSensitive(url) {
  const u = new URL(url, self.location.origin);
  const path = u.pathname.toLowerCase();

  // 带查询参数的 PHP 一律不缓存（避免 token、筛选等）
  if (path.endsWith('.php') && u.search) return true;

  // 常见私有路由/接口/安装器
  const deny = ['login', 'logout', 'register', 'admin', 'api', 'install', 'token', 'auth'];
  return deny.some(key => path.includes(`/${key}`));
}

// 安装：预缓存静态资源与离线页
self.addEventListener('install', (evt) => {
  evt.waitUntil(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      await cache.addAll(STATIC_ASSETS);
      await cache.add(new Request(OFFLINE_URL, { cache: 'reload' }));
    })()
  );
  self.skipWaiting();
});

// 激活：清理旧缓存
self.addEventListener('activate', (evt) => {
  evt.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys.filter(k => ![STATIC_CACHE, DYNAMIC_CACHE].includes(k))
            .map(k => caches.delete(k))
      );
      await self.clients.claim();
    })()
  );
});

// 提取是否为静态资源
function isStaticAsset(req) {
  const url = new URL(req.url);
  return STATIC_ASSETS.includes(url.pathname) ||
         /\.(?:css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|eot)$/i.test(url.pathname);
}

// 统一 fetch 策略分发
self.addEventListener('fetch', (evt) => {
  const req = evt.request;

  // 只处理 GET
  if (req.method !== 'GET') return;

  // 不拦截跨域第三方（如你没有要缓存的 CDN）
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // 私有/敏感路由：直接网络优先，不缓存
  if (isSensitive(req.url)) {
    evt.respondWith(networkFirst(req, { cache: false }));
    return;
  }

  // 静态资源：缓存优先
  if (isStaticAsset(req)) {
    evt.respondWith(cacheFirst(req));
    return;
  }

  // 其他页面：网络优先 + 离线回退
  evt.respondWith(networkFirst(req, { cache: true }));
});

// 策略：缓存优先（静态资源）
async function cacheFirst(req) {
  const cached = await caches.match(req);
  if (cached) return cached;
  try {
    const res = await fetch(req);
    const cache = await caches.open(STATIC_CACHE);
    cache.put(req, res.clone());
    return res;
  } catch (err) {
    // 静态资源失败也尝试离线页
    return caches.match(OFFLINE_URL);
  }
}

// 策略：网络优先（页面）
async function networkFirst(req, { cache }) {
  try {
    const res = await fetch(req, { cache: 'no-store' });
    if (cache) {
      const c = await caches.open(DYNAMIC_CACHE);
      c.put(req, res.clone());
    }
    return res;
  } catch (err) {
    const cached = await caches.match(req);
    if (cached) return cached;
    return caches.match(OFFLINE_URL);
  }
}
