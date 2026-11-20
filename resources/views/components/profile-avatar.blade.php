@props(['user' => null, 'public' => false, 'size' => 19, 'alt' => '', 'lazy' => false])
@php($avatarUrl = $user?->profileAvatarUrl($public))
<span class="profile-avatar" data-profile-avatar>
  <span class="profile-avatar-fallback" data-profile-avatar-fallback aria-hidden="true" @if($avatarUrl) hidden @endif>
    <x-icon name="user" :size="$size"/>
  </span>
  @if($avatarUrl)
    <img src="{{ $avatarUrl }}" alt="{{ $alt }}" class="profile-avatar-photo"
         decoding="async" @if($lazy) loading="lazy" @endif data-profile-avatar-image>
  @endif
</span>
