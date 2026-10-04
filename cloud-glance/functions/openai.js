const { openaiApiKey, homeLocation } = require('./settings');

async function askQuestion(question) {
  const normalizedQuestion = question.trim();

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

module.exports = { askQuestion };
