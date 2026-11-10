@php $editRule=$rule??null; @endphp
<div class="settings-form" style="padding:0;border:0">
<label>Language <select name="language_code" required>@foreach($languages as $language)<option value="{{ $language->code }}" @selected(($editRule?->language_code??'en')===$language->code)>{{ $language->name }}</option>@endforeach</select></label>
<label>Element <select name="element" required>@foreach(\App\Models\SiteTypographyRule::ELEMENTS as $code=>$name)<option value="{{ $code }}" @selected(($editRule?->element??'body')===$code)>{{ $name }}</option>@endforeach</select></label>
<label>Font family <select name="font_family" required>@foreach(\App\Models\SiteTypographyRule::FONTS as $code=>$name)<option value="{{ $code }}" @selected(($editRule?->font_family??'system')===$code)>{{ $name }}</option>@endforeach</select></label>
<label>Font size in px <input type="number" name="font_size" min="10" max="72" required value="{{ $editRule?->font_size??14 }}"></label>
<label>Font weight <select name="font_weight" required>@foreach([400,500,600,700,800] as $weight)<option value="{{ $weight }}" @selected(($editRule?->font_weight??400)===$weight)>{{ $weight }}</option>@endforeach</select></label>
<label>Line height <input name="line_height" type="number" step="0.05" min="1" max="2.5" required value="{{ $editRule?->line_height??1.5 }}"></label>
<label>Alignment <select name="alignment" required>@foreach(['left','center','right','justify'] as $align)<option value="{{ $align }}" @selected(($editRule?->alignment??'left')===$align)>{{ ucfirst($align) }}</option>@endforeach</select></label>
</div>
