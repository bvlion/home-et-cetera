import { writeFile, unlink } from 'node:fs/promises';
import { spawn } from 'node:child_process';

// 検証専用の架空値だけを使い、既存のローカル設定を上書きしない。
await writeFile('functions/.secret.local', [
  'HOME_GOOGLE_ACCOUNT=allowed@example.invalid',
  'OPENAI_API_KEY=fictional-test-key',
  'INCOMING_WEBHOOK_URL=https://example.invalid/fictional-webhook',
  'HOME_LOCATION=架空の地域',
  'SLACK_POST_SETTINGS={}'
].join('\n') + '\n', { flag: 'wx', mode: 0o600 });
try {
  const child = spawn(process.execPath, [
    'node_modules/firebase-tools/lib/bin/firebase.js', 'emulators:exec',
    '--project', 'demo-cloud-glance', '--only', 'hosting,functions,auth',
    'node --test tests/emulator.test.js'
  ], { stdio: 'inherit' });
  process.exitCode = await new Promise((resolve, reject) => {
    child.on('error', reject);
    child.on('exit', code => resolve(code ?? 1));
  });
} finally {
  await unlink('functions/.secret.local');
}
