@php
    use BaconQrCode\Renderer\ImageRenderer;
    use BaconQrCode\Renderer\Image\SvgImageBackEnd;
    use BaconQrCode\Renderer\RendererStyle\RendererStyle;
    use BaconQrCode\Writer;
    use Illuminate\Support\Str;

    $qrUrl = route('track', ['utm_source' => 'qr']);

    $qrPaths = cache()->rememberForever('qr-code.v2.' . md5($qrUrl), function () use ($qrUrl): string {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(256, 2), new SvgImageBackEnd())))->writeString($qrUrl);

        return Str::of($svg)->after('fill="#ffffff"/>')->beforeLast('</svg>')->replace('fill="#000000"', 'fill="currentColor"')->toString();
    });
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" {{ $attributes }}
    shape-rendering="crispEdges">
    {!! $qrPaths !!}
</svg>
