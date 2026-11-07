@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="node"></span>{!! $slot !!}
</a>
</td>
</tr>
