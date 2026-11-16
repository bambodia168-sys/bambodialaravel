<link rel="stylesheet" href="{{ asset('css/builder-supplemental-terms.css') }}?v=1">
<section class="studio-card studio-supplemental" aria-labelledby="studio-terms-title">
  <div class="studio-card-heading">
    <h3 id="studio-terms-title">{{ __('builder_terms.title') }}</h3>
    <span class="studio-step-caption">{{ __('builder_terms.optional') }}</span>
  </div>

  <div class="studio-field">
    <label for="studio-terms">{{ __('builder_terms.field') }}</label>
    @if($canEdit)
      <p class="hint" id="studio-terms-guidance">{{ __('builder_terms.guidance') }}</p>
    @endif
    <textarea id="studio-terms" name="license_terms" rows="13" maxlength="20000" @readonly(!$canEdit)
              aria-describedby="{{ $canEdit ? 'studio-terms-guidance ' : '' }}studio-terms-limit"
              placeholder="{{ __('builder_terms.placeholder') }}">{{ old('license_terms', $editing ? $record->license_terms : $suggestedTerms) }}</textarea>
    @error('license_terms')<small class="builder-field-error">{{ $message }}</small>@enderror
  </div>

  @if($canEdit)
    <div class="studio-terms-helpers">
      <button type="button" class="studio-tool-action" data-use-platform-terms>
        <x-icon name="file-check" size="16"/>{{ __('builder_terms.use_template') }}
      </button>
      <button type="button" class="studio-tool-action" data-clear-platform-terms>
        <x-icon name="eraser" size="16"/>{{ __('builder_terms.clear') }}
      </button>
    </div>
    <script type="application/json" data-suggested-terms>@json($suggestedTerms)</script>
  @endif

  <p id="studio-terms-limit" class="studio-supplemental-notice">
    <x-icon name="shield" size="18"/>
    <span>{{ __('builder_terms.license_limit') }}</span>
  </p>

  @if($supplementalSources !== [])
    <details class="studio-supplemental-sources">
      <summary>
        <x-icon name="book-open" size="18"/>
        <span>{{ __('builder_terms.sources') }}</span>
        <span class="studio-supplemental-count">{{ $termsPublications->count() }}</span>
        <x-icon name="chevron-down" size="17" class="studio-supplemental-chevron"/>
      </summary>
      <div class="studio-supplemental-groups">
        @foreach($supplementalSources as $group => $publications)
          <div>
            <h4>{{ __("builder_terms.groups.$group") }}</h4>
            <ul>
              @foreach($publications as $publication)
                @php
                  $titleKey = 'builder_terms.policies.'.$publication->policy->slug;
                  $title = __($titleKey);
                @endphp
                <li>
                  <a href="{{ $publication->policy->publicUrl() }}" target="_blank" rel="noopener noreferrer">
                    <span>{{ $title === $titleKey ? $publication->title : $title }}</span>
                    <span class="studio-supplemental-revision">{{ __('builder_terms.revision', ['revision' => $publication->revision]) }}</span>
                    <x-icon name="external-link" size="14"/>
                  </a>
                </li>
              @endforeach
            </ul>
          </div>
        @endforeach
      </div>
    </details>
  @else
    <p class="hint">{{ __('builder_terms.no_sources') }}</p>
  @endif
</section>
