const express = require('express');
const { authenticate } = require('./authentication');
const { askQuestion } = require('./openai');
const { shareToSlack } = require('./slack');

const app = express();
const router = express.Router({ caseSensitive: true, strict: true });
app.enable('case sensitive routing');
app.enable('strict routing');

app.use((request, response, next) => {
  response.set('Cache-Control', 'no-store');
  if (request.rawBody?.length > 128 * 1024) {
    response.status(413).json({ error: '送信内容が大きすぎます。' });
    return;
  }
  next();
});

// GETのHEAD自動処理も無効にし、既存の許可メソッドを維持する。
router.route('/session')
  .head(rejectMethod)
  .get(authenticate, (request, response) => response.json({ isAuthorized: true }))
  .all(rejectMethod);
router.route('/ask')
  .post(authenticate, validateQuestion, async (request, response) => {
    response.json(await askQuestion(request.body.question));
  })
  .all(rejectMethod);
router.route('/share')
  .post(authenticate, validateQuestion, async (request, response) => {
    const { question, answer, citations } = request.body;
    if (typeof answer !== 'string' || !answer.trim() ||
        (citations !== undefined && !Array.isArray(citations))) {
      response.status(400).json({ error: '共有する質問と回答がありません。' });
      return;
    }
    response.json(await shareToSlack(question, answer, citations));
  })
  .all(rejectMethod);

app.use('/api', router);
app.use((request, response) => {
  response.status(404).json({ error: 'ページが見つかりません。' });
});
// Express 5は非同期処理の例外も、この共通エラーハンドラーへ渡す。
app.use((error, request, response, next) => {
  // 質問・回答・認証情報・外部APIのレスポンスをログへ記録しない。
  response.status(502).json({ error: error.cause?.isPublic ? error.message :
    '処理に失敗しました。時間をおいて再度お試しください。' });
});

function rejectMethod(request, response) {
  response.status(405).json({ error: 'この操作は利用できません。' });
}

function validateQuestion(request, response, next) {
  if (!request.is('application/json')) {
    response.status(415).json({ error: 'JSONで送信してください。' });
    return;
  }
  const { question } = request.body || {};
  if (typeof question !== 'string' || !question.trim() || question.trim().length > 4000) {
    response.status(400).json({ error: '質問を入力してください（4,000文字以内）。' });
    return;
  }
  next();
}

module.exports = { app };
