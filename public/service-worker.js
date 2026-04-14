// Basic service worker for PWA
self.addEventListener('install', event => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    self.clients.claim();
});


// Listen for push events to show notifications with vibration and sound
self.addEventListener('push', function (event) {
    let data = {};
    if (event.data) {
        data = event.data.json();
    }
    const title = data.title || 'Order Ready!';
    const options = {
        body: data.body || 'Your order is ready. Please collect it.',
        icon: data.icon || '/favicon.ico',
        badge: data.badge || '/favicon.ico',
        vibrate: [200, 100, 200, 100, 200],
        data: data.data || {},
        // No direct way to play sound from service worker, but we can add a sound property for clients
        sound: data.sound || '/sound/bell.mp3',
    };
    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Optionally, handle notificationclick to focus/open the app and play sound if possible
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            // Try to focus an open window
            for (const client of clientList) {
                if (client.url && 'focus' in client) {
                    client.postMessage({ playSound: true });
                    return client.focus();
                }
            }
            // Otherwise, open a new window
            return self.clients.openWindow('/');
        })
    );
});
