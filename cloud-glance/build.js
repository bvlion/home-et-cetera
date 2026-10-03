import { build } from 'esbuild';
import { cp, mkdir, rm } from 'node:fs/promises';

await rm('dist', { recursive: true, force: true });
await mkdir('dist');
await cp('frontend/index.html', 'dist/index.html');
await cp('frontend/styles.css', 'dist/styles.css');
await build({
  entryPoints: ['frontend/app.js'],
  outfile: 'dist/app.js',
  bundle: true,
  format: 'esm',
  target: 'es2022',
  minify: true,
  legalComments: 'eof'
});
