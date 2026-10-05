import { initializeApp } from 'firebase/app';
import { getAuth, GoogleAuthProvider, signInWithRedirect, getRedirectResult, onAuthStateChanged, connectAuthEmulator } from 'firebase/auth';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const askForm = document.getElementById('askForm');
const answerPanel = document.getElementById('answerPanel');
const answerElement = document.getElementById('answer');
const citationsList = document.getElementById('citations');
const statusMessage = document.getElementById('status');
const shareStatusMessage = document.getElementById('shareStatus');
const errorMessage = document.getElementById('error');
const loginButton = document.getElementById('login');
const questionInput = document.getElementById('question');
const askButton = document.getElementById('ask');
const shareButton = document.getElementById('share');
const clearButton = document.getElementById('clear');
let currentQuestion = '';
let currentAnswer = '';
let currentCitations = [];
let isAuthorized = false;
let auth;

clearButton.addEventListener('click', function () {
  if (askButton.disabled) return;
  questionInput.value = '';
  currentQuestion = '';
  currentAnswer = '';
  currentCitations = [];
  answerElement.replaceChildren();
  citationsList.replaceChildren();
  answerPanel.hidden = true;
  statusMessage.textContent = '';
  shareStatusMessage.textContent = '';
  errorMessage.textContent = '';
  shareButton.disabled = true;
  questionInput.focus();
});

askForm.addEventListener('submit', async function (event) {
  event.preventDefault();
  const question = questionInput.value.trim();
  if (!question || askButton.disabled || !isAuthorized) return;
  const user = auth.currentUser;
  askButton.disabled = true;
  clearButton.disabled = true;
  shareButton.disabled = true;
  errorMessage.textContent = '';
  shareStatusMessage.textContent = '';
  statusMessage.textContent = '回答を取得しています…';
  answerPanel.hidden = true;
  try {
    const response = await fetch('/api/ask', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + await user.getIdToken() },
      body: JSON.stringify({ question })
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || '回答を取得できませんでした。');
    if (!isAuthorized || auth.currentUser !== user) return;
    currentQuestion = question;
    currentAnswer = result.answer;
    currentCitations = result.citations || [];
    renderCitations(currentCitations);
    answerPanel.hidden = false;
  } catch (error) {
    if (!isAuthorized || auth.currentUser !== user) return;
    errorMessage.textContent = error.message || '回答を取得できませんでした。';
    answerPanel.hidden = !currentAnswer;
  } finally {
    if (isAuthorized && auth.currentUser === user) {
      statusMessage.textContent = '';
      askButton.disabled = false;
      clearButton.disabled = false;
      shareButton.disabled = !currentAnswer;
    }
  }
});

shareButton.addEventListener('click', async function () {
  if (shareButton.disabled || !currentAnswer || !isAuthorized) return;
  const user = auth.currentUser;
  shareButton.disabled = true;
  askButton.disabled = true;
  clearButton.disabled = true;
  shareStatusMessage.textContent = 'Slackに共有しています…';
  errorMessage.textContent = '';
  try {
    const response = await fetch('/api/share', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + await user.getIdToken() },
      body: JSON.stringify({ question: currentQuestion, answer: currentAnswer, citations: currentCitations })
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Slackへの共有に失敗しました。');
    if (!isAuthorized || auth.currentUser !== user) return;
    shareStatusMessage.textContent = 'Slackに共有しました。';
  } catch (error) {
    if (!isAuthorized || auth.currentUser !== user) return;
    shareStatusMessage.textContent = '';
    errorMessage.textContent = error.message || 'Slackへの共有に失敗しました。';
  } finally {
    if (isAuthorized && auth.currentUser === user) {
      shareButton.disabled = false;
      askButton.disabled = false;
      clearButton.disabled = false;
    }
  }
});

function renderCitations(citations) {
  const answerCharacters = Array.from(currentAnswer);
  let position = 0;
  let answerMarkdown = '';
  let citationMarker = 'CloudGlanceCitation';
  while (currentAnswer.includes(citationMarker)) citationMarker += 'X';
  const inlineLinks = [];
  citationsList.replaceChildren();
  answerElement.replaceChildren();
  citations.slice().sort(function (first, second) {
    return first.startIndex - second.startIndex || first.endIndex - second.endIndex;
  }).forEach(function (citation, index) {
    const item = document.createElement('li');
    const link = document.createElement('a');
    link.href = citation.url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = citation.title || citation.url;
    item.appendChild(link);
    citationsList.appendChild(item);
    if (Number.isInteger(citation.startIndex) && Number.isInteger(citation.endIndex) &&
        citation.startIndex >= 0 && citation.endIndex >= citation.startIndex &&
        citation.endIndex <= answerCharacters.length) {
      if (citation.startIndex >= position) {
        const precedingText = answerCharacters.slice(position, citation.startIndex).join('');
        answerElement.appendChild(document.createTextNode(precedingText));
        answerMarkdown += precedingText;
        position = citation.endIndex;
      }
      const inlineLink = link.cloneNode(true);
      inlineLink.textContent = '[' + (index + 1) + ']';
      inlineLink.setAttribute('aria-label', '出典: ' + (citation.title || citation.url));
      inlineLink.title = citation.title || citation.url;
      answerElement.appendChild(inlineLink);
      inlineLinks[index] = inlineLink;
      answerMarkdown += citationMarker + index + 'End';
    }
  });
  const remainingText = answerCharacters.slice(position).join('');
  answerElement.appendChild(document.createTextNode(remainingText));
  answerMarkdown += remainingText;
  if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
    answerElement.replaceChildren(DOMPurify.sanitize(marked.parse(answerMarkdown), {
      RETURN_DOM_FRAGMENT: true,
      ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'del', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a'],
      ALLOWED_ATTR: ['href', 'title', 'start', 'aria-label']
    }));
    // Markdown解析後の本文へ出典を戻し、強調やコード内でもリンクを維持する。
    const walker = document.createTreeWalker(answerElement, NodeFilter.SHOW_TEXT);
    const citationTextNodes = [];
    while (walker.nextNode()) {
      if (walker.currentNode.textContent.includes(citationMarker)) citationTextNodes.push(walker.currentNode);
    }
    citationTextNodes.forEach(function (node) {
      const fragment = document.createDocumentFragment();
      node.textContent.split(new RegExp(citationMarker + '(\\d+)End', 'g')).forEach(function (part, index) {
        fragment.appendChild(index % 2 === 1 && inlineLinks[Number(part)] ?
          inlineLinks[Number(part)].cloneNode(true) : document.createTextNode(part));
      });
      node.replaceWith(fragment);
    });
    answerElement.querySelectorAll('a').forEach(function (link) {
      if (!/^https?:\/\//i.test(link.getAttribute('href') || '')) {
        link.removeAttribute('href');
      } else {
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
      }
    });
    answerElement.style.whiteSpace = '';
  } else {
    answerElement.style.whiteSpace = 'pre-wrap';
    errorMessage.textContent = 'Markdown表示を読み込めませんでした。再読み込みしてください。';
  }
}


try {
  const response = await fetch('/__/firebase/init.json');
  if (!response.ok) throw new Error('ログインの設定を読み込めませんでした。');
  const config = await response.json();
  // web.appと同一の認証ドメインを使用し、第三者ストレージ制限を避ける。
  config.authDomain = location.hostname;
  auth = getAuth(initializeApp(config));
  if (['localhost', '127.0.0.1'].includes(location.hostname) && config.projectId === 'demo-cloud-glance') {
    connectAuthEmulator(auth, 'http://127.0.0.1:19099', { disableWarnings: true });
  }
  const provider = new GoogleAuthProvider();
  provider.setCustomParameters({ prompt: 'select_account' });
  loginButton.addEventListener('click', async function () {
    loginButton.disabled = true;
    try {
      await signInWithRedirect(auth, provider);
    } catch {
      errorMessage.textContent = 'Googleログインに失敗しました。再度お試しください。';
      loginButton.disabled = false;
    }
  });
  await getRedirectResult(auth);
  onAuthStateChanged(auth, async function (user) {
    isAuthorized = false;
    askButton.disabled = true;
    clearButton.disabled = true;
    shareButton.disabled = true;
    askForm.hidden = true;
    answerPanel.hidden = true;
    currentQuestion = '';
    currentAnswer = '';
    currentCitations = [];
    questionInput.value = '';
    answerElement.replaceChildren();
    citationsList.replaceChildren();
    statusMessage.textContent = '';
    shareStatusMessage.textContent = '';
    errorMessage.textContent = '';
    loginButton.hidden = Boolean(user);
    clearButton.hidden = true;
    loginButton.disabled = false;
    if (!user) return;
    loginButton.disabled = true;
    try {
      const response = await fetch('/api/session', {
        headers: { Authorization: 'Bearer ' + await user.getIdToken() }
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'ログインを確認できませんでした。');
      if (auth.currentUser !== user) return;
      isAuthorized = true;
      loginButton.hidden = true;
      clearButton.hidden = false;
      askForm.hidden = false;
      askButton.disabled = false;
      clearButton.disabled = false;
      questionInput.focus();
    } catch (error) {
      if (auth.currentUser !== user) return;
      errorMessage.textContent = error.message || 'ログインを確認できませんでした。';
      loginButton.hidden = false;
    } finally {
      if (auth.currentUser === user) loginButton.disabled = false;
    }
  });
} catch {
  errorMessage.textContent = 'ログインを開始できませんでした。設定を確認して再読み込みしてください。';
}
