import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { JSDOM } from 'jsdom';
import { marked } from 'marked';
import createDOMPurify from 'dompurify';

let window;
let calls;
let auth;
let authListener;
let fetchResponse;
let pendingOperation;
const source = (await readFile(new URL('../frontend/app.js', import.meta.url), 'utf8')).replace(/^import .*;\n/gm, '');
const html = await readFile(new URL('../frontend/index.html', import.meta.url), 'utf8');
const styles = await readFile(new URL('../frontend/styles.css', import.meta.url), 'utf8');

beforeEach(async () => {
  window = new JSDOM(html, { url: 'https://example.invalid', runScripts: 'outside-only' }).window;
  const stylesheet = window.document.createElement('style');
  stylesheet.textContent = styles;
  window.document.head.appendChild(stylesheet);
  calls = [];
  auth = { currentUser: { getIdToken: async () => 'fictional-token' } };
  fetchResponse = { ok: true, answer: '**架空の回答**', citations: [] };
  pendingOperation = undefined;
  window.marked = marked;
  window.DOMPurify = createDOMPurify(window);
  window.initializeApp = config => config;
  window.getAuth = () => auth;
  window.GoogleAuthProvider = class { setCustomParameters() {} };
  window.signInWithRedirect = async () => {};
  window.getRedirectResult = async () => {};
  window.onAuthStateChanged = (_, listener) => { authListener = listener; };
  window.connectAuthEmulator = () => { throw new Error('本番ホストではエミュレーターを使用しない'); };
  window.fetch = async (path, options) => {
    calls.push({ path, options });
    if (path === '/__/firebase/init.json') return { ok: true, json: async () => ({ projectId: 'fictional-project' }) };
    if (path === '/api/session') return { ok: true, json: async () => ({ isAuthorized: true }) };
    if (pendingOperation) await pendingOperation;
    return { ok: fetchResponse.ok, json: async () => fetchResponse };
  };
  await window.eval(`(async () => { ${source}\n })()`);
  await authListener(auth.currentUser);
  calls.length = 0;
});
afterEach(() => window.close());

test('非表示の認証ボタンにはページ側のCSSでもdisplay:noneを適用する', () => {
  // jsdom 30.1.1はブラウザー既定とページ側のCSSの優先順位を区別しないため、
  // 計算済みスタイルだけでは今回のhidden上書きを検出できない。
  const hidingRules = Array.from(window.document.styleSheets)
    .flatMap(stylesheet => Array.from(stylesheet.cssRules))
    .filter(rule => rule.style?.display === 'none');
  for (const id of ['login', 'clear']) {
    const button = window.document.getElementById(id);
    button.hidden = true;
    assert.ok(hidingRules.some(rule => button.matches(rule.selectorText)), `${id}の非表示をページ側のCSSで保証する`);
    assert.equal(window.getComputedStyle(button).display, 'none');
  }
});

test('初期HTMLでもCSS適用後にクリアを表示しない', () => {
  const initialWindow = new JSDOM(html).window;
  initialWindow.document.head.appendChild(window.document.querySelector('style').cloneNode(true));
  try {
    assert.equal(initialWindow.getComputedStyle(initialWindow.document.getElementById('clear')).display, 'none');
    assert.equal(initialWindow.getComputedStyle(initialWindow.document.getElementById('login')).display, 'inline-flex');
    assert.equal(initialWindow.document.getElementById('login').disabled, true);
  } finally {
    initialWindow.close();
  }
});

test('未ログインではCSS適用後もGoogleログインだけを表示する', async () => {
  auth.currentUser = null;
  await authListener(null);
  assert.equal(window.document.getElementById('login').hidden, false);
  assert.equal(window.document.getElementById('clear').hidden, true);
  assert.equal(window.getComputedStyle(window.document.getElementById('login')).display, 'inline-flex');
  assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'none');
});

test('利用可能状態ではCSS適用後もクリアだけを表示する', () => {
  assert.equal(window.document.getElementById('login').hidden, true);
  assert.equal(window.document.getElementById('clear').hidden, false);
  assert.equal(window.getComputedStyle(window.document.getElementById('login')).display, 'none');
  assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'inline-flex');
});

test('許可確認中は両操作を表示せず、完了後にクリアだけを表示する', async () => {
  let complete;
  window.fetch = () => new Promise(resolve => { complete = resolve; });
  const authorization = authListener(auth.currentUser);
  assert.equal(window.getComputedStyle(window.document.getElementById('login')).display, 'none');
  assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'none');
  assert.equal(window.document.getElementById('askForm').hidden, true);
  assert.equal(window.document.getElementById('ask').disabled, true);
  await new Promise(resolve => setImmediate(resolve));
  complete({ ok: true, json: async () => ({ isAuthorized: true }) });
  await authorization;
  assert.equal(window.getComputedStyle(window.document.getElementById('login')).display, 'none');
  assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'inline-flex');
  assert.equal(window.document.getElementById('clear').disabled, false);
  assert.equal(window.document.getElementById('askForm').hidden, false);
  assert.equal(window.document.getElementById('ask').disabled, false);
});

for (const isSuccessful of [true, false]) {
  test(`許可確認中に未ログインへ変わった後の${isSuccessful ? '成功' : '失敗'}応答で操作表示を戻さない`, async () => {
    let complete;
    window.fetch = () => new Promise(resolve => { complete = resolve; });
    const authorization = authListener(auth.currentUser);
    await new Promise(resolve => setImmediate(resolve));
    auth.currentUser = null;
    await authListener(null);
    assert.equal(window.getComputedStyle(window.document.getElementById('login')).display, 'inline-flex');
    assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'none');
    complete({ ok: isSuccessful, json: async () => ({ error: '架空の許可エラー' }) });
    await authorization;
    assert.equal(window.getComputedStyle(window.document.getElementById('login')).display, 'inline-flex');
    assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'none');
    assert.equal(window.document.getElementById('login').disabled, false);
    assert.equal(window.document.getElementById('askForm').hidden, true);
    assert.equal(window.document.getElementById('error').textContent, '');
  });
}

test('未ログインからGoogleログインを開始でき、失敗時に再試行できる', async () => {
  auth.currentUser = null;
  await authListener(null);
  let loginAttempts = 0;
  window.signInWithRedirect = async currentAuth => {
    assert.equal(currentAuth, auth);
    loginAttempts += 1;
    throw new Error('架空のログインエラー');
  };
  const loginButton = window.document.getElementById('login');
  loginButton.click();
  assert.equal(loginButton.disabled, true);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(loginButton.disabled, false);
  assert.equal(window.getComputedStyle(loginButton).display, 'inline-flex');
  assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'none');
  assert.equal(window.document.getElementById('error').textContent, 'Googleログインに失敗しました。再度お試しください。');
  loginButton.click();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(loginAttempts, 2);
});

test('回答取得時点の質問を共有し、編集した質問とは組み替えない', async () => {
  window.document.getElementById('question').value = '回答取得時の架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls.length, 1);
  assert.equal(calls[0].path, '/api/ask');
  assert.equal(calls[0].options.headers.Authorization, 'Bearer fictional-token');
  assert.equal(window.document.querySelector('#answer strong').textContent, '架空の回答');
  window.document.getElementById('question').value = '編集後の架空の質問';
  window.document.getElementById('share').click();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls[1].path, '/api/share');
  const payload = JSON.parse(calls[1].options.body);
  assert.equal(payload.question, '回答取得時の架空の質問');
  assert.equal(payload.answer, '**架空の回答**');
});

test('本文内と回答下の出典リンクを表示し、Unicodeの出典位置を維持する', async () => {
  fetchResponse = { ok: true, answer: '😀説明 **出典**', citations: [
    { url: 'https://example.invalid/source', title: '架空の出典', startIndex: 6, endIndex: 8 }
  ] };
  window.document.getElementById('question').value = '架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(window.document.querySelector('#answer strong a').textContent, '[1]');
  assert.match(window.document.getElementById('answer').textContent, /^😀説明/);
  for (const selector of ['#answer a', '#citations a']) {
    const link = window.document.querySelector(selector);
    assert.equal(link.href, 'https://example.invalid/source');
    assert.equal(link.target, '_blank');
    assert.equal(link.rel, 'noopener noreferrer');
  }
});

test('生成HTMLのスクリプト・イベント・危険なリンクを除去する', async () => {
  fetchResponse.answer = '<script>alert(1)</script>\n\n<img src=x onerror="alert(1)">\n\n[危険](javascript:alert(1)) **安全**';
  window.document.getElementById('question').value = '架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(window.document.querySelectorAll('#answer script, #answer img, #answer [onerror]').length, 0);
  assert.equal(window.document.querySelectorAll('#answer a[href]').length, 0);
  assert.equal(window.document.querySelector('#answer strong').textContent, '安全');
});

test('クリアは質問・回答・出典・通知・共有状態を消す', async () => {
  window.document.getElementById('question').value = '架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  window.document.getElementById('share').click();
  await new Promise(resolve => setImmediate(resolve));
  window.document.getElementById('clear').click();
  for (const id of ['answer', 'citations', 'status', 'shareStatus', 'error']) {
    assert.equal(window.document.getElementById(id).textContent, '');
  }
  assert.equal(window.document.getElementById('question').value, '');
  assert.equal(window.document.getElementById('answerPanel').hidden, true);
  assert.equal(window.document.getElementById('share').disabled, true);
});

test('次の質問が失敗した場合は前の回答と質問の共有を維持する', async () => {
  window.document.getElementById('question').value = '最初の架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  fetchResponse = { ok: false, error: '架空の取得エラー' };
  window.document.getElementById('question').value = '失敗する架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(window.document.getElementById('answerPanel').hidden, false);
  assert.equal(window.document.getElementById('error').textContent, '架空の取得エラー');
  fetchResponse = { ok: true };
  window.document.getElementById('share').click();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(JSON.parse(calls[2].options.body).question, '最初の架空の質問');
});

test('回答取得中・共有中は二重送信とクリアを防ぐ', async () => {
  let complete;
  pendingOperation = new Promise(resolve => { complete = resolve; });
  window.document.getElementById('question').value = '架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls.length, 1);
  assert.equal(window.document.getElementById('clear').disabled, true);
  assert.equal(window.document.getElementById('share').disabled, true);
  complete();
  await new Promise(resolve => setImmediate(resolve));
  pendingOperation = new Promise(resolve => { complete = resolve; });
  window.document.getElementById('share').click();
  window.document.getElementById('share').click();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls.length, 2);
  assert.equal(window.document.getElementById('ask').disabled, true);
  assert.equal(window.document.getElementById('clear').disabled, true);
  complete();
  await new Promise(resolve => setImmediate(resolve));
});

test('共有失敗後も回答を維持し、再試行とクリアができる', async () => {
  window.document.getElementById('question').value = '架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  fetchResponse = { ok: false, error: '架空の共有エラー' };
  window.document.getElementById('share').click();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(window.document.getElementById('answerPanel').hidden, false);
  assert.equal(window.document.getElementById('share').disabled, false);
  assert.equal(window.document.getElementById('clear').disabled, false);
  assert.equal(window.document.getElementById('error').textContent, '架空の共有エラー');
});

test('認証状態が失われた後に返る回答を表示しない', async () => {
  let complete;
  pendingOperation = new Promise(resolve => { complete = resolve; });
  window.document.getElementById('question').value = '架空の質問';
  window.document.getElementById('askForm').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await new Promise(resolve => setImmediate(resolve));
  auth.currentUser = null;
  await authListener(null);
  complete();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(window.document.getElementById('answerPanel').hidden, true);
  assert.equal(window.document.getElementById('askForm').hidden, true);
  assert.equal(window.document.getElementById('answer').textContent, '');
  assert.equal(window.getComputedStyle(window.document.getElementById('login')).display, 'inline-flex');
  assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'none');
});

test('許可確認で拒否された利用者には質問フォームを表示しない', async () => {
  window.fetch = async () => ({ ok: false, json: async () => ({ error: '架空の許可エラー' }) });
  await authListener(auth.currentUser);
  assert.equal(window.document.getElementById('askForm').hidden, true);
  assert.equal(window.document.getElementById('share').disabled, true);
  assert.equal(window.document.getElementById('error').textContent, '架空の許可エラー');
  assert.equal(window.getComputedStyle(window.document.getElementById('login')).display, 'inline-flex');
  assert.equal(window.getComputedStyle(window.document.getElementById('clear')).display, 'none');
  assert.equal(window.document.getElementById('login').disabled, false);
  let loginAttempts = 0;
  window.signInWithRedirect = async () => { loginAttempts += 1; };
  window.document.getElementById('login').click();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(loginAttempts, 1);
});
