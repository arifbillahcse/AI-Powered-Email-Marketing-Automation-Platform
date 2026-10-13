@extends('marketing.layout', ['title' => $article['title'], 'description' => $article['summary']])

@section('content')
    <section>
        <div class="wrap">
            <p><a href="{{ route('help.index') }}">&larr; Help center</a></p>
            <article class="prose">
                <h1>{{ $article['title'] }}</h1>
                {{-- Our own Markdown, rendered with raw HTML stripped (HelpCenter). --}}
                {!! $article['html'] !!}
            </article>
        </div>
    </section>
@endsection
