const { initializeApp } = require('firebase-admin/app');
const { getAuth } = require('firebase-admin/auth');
const { onRequest } = require('firebase-functions/v2/https');
const { defineSecret, defineJsonSecret } = require('firebase-functions/params');

initializeApp();
const allowedGoogleAccounts = defineSecret('HOME_GOOGLE_ACCOUNT');
const openaiApiKey = defineSecret('OPENAI_API_KEY');
const incomingWebhookUrl = defineSecret('INCOMING_WEBHOOK_URL');
const homeLocation = defineSecret('HOME_LOCATION');
const slackPostSettings = defineJsonSecret('SLACK_POST_SETTINGS');

// Hosting経由と関数URLへの直接アクセスの両方で、同じ認証・allowlist検証を行う。
exports.cloudGlance = onRequest({
  region: 'asia-east1',
  timeoutSeconds: 60,
  secrets: [allowedGoogleAccounts, openaiApiKey, incomingWebhookUrl, homeLocation, slackPostSettings]
}, async (request, response) => {
  response.set('Cache-Control', 'no-store');
  if (request.rawBody?.length > 128 * 1024) {
    response.status(413).json({ error: '送信内容が大きすぎます。' });
    return;
  }
  if (!['/api/session', '/api/ask', '/api/share'].includes(request.path)) {
    response.status(404).json({ error: 'ページが見つかりません。' });
    return;
  }
  if (request.method !== (request.path === '/api/session' ? 'GET' : 'POST')) {
    response.status(405).json({ error: 'この操作は利用できません。' });
    return;
  }
  const authorization = request.get('Authorization') || '';
  if (!authorization.startsWith('Bearer ')) {
    response.status(401).json({ error: 'Googleでログインしてください。' });
    return;
  }
  let identity;
  try {
    identity = await getAuth().verifyIdToken(authorization.slice(7), true);
  } catch {
    response.status(401).json({ error: 'Googleでログインしてください。' });
    return;
  }
  const email = typeof identity.email === 'string' ? identity.email.trim().toLowerCase() : '';
  if (!email || !identity.email_verified || identity.firebase?.sign_in_provider !== 'google.com' ||
      !allowedGoogleAccounts.value().split(',').some(account => account.trim().toLowerCase() === email)) {
    response.status(403).json({ error: 'このアカウントでは利用できません。' });
    return;
  }
  if (request.path === '/api/session') {
    response.json({ isAuthorized: true });
    return;
  }
  if (!request.is('application/json')) {
    response.status(415).json({ error: 'JSONで送信してください。' });
    return;
  }
  const { question, answer, citations } = request.body || {};
  if (typeof question !== 'string' || !question.trim() || question.trim().length > 4000) {
    response.status(400).json({ error: '質問を入力してください（4,000文字以内）。' });
    return;
  }
  if (request.path === '/api/share' && (typeof answer !== 'string' || !answer.trim() ||
      (citations !== undefined && !Array.isArray(citations)))) {
    response.status(400).json({ error: '共有する質問と回答がありません。' });
    return;
  }
  try {
    response.json(request.path === '/api/ask' ?
      await askQuestion(question) : await shareToSlack(question, answer, citations));
  } catch (error) {
    // 質問・回答・認証情報・外部APIのレスポンスをログへ記録しない。
    response.status(502).json({ error: error.cause?.isPublic ? error.message :
      '処理に失敗しました。時間をおいて再度お試しください。' });
  }
});

async function askQuestion(question) {
  const normalizedQuestion = String(question || '').trim();
  if (!normalizedQuestion || normalizedQuestion.length > 4000) {
    throw new Error('質問を入力してください（4,000文字以内）。', { cause: { isPublic: true } });
  }

  const apiKey = openaiApiKey.value();
  if (!apiKey) throw new Error('cloud-glanceの設定が未完了です。管理者に連絡してください。', { cause: { isPublic: true } });

  const timeZone = 'Asia/Tokyo';
  const now = new Intl.DateTimeFormat('sv-SE', {
    timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23'
  }).format(new Date());
  const location = homeLocation.value().trim();
  const prompt = [
    '質問に日本語で簡潔に答えてください。結論を先に述べ、不明な前提を作らず、会話を続けるためだけの質問はしないでください。',
    '今日・明日などの相対日時は、次の現在日時とタイムゾーンを基準にしてください。',
    '現在日時: ' + now,
    'タイムゾーン: ' + timeZone,
    '検索の地域コンテキスト: ' + (location || '指定なし'),
    '天気・営業時間・最新情報など、現在の情報が必要な場合はWeb検索を使ってください。一般知識だけで答えられる質問では不要な検索をしないでください。'
  ].join('\n');

  const requestBody = {
    model: 'gpt-6-luna',
    instructions: prompt,
    input: normalizedQuestion,
    reasoning: { effort: 'low' },
    store: false,
    tools: [{
      type: 'web_search',
      search_context_size: 'low',
      user_location: {
        type: 'approximate',
        region: location,
        timezone: timeZone
      }
    }],
    tool_choice: 'auto'
  };

  let response;
  try {
    response = await fetch('https://api.openai.com/v1/responses', {
      method: 'post',
      headers: { Authorization: 'Bearer ' + apiKey, 'Content-Type': 'application/json' },
      body: JSON.stringify(requestBody),
      signal: AbortSignal.timeout(50000)
    });
  } catch (error) {
    throw new Error('回答を取得できませんでした。時間をおいて再度お試しください。', { cause: { isPublic: true } });
  }
  if (!response.ok) {
    throw new Error('回答を取得できませんでした。時間をおいて再度お試しください。', { cause: { isPublic: true } });
  }

  let responseData;
  try {
    responseData = await response.json();
  } catch (error) {
    throw new Error('回答を読み取れませんでした。時間をおいて再度お試しください。', { cause: { isPublic: true } });
  }
  const extracted = extractResponse(responseData);
  if (!extracted.answer.trim()) throw new Error('回答を取得できませんでした。時間をおいて再度お試しください。', { cause: { isPublic: true } });
  return extracted;
}

async function shareToSlack(question, answer, citations) {
  const normalizedQuestion = String(question || '').trim();
  const normalizedAnswer = String(answer || '').trim();
  if (!normalizedQuestion || !normalizedAnswer) throw new Error('共有する質問と回答がありません。', { cause: { isPublic: true } });

  const webhookUrl = incomingWebhookUrl.value();
  if (!webhookUrl) throw new Error('Slack共有の設定が未完了です。管理者に連絡してください。', { cause: { isPublic: true } });
  if (!/^https:\/\//i.test(webhookUrl)) throw new Error('Slack共有の設定を確認してください。', { cause: { isPublic: true } });

  const seenCitationUrls = new Set();
  const safeCitations = (Array.isArray(citations) ? citations : [])
    .filter(function (citation) {
      if (!citation || !/^https?:\/\//i.test(String(citation.url || '')) || seenCitationUrls.has(citation.url)) return false;
      seenCitationUrls.add(citation.url);
      return true;
    })
    .slice(0, 10)
    .map(function (citation) {
      return '<' + escapeSlackText(String(citation.url).slice(0, 2000).replace(/[<>|\s]/g, encodeURIComponent)) + '|' + escapeSlackText(String(citation.title || citation.url).slice(0, 200)) + '>';
    });
  const message = 'Q. ' + escapeSlackText(normalizedQuestion) + '\n\nA. ' + escapeSlackText(normalizedAnswer) +
    (safeCitations.length ? '\n\n出典:\n' + safeCitations.join('\n') : '');

  const blocks = [
    { type: 'markdown', text: 'Q. ' + normalizedQuestion },
    { type: 'markdown', text: normalizedAnswer }
  ];
  if (blocks[0].text.length + blocks[1].text.length > 12000) {
    throw new Error('SlackのMarkdown共有は質問と回答の合計12,000文字までです。', { cause: { isPublic: true } });
  }
  if (safeCitations.length) {
    blocks.push({
      type: 'context',
      elements: safeCitations.map(function (citation, index) {
        return { type: 'mrkdwn', text: (index === 0 ? '出典: ' : '') + citation };
      })
    });
  }

  const postSettings = slackPostSettings.value();
  if (!postSettings || typeof postSettings !== 'object' || Array.isArray(postSettings) ||
      Object.keys(postSettings).some(key => !['channel', 'username', 'icon_url'].includes(key) || typeof postSettings[key] !== 'string')) {
    throw new Error('Slack共有の設定を確認してください。', { cause: { isPublic: true } });
  }
  let response;
  try {
    response = await fetch(webhookUrl, {
      method: 'post',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        text: message,
        ...postSettings,
        blocks: blocks
      }),
      signal: AbortSignal.timeout(50000)
    });
  } catch (error) {
    throw new Error('Slackへの共有に失敗しました。時間をおいて再度お試しください。', { cause: { isPublic: true } });
  }
  if (!response.ok) {
    throw new Error('Slackへの共有に失敗しました。時間をおいて再度お試しください。', { cause: { isPublic: true } });
  }
  return { isShared: true };
}

function extractResponse(responseData) {
  let answer = '';
  const citations = [];
  (responseData.output || []).forEach(function (item) {
    if (item.type !== 'message' || item.role !== 'assistant') return;
    (item.content || []).forEach(function (content) {
      if (content.type !== 'output_text') return;
      const offset = Array.from(answer).length;
      (content.annotations || []).forEach(function (annotation) {
        if (annotation.type === 'url_citation' && /^https?:\/\//i.test(String(annotation.url || ''))) {
          citations.push({
            title: annotation.title || annotation.url,
            url: annotation.url,
            startIndex: offset + annotation.start_index,
            endIndex: offset + annotation.end_index
          });
        }
      });
      answer += content.text || '';
    });
  });
  return { answer: answer, citations: citations };
}

function escapeSlackText(value) {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
