<dialog class="studio-upload-dialog" id="studio-ai-draft" data-ai-draft-dialog
  data-generate-url="{{ route('builder.ai.generate') }}"
  data-review-url-template="{{ route('builder.ai.reviewed', ['draft'=>'__ID__']) }}"
  data-content-type="{{ $type }}"
  data-record-reference="{{ $record?->public_id ?? '' }}">
  <div class="studio-card-heading">
    <div><h3><x-icon name="sparkles" size="20"/> AI Draft Studio</h3><p class="hint">Assisted writing for your {{ $types[$type]['label'] }}. You remain responsible for accuracy and rights.</p></div>
    <button type="button" class="studio-editor-icon" data-modal-close title="Close" aria-label="Close AI Draft Studio"><x-icon name="x" size="19"/></button>
  </div>
  <form data-ai-generate-form>
    @csrf
    <div class="studio-field">
      <label for="ai-brief">What do you want to create?</label>
      <textarea id="ai-brief" name="brief" rows="5" minlength="30" maxlength="1500" required
        placeholder="Describe your real product, service, project, freelance offer or job requirements. Include only information you are authorized to share."></textarea>
      <small class="hint">Do not include passwords, private customer information, credentials or confidential documents.</small>
    </div>
    <div class="studio-form-grid">
      <div class="studio-field">
        <label for="ai-tone">Writing style</label>
        <select id="ai-tone" name="tone" required>
          <option value="professional">Professional</option>
          <option value="friendly">Friendly</option>
          <option value="concise">Concise</option>
        </select>
      </div>
      <div class="studio-field">
        <label for="ai-locale">Draft language</label>
        <select id="ai-locale" name="locale" required>
          @foreach(['en'=>'English','km'=>'Khmer','zh'=>'Chinese','ko'=>'Korean','ja'=>'Japanese',
            'th'=>'Thai','vi'=>'Vietnamese','de'=>'German','fr'=>'French','hi'=>'Hindi',
            'id'=>'Indonesian','ms'=>'Malay','es'=>'Spanish'] as $code=>$label)
            <option value="{{ $code }}" @selected((auth()->user()->locale??'en')===$code)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <label class="studio-field" style="display:flex;gap:10px;align-items:flex-start">
      <input type="checkbox" name="consent" value="1" required style="margin-top:5px">
      <span>I understand my brief will be sent to the configured AI provider to generate suggestions. I will review all claims, rights and content before saving.</span>
    </label>
    <div class="studio-submit-actions">
      <button type="button" class="studio-tool-action" data-modal-close>Cancel</button>
      <button type="submit" class="btn primary" data-ai-generate><x-icon name="sparkles" size="16"/> Generate draft</button>
    </div>
  </form>
  <p data-ai-status role="status" aria-live="polite" class="hint"></p>
  <section data-ai-review hidden aria-labelledby="ai-review-heading">
    <div class="studio-card-heading"><h3 id="ai-review-heading">Review and edit AI suggestion</h3></div>
    <div class="studio-field">
      <label for="ai-review-title">Suggested title</label>
      <input id="ai-review-title" data-ai-title type="text" minlength="8" maxlength="180">
    </div>
    <div class="studio-field">
      <label for="ai-review-description">Suggested description</label>
      <textarea id="ai-review-description" data-ai-description rows="8" minlength="{{ $isJob ? 70 : 40 }}" maxlength="10000"></textarea>
    </div>
    <p class="hint">AI text may be inaccurate. Check every claim. Applying inserts your edited suggestion into this Builder form only; it does not save or publish anything.</p>
    <div class="studio-submit-actions">
      <button type="button" class="studio-tool-action" data-ai-discard>Discard</button>
      <button type="button" class="btn primary" data-ai-apply><x-icon name="check" size="16"/> Apply reviewed draft</button>
    </div>
  </section>
</dialog>
