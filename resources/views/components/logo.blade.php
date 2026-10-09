@props(['class' => 'size-9', 'res' => 64])
@use('App\Support\Branding')
<img src="{{ Branding::logoUrl($res) }}" alt="" {{ $attributes->merge(['class' => $class.' shrink-0 rounded-full object-cover']) }}>
