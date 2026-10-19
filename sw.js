// TOBG のサービスワーカー。
// いまはプッシュ通知の受け取りと、通知を押したときにサイトを開く処理だけを行う（画面のキャッシュはしない）。
// サイトの一番上に置き、サイト全体を担当範囲にする。

self.addEventListener('install', () => {
    // 新しい版をすぐ使えるようにする
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// サーバーから届いた通知を表示する。中身は JSON: { title, body, url, tag }
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'TOBG';
    const options = {
        body: data.body || '',
        icon: new URL('icons/icon-192.png', self.registration.scope).href,
        badge: new URL('icons/icon-192.png', self.registration.scope).href,
        tag: data.tag || undefined,
        data: { url: data.url || self.registration.scope },
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

// 通知を押したら、そのページを開く。すでにサイトが開いていれば、そのタブを使う
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = new URL(event.notification.data && event.notification.data.url ? event.notification.data.url : self.registration.scope, self.registration.scope).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (new URL(client.url).origin === new URL(targetUrl).origin && 'focus' in client) {
                await client.focus();
                if ('navigate' in client) {
                    await client.navigate(targetUrl);
                }
                return;
            }
        }
        await self.clients.openWindow(targetUrl);
    })());
});
