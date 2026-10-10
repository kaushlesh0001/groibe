@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- PNG, because email clients do not reliably render SVG (spec 10.3). A proportionally resized copy of the supplied file (600 wide), so the message stays light. --}}
<img src="{{ url('brand/groibe-logo-small.png') }}" width="160" height="40" alt="Groibe" style="display: block; width: 160px; height: auto; border: 0;">
</a>
</td>
</tr>
