const { incomingWebhookUrl, slackPostSettings } = require('./settings');

async function shareToSlack(question, answer, citations) {
  const normalizedQuestion = question.trim();
  const normalizedAnswer = answer.trim();

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

function escapeSlackText(value) {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

module.exports = { shareToSlack };
