import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';

const { parseFunctionSelector } = createRequire(import.meta.url)('firebase-tools/lib/deploy/functions/functionsDeployHelper');

test('Hostingは外部CSS・JavaScriptを配信し、同じ1関数へAPIを転送する', async () => {
  const html = await readFile('frontend/index.html', 'utf8');
  assert.doesNotMatch(html, /<style\b|\sstyle=|\son\w+=/i);
  assert.match(html, /<link rel="stylesheet" href="\/styles.css">/);
  assert.equal((html.match(/<script\b/g) || []).length, 1);
  assert.match(html, /<script type="module" src="\/app.js"><\/script>/);
  const config = JSON.parse(await readFile('firebase.json', 'utf8'));
  assert.equal(config.functions.length, 1);
  assert.equal(config.functions[0].codebase, 'cloud-glance');
  assert.deepEqual(config.hosting.rewrites, [{ source: '/api/**', function: { functionId: 'cloudGlance', region: 'asia-east1' } }]);
  assert.equal(JSON.parse(await readFile('.firebaserc', 'utf8')).projects.default, 'b-glance');
});

test('デプロイは専用タグでHostingと1つの関数だけを対象にする', async () => {
  const workflow = await readFile('../.github/workflows/cloud-glance-deploy.yaml', 'utf8');
  assert.match(workflow, /tags: \['cloud-glance-v\*'\]/);
  assert.match(workflow, /--project b-glance --only hosting,functions:cloud-glance:cloudGlance --non-interactive/);
  assert.match(workflow, /group: cloud-glance-production-deploy/);
  const config = JSON.parse(await readFile('firebase.json', 'utf8'));
  assert.deepEqual(parseFunctionSelector('cloud-glance:cloudGlance', config.functions), [
    { codebase: 'cloud-glance', idChunks: ['cloudGlance'] }
  ]);
});
