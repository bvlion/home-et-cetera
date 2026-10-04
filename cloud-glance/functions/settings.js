const { defineSecret, defineJsonSecret } = require('firebase-functions/params');

const allowedGoogleAccounts = defineSecret('HOME_GOOGLE_ACCOUNT');
const openaiApiKey = defineSecret('OPENAI_API_KEY');
const incomingWebhookUrl = defineSecret('INCOMING_WEBHOOK_URL');
const homeLocation = defineSecret('HOME_LOCATION');
const slackPostSettings = defineJsonSecret('SLACK_POST_SETTINGS');

module.exports = { allowedGoogleAccounts, openaiApiKey, incomingWebhookUrl, homeLocation, slackPostSettings };
