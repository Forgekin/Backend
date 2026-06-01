@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ rtrim(config('app.url'), '/') }}/email/forgekin-logo.png"
     alt="{{ config('app.name', 'ForgeKin') }}"
     width="170"
     style="width:170px; max-width:170px; height:auto; border:0; display:inline-block;">
</a>
</td>
</tr>
