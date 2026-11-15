@php
  use App\Support\MarketplaceManagement;
  use App\Support\UserManagementAccess;
  use Illuminate\Support\Str;

  $isJob = $type === 'job';
  $isProduct = $type === 'product';
  $name = ucfirst($type);
  $canReview = UserManagementAccess::allows(auth()->user(), 'marketplace_review');
  $canTransfer = UserManagementAccess::allows(auth()->user(), 'marketplace_transfer');
  $managerUrl = MarketplaceManagement::indexUrl($type);
  $filters = array_filter(['q' => $search, 'status' => $status, 'owner' => $owner, 'category' => $category],
    fn ($value) => $value !== null && $value !== '');
@endphp
<div class="page-intro">
  <div class="eyebrow">Marketplace Governance</div>
  <h1>{{ $name }} Manager</h1>
  <p>{{ $isProduct ? 'Products require an active license and a valid cover to publish.' : 'Find listings, open their Builder Studio preview, and manage review status.' }}</p>
  @if(in_array($type, \App\Support\BuilderAccess::allowedTypes(auth()->user()), true))
    <a class="btn primary" href="{{ route('builder', ['type' => $type]) }}"><x-icon name="plus" size="16"/> Create {{ strtolower($name) }}</a>
  @endif
</div>
@foreach($errors->all() as $error)
  <p class="notice" role="alert">{{ $error }}</p>
@endforeach
<section class="panel">
  <div class="panel-head">
    <h2>{{ $isProduct ? 'Digital products' : $name.' listings' }} <span class="count">{{ $records->total() }}</span></h2>
    <form class="table-search" method="get" action="{{ $managerUrl }}">
      @if($owner !== null)<input type="hidden" name="owner" value="{{ $owner }}">@endif
      @if($category !== null)<input type="hidden" name="category" value="{{ $category }}">@endif
      <input name="q" value="{{ $search }}" placeholder="Search title or public ID" aria-label="Search listings" maxlength="120">
      <select class="table-select" name="status" aria-label="Filter status">
        @foreach($statuses as $key => $label)
          <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
        @endforeach
      </select>
      <button class="btn subtle" type="submit">Filter</button>
      @if($owner !== null || $category !== null || $status !== 'all' || $search !== '')
        <a class="btn subtle" href="{{ $managerUrl }}">Clear filters</a>
      @endif
    </form>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr>
        <th scope="col">Public ID</th><th scope="col">Title</th><th scope="col">{{ $isJob ? 'Client' : 'Creator' }}</th>
        <th scope="col">Category</th><th scope="col">{{ $isProduct ? 'License' : ($isJob ? 'Budget' : 'Price') }}</th>
        <th scope="col">Preview</th><th scope="col">Status</th><th scope="col">Action</th>
      </tr></thead>
      <tbody>
        @forelse($records as $record)
          @php
            $member = $isJob ? $record->client : $record->creator;
            $categoryName = ($isJob ? ($jobCategories[$record->category] ?? Str::headline($record->category ?? '')) : $record->category?->name) ?: 'Uncategorized';
            $categoryKey = $isJob ? $record->category : $record->category_id;
            $previewUrl = MarketplaceManagement::builderUrl($record, true);
            $thumbnail = $isProduct ? ($record->coverAsset ?? $record->featuredAsset) : $record->featuredAsset;
            if ($thumbnail && ((int) $thumbnail->user_id !== MarketplaceManagement::ownerId($record)
                || !str_starts_with($thumbnail->mime_type, 'image/'))) {
              $thumbnail = null;
            }
            $thumbUrl = $thumbnail ? route('dashboard.marketplace.asset', [
              'type' => $type, 'publicId' => $record->public_id, 'mediaId' => $thumbnail->public_id,
            ]) : null;
            $amount = $isJob ? $record->budget : $record->price;
            $statusLabel = $statuses[$record->status] ?? ucfirst($record->status);
          @endphp
          <tr>
            <td class="mono"><a href="{{ $previewUrl }}" aria-label="Preview listing {{ $record->public_id }}">{{ $record->public_id }}</a></td>
            <td><strong><a href="{{ $previewUrl }}">{{ $record->title }}</a></strong></td>
            <td>
              @if($member)
                <a href="{{ MarketplaceManagement::indexUrl($type, array_replace($filters, ['owner' => $member->id])) }}"
                   aria-label="Filter by {{ $isJob ? 'client' : 'creator' }} {{ $member->name }}">{{ $member->name }}</a>
              @else
                <span class="text-muted">Unavailable</span>
              @endif
            </td>
            <td>
              @if($categoryKey)
                <a href="{{ MarketplaceManagement::indexUrl($type, array_replace($filters, ['category' => $categoryKey])) }}"
                   aria-label="Filter by category {{ $categoryName }}">{{ $categoryName }}</a>
              @else
                <span class="text-muted">{{ $categoryName }}</span>
              @endif
            </td>
            <td>
              @if($isProduct)
                {{ $record->licenseDefinition?->name ?? 'Missing' }}
                @if($record->licenseDefinition)<div class="text-muted">{{ $record->licenseDefinition->version }}</div>@endif
              @else
                {{ $amount === null ? '—' : '$'.number_format((float) $amount, 2) }}
              @endif
            </td>
            <td>
              <a href="{{ $previewUrl }}" aria-label="Preview {{ $record->title }}">
                @if($thumbUrl)
                  <img class="media-thumb" src="{{ $thumbUrl }}" width="52" height="52" style="display:block;width:52px;height:52px;object-fit:cover" alt="" loading="lazy">
                @else
                  <x-icon name="eye" size="18"/> Preview
                @endif
              </a>
            </td>
            <td><span class="role-pill">{{ $statusLabel }}</span></td>
            <td>@include('dashboard.partials.marketplace-actions')</td>
          </tr>
        @empty
          <tr><td colspan="8" class="empty-cell">No listings match these filters.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <x-pagination :paginator="$records"/>
</section>
