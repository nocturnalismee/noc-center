<x-mail::message>
# {{ $title }}

{{ $summary }}

<x-mail::table>
| Field | Value |
|:------|:------|
@foreach ($rows as $label => $value)
| {{ $label }} | {{ $value }} |
@endforeach
</x-mail::table>

<x-mail::button :url="$url">
{{ $actionLabel }}
</x-mail::button>
</x-mail::message>