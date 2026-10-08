// Secret scan: uses gitleaks when installed (CI, Markus' Mac); otherwise a built-in scan of all
// tracked files for common key formats and filled-in .env values.
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const gitleaks = spawnSync('gitleaks', ['version'], { stdio: 'ignore' });
if (gitleaks.status === 0) {
  const run = spawnSync('gitleaks', ['detect', '--no-banner', '--redact'], { stdio: 'inherit' });
  process.exit(run.status ?? 1);
}

const PATTERNS = [
  ['Google OAuth secret', /GOCSPX-[A-Za-z0-9_-]{20,}/],
  ['Google API key', /AIza[0-9A-Za-z_-]{35}/],
  ['Anthropic key', /sk-ant-[A-Za-z0-9_-]{20,}/],
  ['Private key', /-----BEGIN [A-Z ]*PRIVATE KEY-----/],
  ['Bearer token', /Bearer [A-Za-z0-9._-]{30,}/],
  [
    'Filled secret in env',
    /^(ENCRYPTION_KEY|ADMIN_TOKEN_SECRET|CRON_KEY|GOOGLE_CLIENT_SECRET|OP3_TOKEN|ROUTINE_FIRE_TOKEN|ANTHROPIC_API_KEY)=(?!e2e-|dev-|test)\S{8,}/m,
  ],
  ['Password hash', /\$2y\$1\d\$[./A-Za-z0-9]{53}/],
];
const files = execFileSync('git', ['ls-files', '-co', '--exclude-standard'], { encoding: 'utf8' })
  .split('\n')
  .filter((f) => f && !f.startsWith('node_modules/') && !f.endsWith('package-lock.json') && !f.endsWith('composer.lock') && !/\.(png|jpg|woff2?)$/.test(f));
let found = 0;
for (const file of files) {
  let text;
  try {
    text = readFileSync(file, 'utf8');
  } catch {
    continue;
  }
  for (const [name, re] of PATTERNS) {
    if (re.test(text)) {
      console.log(`${file}: ${name}`);
      found++;
    }
  }
}
console.log(found === 0 ? `Secret scan ok (${files.length} files, built-in rules; gitleaks not installed).` : `${found} possible secrets found.`);
process.exit(found === 0 ? 0 : 1);
