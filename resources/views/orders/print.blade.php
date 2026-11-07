<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Order #{{ $order->id }} - {{ $company->name }}</title>
<style>
  body{font-family:Inter,Arial,sans-serif;color:#0f172a;margin:0;padding:32px;max-width:820px;margin:auto}
  h1{margin:0;font-size:26px} h1 span{color:#ef4444}
  .muted{color:#64748b;font-size:13px}
  .head{display:flex;align-items:center;gap:16px}
  .head img{width:72px;height:72px;object-fit:cover;border-radius:12px;border:1px solid #e2e8f0}
  .pay{margin-top:28px;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;line-height:1.7}
  .pay b{display:inline-block;min-width:110px;color:#475569}
  .row{display:flex;justify-content:space-between;gap:24px;margin:20px 0}
  table{width:100%;border-collapse:collapse;margin-top:12px}
  th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:14px}
  th{background:#f1f5f9;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
  td.num,th.num{text-align:right}
  .totals{margin-left:auto;width:280px;margin-top:16px}
  .totals div{display:flex;justify-content:space-between;padding:6px 0;font-size:14px}
  .totals .grand{border-top:2px solid #0f172a;font-weight:700;font-size:17px}
  @media print{body{padding:0}}
</style>
</head>
<body onload="window.print()">
  <div class="head">
    <img src="{{ $company->logo_url ?: url('/images/mk-electrical.png') }}" alt="" onerror="this.style.display='none'">
    <div>
      <h1>{{ $company->name }}</h1>
      <div class="muted">{{ collect([$company->address, $company->city, $company->phone ? "Ph: ".$company->phone : null, $company->gstin ? "GSTIN: ".$company->gstin : null])->filter()->implode(" · ") }}</div>
    </div>
  </div>

  <div class="row">
    <div>
      <strong>Billed to</strong><br>
      {{ $order->customer_name }}<br>
      {{ $order->customer_phone }}<br>
      <span class="muted">{{ collect([$order->address, $order->area, $order->city])->filter()->implode(', ') }}</span>
    </div>
    <div style="text-align:right">
      <strong>Order #{{ $order->id }}</strong><br>
      {{ $order->order_name }}<br>
      <span class="muted">{{ optional($order->order_date)->format('d M Y') }} &middot; {{ ucfirst($order->status) }}</span>
    </div>
  </div>

  <table>
    <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @foreach($order->items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $item->product->product_name ?? 'Item' }}</td>
        <td class="num">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
        <td class="num">₹{{ number_format($item->rate, 2) }}</td>
        <td class="num">₹{{ number_format($item->amount, 2) }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div><span>Items total</span><span>₹{{ number_format($order->order_amount, 2) }}</span></div>
    <div><span>Service charge</span><span>₹{{ number_format($order->service_charge, 2) }}</span></div>
    <div class="grand"><span>Total</span><span>₹{{ number_format($order->total, 2) }}</span></div>
  </div>

  @if($order->notes)<p class="muted">Notes: {{ $order->notes }}</p>@endif

  @if($company->upi_id || $company->account_number)
  <div class="pay">
    <strong>Pay to</strong><br>
    @if($company->upi_id)<div><b>UPI</b> {{ $company->upi_id }}@if($company->upi_name) ({{ $company->upi_name }})@endif</div>@endif
    @if($company->account_number)
      <div><b>Bank</b> {{ $company->bank_name }}@if($company->bank_branch), {{ $company->bank_branch }}@endif</div>
      <div><b>Account name</b> {{ $company->account_holder ?: $company->name }}</div>
      <div><b>Account number</b> {{ $company->account_number }}</div>
      @if($company->ifsc)<div><b>IFSC</b> {{ $company->ifsc }}</div>@endif
    @endif
  </div>
  @endif
</body>
</html>
