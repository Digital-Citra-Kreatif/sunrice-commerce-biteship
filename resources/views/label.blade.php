{{-- A shipping label for one Biteship shipment (A6). Override with resources/views/vendor/commerce-biteship/label.blade.php. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $order->number }}</title>
    <style>
        @page { margin: 14px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 4px 5px; border: 1px solid #000; }
        .big { font-size: 14px; font-weight: bold; }
        .huge { font-size: 18px; font-weight: bold; letter-spacing: 1px; }
        .center { text-align: center; }
        .label { font-size: 7px; text-transform: uppercase; color: #333; }
        .testing { font-size: 10px; font-weight: bold; text-align: center; border: 2px dashed #000; padding: 2px; margin-bottom: 4px; }
    </style>
</head>
<body>
    @if ($testing)
        <div class="testing">BITESHIP TESTING MODE — NOT A REAL SHIPMENT</div>
    @endif
    <table>
        <tr>
            <td class="big" style="width: 60%">{{ $courier }}</td>
            <td class="center"><div class="label">{{ __('Service') }}</div><div class="big">{{ $service }}</div></td>
        </tr>
        <tr>
            <td colspan="2" class="center">
                @if ($waybill !== '')
                    <img src="{{ $barcode }}" style="height: 48px; max-width: 100%;" alt="">
                    <div class="huge">{{ $waybill }}</div>
                @else
                    <div class="big">{{ __('Waybill number not issued yet') }}</div>
                @endif
                @if ($routing)
                    <div class="big">{{ $routing }}</div>
                @endif
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="label">{{ __('To') }}</div>
                <div class="big">{{ $to->get('name') ?: $order->customer_name }}</div>
                <div>{{ $to->get('phone') ?: $order->phone }}</div>
                <div>{!! nl2br(e($street)) !!}</div>
                @if ($to->get('notes'))<div><em>{{ $to->get('notes') }}</em></div>@endif
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="label">{{ __('From') }}</div>
                <strong>{{ $from['name'] }}</strong> · {{ $from['phone'] }}
                <div>{{ $from['address'] }}</div>
            </td>
        </tr>
        <tr>
            <td><div class="label">{{ __('Order') }}</div><strong>{{ $order->number }}</strong></td>
            <td><div class="label">{{ __('Weight') }}</div><strong>{{ number_format($weight / 1000, 2, ',', '.') }} kg</strong></td>
        </tr>
        <tr>
            <td colspan="2" class="center">
                @if ($cod > 0)
                    <div class="label">{{ __('Cash on delivery') }}</div><div class="huge">COD {{ $money->format($cod) }}</div>
                @else
                    <div class="big">NON-COD</div>
                @endif
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="label">{{ __('Contents') }}</div>
                @foreach ($items as $item)
                    <div>{{ $item['quantity'] }} × {{ $item['name'] }}</div>
                @endforeach
                @if ($order->customer_note)<div style="margin-top: 3px"><em>{{ $order->customer_note }}</em></div>@endif
            </td>
        </tr>
    </table>
</body>
</html>
