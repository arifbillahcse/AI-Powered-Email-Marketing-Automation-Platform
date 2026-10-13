@extends('marketing.layout', ['title' => 'Help center', 'description' => 'Guides for setting up mailboxes, DNS, warmup and campaigns.'])

@section('content')
    <section>
        <div class="wrap">
            <h2>Help center</h2>
            <p class="lead">Guides to get set up and keep your emails out of spam.</p>
            <div class="grid">
                @foreach ($articles as $article)
                    <a class="card" href="{{ route('help.article', $article['slug']) }}" style="text-decoration: none; color: inherit;">
                        <h3>{{ $article['title'] }}</h3>
                        <p>{{ $article['summary'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endsection
