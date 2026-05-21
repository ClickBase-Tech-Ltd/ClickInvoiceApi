{{-- Icon row for email clients — @include('emails.partials.social-follow') --}}
@php
    $socialLinks = config('clickinvoice.social', []);
    $iconBase = rtrim(config('clickinvoice.social_icon_base', 'https://app.clickinvoice.app/images/social'), '/');
@endphp
@if (!empty($socialLinks))
    <p style="margin:16px 0 10px;font-size:13px;line-height:1.5;color:#64748b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;text-align:center;">
        <strong style="color:#475569;">Follow ClickInvoice</strong>
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 12px;">
        <tr>
            @foreach ($socialLinks as $link)
                @php
                    $iconFile = $link['icon'] ?? '';
                    $iconUrl = $iconFile ? $iconBase . '/' . $iconFile : '';
                    $label = $link['platform'] ?? 'Social';
                @endphp
                <td align="center" style="padding:0 10px;">
                    <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" title="Follow us on {{ $label }}" style="text-decoration:none;">
                        @if ($iconUrl)
                            <img
                                src="{{ $iconUrl }}"
                                width="32"
                                height="32"
                                alt="{{ $label }}"
                                style="display:block;border:0;outline:none;width:32px;height:32px;"
                            />
                        @else
                            <span style="color:#0A66C2;font-size:12px;font-weight:600;">{{ $label }}</span>
                        @endif
                    </a>
                </td>
            @endforeach
        </tr>
    </table>
@endif
