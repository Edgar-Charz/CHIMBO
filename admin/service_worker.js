self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const fcmMessage = event.notification.data?.FCM_MSG;
  const target = fcmMessage?.data?.url || event.notification.data?.url || self.registration.scope;
  const targetUrl = new URL(target, self.registration.scope);
  const scopeUrl = new URL(self.registration.scope);
  if (targetUrl.origin !== self.location.origin || !targetUrl.pathname.startsWith(scopeUrl.pathname)) return;

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      const clientUrl = new URL(client.url);
      if (clientUrl.origin === scopeUrl.origin && clientUrl.pathname.startsWith(scopeUrl.pathname)) {
        if (clientUrl.href !== targetUrl.href) await client.navigate(targetUrl.href);
        return client.focus();
      }
    }
    return self.clients.openWindow(targetUrl.href);
  })());
});

importScripts('https://www.gstatic.com/firebasejs/13.0.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/13.0.0/firebase-messaging-compat.js');
// Refresh this worker when its push handling changes.
importScripts('firebase_config.js.php');
const messaging = firebase.messaging();
messaging.onBackgroundMessage((payload) => {
  const notification = payload.notification || {};
  const title = notification.title || 'CHIMBO Admin';
  const options = {
    body: notification.body || 'There is work waiting in the admin site.',
    icon: new URL('assets/img/favicon-192.png', self.registration.scope).href,
    data: { url: payload.data?.url || self.registration.scope },
  };
  self.registration.showNotification(title, options);
});