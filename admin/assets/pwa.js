(() => {
  const enableButton = document.querySelector('[data-admin-push-enable]');
  const manifestUrl = document.querySelector('link[rel="manifest"]')?.href;
  if (!manifestUrl) return;
  const setButtonStatus = (message) => {
    if (!enableButton) return;
    enableButton.title = message;
    enableButton.setAttribute('aria-label', message);
  };
  if (!('Notification' in window) || !('serviceWorker' in navigator)) {
    if (enableButton) {
      enableButton.disabled = true;
      setButtonStatus('Browser notifications are unavailable in this browser or page context.');
    }
    return;
  }
  if (Notification.permission === 'granted') setButtonStatus('Set up browser notifications on this device.');
  if (Notification.permission === 'denied') setButtonStatus('Notifications are blocked. Change this site’s browser settings to allow them.');

  const workerUrl = new URL('service_worker.js', manifestUrl);
  const configUrl = new URL('firebase_config.php', manifestUrl);
  const registrationUrl = new URL('ajax/push_registration.php', manifestUrl);
  let registrationPromise;

  const registerWorker = () => {
    registrationPromise ??= navigator.serviceWorker.register(workerUrl.href, { updateViaCache: 'none' });
    return registrationPromise;
  };

  const loadFirebase = async () => {
    if (!window.firebase) {
      await new Promise((resolve, reject) => {
        const appScript = document.createElement('script');
        appScript.src = 'https://www.gstatic.com/firebasejs/13.0.0/firebase-app-compat.js';
        appScript.onload = resolve;
        appScript.onerror = reject;
        document.head.append(appScript);
      });
      await new Promise((resolve, reject) => {
        const messagingScript = document.createElement('script');
        messagingScript.src = 'https://www.gstatic.com/firebasejs/13.0.0/firebase-messaging-compat.js';
        messagingScript.onload = resolve;
        messagingScript.onerror = reject;
        document.head.append(messagingScript);
      });
    }
    const configResponse = await fetch(configUrl, { credentials: 'same-origin', cache: 'no-store' });
    if (!configResponse.ok) throw new Error('Firebase settings could not be loaded.');
    const config = await configResponse.json();
    if (!window.firebase.apps.length) window.firebase.initializeApp(config);
    return { config, messaging: window.firebase.messaging() };
  };

  const updateToken = async (token, action = 'register') => {
    const response = await fetch(registrationUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': enableButton?.dataset.csrf ?? '' },
      body: JSON.stringify({ action, device_token: token }),
    });
    if (!response.ok) throw new Error('The browser notification registration could not be updated.');
  };

  const enableNotifications = async () => {
    if (!enableButton) return;
    enableButton.disabled = true;
    try {
      if (!window.isSecureContext) throw new Error('Open the admin site over HTTPS or localhost to enable notifications.');
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        const message = permission === 'denied'
          ? 'Notifications are blocked for this site. Allow them in your browser site settings, then try again.'
          : 'Allow notifications in your browser to receive admin alerts.';
        throw new Error(message);
      }
      const registration = await registerWorker();
      const { config, messaging } = await loadFirebase();
      const token = await messaging.getToken({
        vapidKey: config.vapidKey,
        serviceWorkerRegistration: registration,
      });
      if (!token) throw new Error('Firebase did not return a notification token.');
      await updateToken(token, 'register');
      setButtonStatus('Browser notifications are enabled on this device.');
      window.alert('Browser notifications are enabled for this device.');
    } catch (error) {
      setButtonStatus(error.message || 'Could not enable browser notifications.');
      window.alert(error.message || 'Could not enable browser notifications.');
    } finally {
      enableButton.disabled = false;
    }
  };

  enableButton?.addEventListener('click', enableNotifications);


  registerWorker().then(async (registration) => {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const { config, messaging } = await loadFirebase();
    const token = await messaging.getToken({
      vapidKey: config.vapidKey,
      serviceWorkerRegistration: registration,
    });
    if (token) {
      await updateToken(token, 'register');
      setButtonStatus('Browser notifications are enabled on this device.');
    }
    messaging.onMessage((payload) => {
      const title = payload.notification?.title || 'CHIMBO Admin';
      const body = payload.notification?.body || 'There is work waiting in the admin site.';
      registration.showNotification(title, {
        body,
        icon: new URL('assets/img/favicon-192.png', manifestUrl).href,
        data: { url: payload.data?.url || manifestUrl },
      });
    });
  }).catch(() => {
    if (Notification.permission === 'granted') setButtonStatus('Browser notifications setup failed. Click to retry.');
  });
})();