# Bambodia AI Draft Studio — first milestone

## Scope
Extends the **existing authenticated Builder Studio**, not the public marketplace layout.
The five supported types are Product, Project, Service, Freelance, and Job.
AI Draft is an optional toolbar action. Provider credentials and usage are managed
by super-admins at \`/dashboard/ai-draft-studio\`.

## Activation (fail-closed)
1. Run the new additive migration in an isolated QA database.
2. Visit the admin settings. Paste a real OpenAI API key. Secret stored with
   Eloquent's encrypted cast, never returned to browsers or activity tables.
3. Select \`gpt-4.1-mini\` or \`gpt-4o-mini\`, then select daily limits.
4. **Test connection**: makes a real provider request with a tiny fixed probe.
5. **Enable** intentionally within 24h of a successful probe.
6. Open Builder as an eligible owner. Enter a non-confidential creative brief,
   choose a writing style/language, affirm external-provider disclosure and generate.
7. Review the generated title/description, fix claims/rights, then **Apply reviewed
   draft**. This only inserts text locally into the existing Builder fields.
   The creator must still follow Bambodia's existing save/terms/moderation workflow.

## Security properties
- Server-side role and content ownership/status checks apply before provider calls.
- Provider endpoint is fixed to \`https://api.openai.com/v1/responses\`; redirects
  are disallowed, no user-supplied URL, no automatic model web/file tools.
- Response API uses \`store=false\`. **This requests no storage at the provider**
  where supported; consult provider documentation/policy for retention and privacy.
- All attempted calls (including timeouts) consume quota. A row-level locked
  provider record serializes concurrent per-user and global daily limits.
  Request throttling is an additional per-minute control, not a cost-control substitute.
- Only metadata (creator, type, model, result status, character counts, reviewed time)
  is stored. Prompt and result text are not persisted to the AI usage database.
- Generated text is plain text and inserted through \`textContent\`, not \`innerHTML\`.
- Provider errors/keys are not logged or returned to users. HTTP 502 is generic.
- AI cannot publish, approve licenses, upload media or edit any records directly.
- Disabling or disconnecting preserves all existing marketplace records.
- Owner-only review acknowledgments use an auditable, idempotent update.

## Production acceptance gates
- Provider credentials cannot be tested against a fake API in production.
- CI uses \`Http::fake()\`, not a real paid model. Manual test with an authorized
  low-cost provider key, actual network access and provider billing controls is required.
- Test on a disposable database, verify mobile form/editor behavior and the existing
  terms/review flow. Roll out only after hosting and physical-device acceptance.
- Never pass secrets from arbitrary user-supplied prompts, customer records, R2
  credentials or the site's \`.env\` to the model.
- Keep production unchanged until exact-source comparison, verified backup-first
  migration and a separately reviewed deploy script pass all required checks.
