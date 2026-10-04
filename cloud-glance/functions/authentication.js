const { getAuth } = require('firebase-admin/auth');
const { allowedGoogleAccounts } = require('./settings');

async function authenticate(request, response, next) {
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
  next();
}

module.exports = { authenticate };
