const { test, before, after, beforeEach, afterEach, mock } = require('node:test');
const assert = require('node:assert/strict');
const { cloudGlance } = require('../index');
const { getAuth } = require('firebase-admin/auth');
const express = require('express');
const http = require('node:http');

// Functions Frameworkが付与するJSON本文とrawBodyをHTTPテストでも再現する。
const server = http.createServer(express().use(express.json({
  limit: '1mb',
  verify(request, response, body) { request.rawBody = body; }
})).use(cloudGlance));
before(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
});
after(async () => {
  await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
});
let request;
let response;
let identity;

beforeEach(() => {
  process.env.HOME_GOOGLE_ACCOUNT = ' allowed@example.invalid , second@example.invalid ';
  process.env.OPENAI_API_KEY = 'fictional-test-key';
  process.env.HOME_LOCATION = '架空の地域';
  process.env.INCOMING_WEBHOOK_URL = 'https://slack.example.invalid/fictional-webhook';
  process.env.SLACK_POST_SETTINGS = JSON.stringify({ channel: '#fictional', username: '架空の表示名', icon_url: 'https://example.invalid/icon.png' });
  identity = { email: 'ALLOWED@example.invalid', email_verified: true, firebase: { sign_in_provider: 'google.com' } };
  mock.method(getAuth(), 'verifyIdToken', async () => identity);
  mock.method(global, 'fetch', async () => { throw new Error('外部通信は禁止'); });
  request = {
    headers: { Authorization: 'Bearer fictional-token', 'Content-Type': 'application/json' },
    path: '/api/ask', method: 'POST', body: { question: '架空の質問' }
  };
  response = undefined;
});
afterEach(() => mock.restoreAll());

test('デプロイするHTTP関数は1つで、5つのSecretをバインドする', () => {
  assert.deepEqual(Object.keys(require('../index')), ['cloudGlance']);
  assert.deepEqual(cloudGlance.__endpoint.region, ['asia-east1']);
  assert.equal(cloudGlance.__endpoint.timeoutSeconds, 60);
  assert.equal(cloudGlance.__endpoint.secretEnvironmentVariables.length, 5);
});

for (const path of ['/api/session', '/api/ask', '/api/share']) {
  test(`${path}: 未ログインでは外部通信をせず拒否する`, async () => {
    request.path = path;
    request.method = path === '/api/session' ? 'GET' : 'POST';
    delete request.headers.Authorization;
    await sendRequest();
    assert.equal(response.statusCode, 401);
    assert.equal(global.fetch.mock.callCount(), 0);
    assert.equal(response.headers['cache-control'], 'no-store');
  });
  test(`${path}: allowlist外のGoogle利用者を拒否する`, async () => {
    request.path = path;
    request.method = path === '/api/session' ? 'GET' : 'POST';
    identity.email = 'outsider@example.invalid';
    await sendRequest();
    assert.equal(response.statusCode, 403);
    assert.equal(global.fetch.mock.callCount(), 0);
  });
}

for (const rejectedIdentity of [
  { email: '', email_verified: true, firebase: { sign_in_provider: 'google.com' } },
  { email: 'allowed@example.invalid', email_verified: false, firebase: { sign_in_provider: 'google.com' } },
  { email: 'allowed@example.invalid', email_verified: true, firebase: { sign_in_provider: 'password' } }
]) {
  test(`メール未取得・未検証・Google以外の認証を拒否: ${JSON.stringify(rejectedIdentity)}`, async () => {
    identity = rejectedIdentity;
    await sendRequest();
    assert.equal(response.statusCode, 403);
    assert.equal(global.fetch.mock.callCount(), 0);
  });
}

test('期限切れ・失効したIDトークンを拒否し、詳細を公開しない', async () => {
  getAuth().verifyIdToken.mock.mockImplementation(async () => { throw new Error('fictional-private-detail'); });
  await sendRequest();
  assert.equal(response.statusCode, 401);
  assert.ok(!JSON.stringify(response.body).includes('fictional-private-detail'));
  assert.deepEqual(getAuth().verifyIdToken.mock.calls[0].arguments, ['fictional-token', true]);
});

test('許可確認はメールを正規化し、allowlist変更も次の要求で反映する', async () => {
  request.path = '/api/session'; request.method = 'GET';
  await sendRequest();
  assert.deepEqual(response.body, { isAuthorized: true });
  process.env.HOME_GOOGLE_ACCOUNT = '';
  await sendRequest();
  assert.equal(response.statusCode, 403);
});

for (const question of ['', ' ', 'a'.repeat(4001), { value: '質問' }]) {
  test(`不正な質問を送信せず拒否: ${typeof question}/${String(question).length}`, async () => {
    request.body.question = question;
    await sendRequest();
    assert.equal(response.statusCode, 400);
    assert.equal(global.fetch.mock.callCount(), 0);
  });
}

test('Responses要求と日本時間・地域コンテキストを維持し、複数本文の出典位置を結合する', async () => {
  mock.timers.enable({ apis: ['Date'], now: new Date('2026-01-01T15:04:05Z') });
  global.fetch.mock.mockImplementation(async () => ({ ok: true, json: async () => ({ output: [
    { type: 'web_search_call' },
    { type: 'message', role: 'assistant', content: [
      { type: 'output_text', text: '😀説明', annotations: [] },
      { type: 'output_text', text: '出典です', annotations: [
        { type: 'url_citation', url: 'https://example.invalid/source', title: '架空の出典', start_index: 0, end_index: 2 },
        { type: 'url_citation', url: 'javascript:alert(1)', start_index: 0, end_index: 2 }
      ] }
    ] }
  ] }) }));
  await sendRequest();
  assert.equal(response.statusCode, 200);
  assert.deepEqual(response.body, { answer: '😀説明出典です', citations: [
    { url: 'https://example.invalid/source', title: '架空の出典', startIndex: 3, endIndex: 5 }
  ] });
  const [url, options] = global.fetch.mock.calls[0].arguments;
  assert.equal(url, 'https://api.openai.com/v1/responses');
  const body = JSON.parse(options.body);
  assert.equal(body.model, 'gpt-6-luna');
  assert.deepEqual(body.reasoning, { effort: 'low' });
  assert.equal(body.store, false);
  assert.equal(body.input, '架空の質問');
  assert.equal(body.tool_choice, 'auto');
  assert.deepEqual(body.tools, [{ type: 'web_search', search_context_size: 'low', user_location: {
    type: 'approximate', region: '架空の地域', timezone: 'Asia/Tokyo'
  } }]);
  assert.match(body.instructions, /2026-01-02 00:04:05/);
  assert.match(body.instructions, /Asia\/Tokyo/);
  assert.match(body.instructions, /架空の地域/);
  assert.equal(body.previous_response_id, undefined);
  assert.equal(body.conversation, undefined);
  assert.equal(global.fetch.mock.callCount(), 1); // Slackへ自動投稿しない
  mock.timers.reset();
});

for (const kind of ['network', 'http', 'json', 'empty', 'malformed']) {
  test(`OpenAI失敗時は機密詳細を公開しない: ${kind}`, async () => {
    global.fetch.mock.mockImplementation(async () => {
      if (kind === 'network') throw new Error('fictional-private-detail');
      return { ok: kind !== 'http', json: async () => {
        if (kind === 'json') throw new Error('fictional-private-detail');
        return kind === 'malformed' ? { output: 'fictional-private-detail' } : { output: [] };
      } };
    });
    await sendRequest();
    assert.equal(response.statusCode, 502);
    assert.ok(!JSON.stringify(response.body).includes('fictional-private-detail'));
  });
}

test('Slack共有はMarkdownを維持し、安全な出典を重複排除して10件に制限する', async () => {
  request.path = '/api/share';
  request.body = { question: '架空 <質問>', answer: '**架空の回答** & 説明', citations: [
    { url: 'javascript:alert(1)' },
    ...Array.from({ length: 12 }, (_, index) => ({ url: `https://example.invalid/${index}`, title: '架空 <出典>' })),
    { url: 'https://example.invalid/0', title: '重複' }
  ] };
  global.fetch.mock.mockImplementation(async () => ({ ok: true }));
  await sendRequest();
  assert.equal(response.statusCode, 200);
  assert.equal(global.fetch.mock.callCount(), 1);
  const [url, options] = global.fetch.mock.calls[0].arguments;
  assert.equal(url, process.env.INCOMING_WEBHOOK_URL);
  const body = JSON.parse(options.body);
  assert.deepEqual(body.blocks.slice(0, 2), [
    { type: 'markdown', text: 'Q. 架空 <質問>' }, { type: 'markdown', text: '**架空の回答** & 説明' }
  ]);
  assert.equal(body.blocks[2].elements.length, 10);
  assert.match(body.text, /&lt;質問&gt;/);
  assert.match(body.text, /&amp;/);
  assert.ok(!body.text.includes('javascript:'));
  assert.equal(body.channel, '#fictional');
  assert.equal(body.username, '架空の表示名');
});

test('Slackの合計12,000文字超過は通信前に拒否する', async () => {
  request.path = '/api/share'; request.body.answer = 'a'.repeat(12000);
  await sendRequest();
  assert.equal(response.statusCode, 502);
  assert.match(response.body.error, /12,000/);
  assert.equal(global.fetch.mock.callCount(), 0);
});

for (const kind of ['network', 'http', 'settings', 'webhook']) {
  test(`Slack失敗・不正設定時は機密詳細を公開しない: ${kind}`, async () => {
    request.path = '/api/share'; request.body.answer = '架空の回答';
    if (kind === 'settings') process.env.SLACK_POST_SETTINGS = '{fictional-private-detail';
    if (kind === 'webhook') process.env.INCOMING_WEBHOOK_URL = 'http://example.invalid/unsafe';
    global.fetch.mock.mockImplementation(async () => {
      if (kind === 'network') throw new Error('fictional-private-detail');
      return { ok: false };
    });
    await sendRequest();
    assert.equal(response.statusCode, 502);
    assert.ok(!JSON.stringify(response.body).includes('fictional-private-detail'));
  });
}

for (const settings of ['null', '[]', '"fictional-private-detail"', '42', 'true',
  '{"unexpected":"fictional-private-detail"}', '{"username":42}']) {
  test(`Slack JSON Secretの不正な形式・許可外キー・非文字列を拒否する: ${settings}`, async () => {
    request.path = '/api/share'; request.body.answer = '架空の回答';
    process.env.SLACK_POST_SETTINGS = settings;
    await sendRequest();
    assert.equal(response.statusCode, 502);
    assert.equal(global.fetch.mock.callCount(), 0);
    assert.ok(!JSON.stringify(response.body).includes('fictional-private-detail'));
  });
}

test('空のSlack JSON設定はWebhook側の設定で共有できる', async () => {
  request.path = '/api/share'; request.body.answer = '架空の回答';
  process.env.SLACK_POST_SETTINGS = '{}';
  global.fetch.mock.mockImplementation(async () => ({ ok: true }));
  await sendRequest();
  assert.equal(response.statusCode, 200);
  const body = JSON.parse(global.fetch.mock.calls[0].arguments[1].body);
  assert.equal(body.channel, undefined);
  assert.equal(body.username, undefined);
  assert.equal(body.icon_url, undefined);
});

test('Slack JSON Secretの未設定も詳細を公開せず拒否する', async () => {
  request.path = '/api/share'; request.body.answer = '架空の回答';
  delete process.env.SLACK_POST_SETTINGS;
  await sendRequest();
  assert.equal(response.statusCode, 502);
  assert.equal(global.fetch.mock.callCount(), 0);
  assert.ok(!JSON.stringify(response.body).includes('SLACK_POST_SETTINGS'));
});

test('不明なパス・HTTPメソッド・形式・過大な要求を拒否する', async () => {
  request.path = '/api/unknown';
  await sendRequest(); assert.equal(response.statusCode, 404);
  request.path = '/api/ask'; request.method = 'GET';
  await sendRequest(); assert.equal(response.statusCode, 405);
  request.method = 'POST'; request.headers['Content-Type'] = 'text/plain';
  await sendRequest(); assert.equal(response.statusCode, 415);
  request.headers['Content-Type'] = 'application/json';
  request.body = { question: 'a'.repeat(128 * 1024 + 1) };
  await sendRequest(); assert.equal(response.statusCode, 413);
  assert.equal(global.fetch.mock.callCount(), 0);
});


for (const [path, method, statusCode] of [
  ['/api/session', 'HEAD', 405], ['/api/session', 'POST', 405],
  ['/api/ask', 'HEAD', 405], ['/api/ask', 'OPTIONS', 405],
  ['/api/share', 'GET', 405], ['/api/share', 'OPTIONS', 405],
  ['/API/session', 'GET', 404], ['/api/Session', 'GET', 404],
  ['/api/session/', 'GET', 404], ['/api/ask/', 'POST', 404]
]) {
  test(`Expressへ移行してもパスとメソッドの拒否を維持: ${method} ${path}`, async () => {
    request.path = path;
    request.method = method;
    await sendRequest();
    assert.equal(response.statusCode, statusCode);
    assert.equal(response.headers['cache-control'], 'no-store');
    assert.equal(getAuth().verifyIdToken.mock.callCount(), 0);
    assert.equal(global.fetch.mock.callCount(), 0);
  });
}

// アプリのfetchは外部通信の置換に使うため、検証要求はNode標準のHTTPで送る。
async function sendRequest() {
  response = await new Promise((resolve, reject) => {
    const body = JSON.stringify(request.body);
    const outgoing = http.request({
      hostname: '127.0.0.1', port: server.address().port,
      path: request.path, method: request.method,
      headers: { ...request.headers, 'Content-Length': Buffer.byteLength(body) }
    }, incoming => {
      let body = '';
      incoming.setEncoding('utf8');
      incoming.on('data', chunk => { body += chunk; });
      incoming.on('error', reject);
      incoming.on('end', () => resolve({
        statusCode: incoming.statusCode, headers: incoming.headers,
        body: body ? JSON.parse(body) : undefined
      }));
    });
    outgoing.on('error', reject);
    outgoing.end(body);
  });
}
