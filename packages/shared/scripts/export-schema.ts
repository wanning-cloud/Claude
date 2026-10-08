// Writes the zod import schema as JSON Schema for the PHP API, so both sides validate against one source.
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { z } from 'zod';
import { ImportPayload } from '../src/import.ts';

const here = dirname(fileURLToPath(import.meta.url));
const target = resolve(here, '../../../apps/api/schema/import.schema.json');
mkdirSync(dirname(target), { recursive: true });
const schema = z.toJSONSchema(ImportPayload, { target: 'draft-2020-12' });
writeFileSync(target, `${JSON.stringify(schema, null, 2)}\n`);
console.log(`import schema written to ${target}`);
