// zod schemas: the import contract (exported as JSON Schema for the PHP API) and request bodies.
import { z } from 'zod';
import { PLATFORMS } from './platforms.ts';

export * from './import.ts';

export const PlatformSchema = z.enum(PLATFORMS);
export const CommentStatusSchema = z.enum(['new', 'answered', 'done', 'later']);
export const CommentPatchSchema = z.object({
  status: CommentStatusSchema.optional(),
  note: z.string().max(4000).nullable().optional(),
});
export const ReplyRequestSchema = z.object({ text: z.string().trim().min(1).max(10000) });
