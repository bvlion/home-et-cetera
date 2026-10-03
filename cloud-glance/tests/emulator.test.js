import { test } from 'node:test';
import assert from 'node:assert/strict';

// この検証はdemoプロジェクトのエミュレーターだけに接続する。
assert.equal(process.env.GCLOUD_PROJECT, 'demo-cloud-glance');

test('HostingからHTML・CSS・JavaScriptを配信できる', async () => {
  for (const path of ['/', '/styles.css', '/app.js']) {
    const response = await fetch('http://127.0.0.1:15000' + path);
    assert.equal(response.status, 200);
    assert.ok((await response.text()).length > 0);
  }
});

test('HostingのAPI転送と関数URLの両方で未ログインを拒否する', async () => {
  for (const base of ['http://127.0.0.1:15000', 'http://127.0.0.1:15001/demo-cloud-glance/asia-east1/cloudGlance']) {
    const response = await fetch(base + '/api/session');
    assert.equal(response.status, 401);
    assert.equal(response.headers.get('cache-control'), 'no-store');
  }
});

for (const email of ['allowed@example.invalid', 'outsider@example.invalid']) {
  test(`AuthenticationのGoogleトークンを検証しallowlistを照合する: ${email}`, async () => {
    const identityResponse = await fetch('http://127.0.0.1:19099/identitytoolkit.googleapis.com/v1/accounts:signInWithIdp?key=fictional-key', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        postBody: 'providerId=google.com&id_token=' + encodeURIComponent(JSON.stringify({
          sub: email, email, email_verified: true, name: '架空の検証利用者'
        })),
        requestUri: 'http://localhost', returnSecureToken: true
      })
    });
    assert.equal(identityResponse.status, 200);
    const { idToken } = await identityResponse.json();
    for (const base of ['http://127.0.0.1:15000', 'http://127.0.0.1:15001/demo-cloud-glance/asia-east1/cloudGlance']) {
      const response = await fetch(base + '/api/session', { headers: { Authorization: 'Bearer ' + idToken } });
      assert.equal(response.status, email === 'allowed@example.invalid' ? 200 : 403);
      if (response.status === 200) assert.deepEqual(await response.json(), { isAuthorized: true });
    }
  });
}
