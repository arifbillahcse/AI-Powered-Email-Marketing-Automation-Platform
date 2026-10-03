<?php

use App\Services\Campaigns\TemplateRenderer;

beforeEach(fn () => $this->renderer = new TemplateRenderer);

it('replaces variables, case and space insensitive', function () {
    expect($this->renderer->render('Hi {{first_name}}, {{ Company }}!', ['first_name' => 'Jane', 'company' => 'Acme'], 's'))
        ->toBe('Hi Jane, Acme!');
});

it('uses fallbacks for blank or unknown variables', function () {
    expect($this->renderer->render('Hi {{first_name|there}} at {{company}}{{nope|}}.', ['first_name' => '  '], 's'))
        ->toBe('Hi there at .');
});

it('reports variables that would render empty', function () {
    $missing = $this->renderer->missingVariables('{{first_name}} {{company|your team}} {{title}}', ['first_name' => '', 'title' => 'CEO']);

    expect($missing)->toBe(['first_name']);
});

it('spins deterministically per seed, including nested groups', function () {
    $template = '{Hi|Hello|Hey} {there|{friend|pal}}';

    $a = $this->renderer->render($template, [], 'lead-1:step-1');
    $again = $this->renderer->render($template, [], 'lead-1:step-1');

    $outputs = collect(range(1, 40))->map(fn ($i) => $this->renderer->render($template, [], "lead-{$i}"))->unique();

    expect($a)->toBe($again)
        ->and($a)->toMatch('/^(Hi|Hello|Hey) (there|friend|pal)$/')
        ->and($outputs->count())->toBeGreaterThan(3);
});

it('never spins or interprets lead data', function () {
    $output = $this->renderer->render('Hi {{first_name}}', ['first_name' => '{Evil|Twin} {{company}}'], 's');

    expect($output)->toBe('Hi {Evil|Twin} {{company}}');
});

it('leaves braces without options alone (e.g. CSS)', function () {
    expect($this->renderer->render('<style>p {color: red}</style>{A|A}', [], 's'))
        ->toBe('<style>p {color: red}</style>A');
});

it('escapes values in HTML mode only', function () {
    $vars = ['company' => '<b>Acme & Co</b>'];

    expect($this->renderer->render('<p>{{company}}</p>', $vars, 's', html: true))->toBe('<p>&lt;b&gt;Acme &amp; Co&lt;/b&gt;</p>')
        ->and($this->renderer->render('{{company}}', $vars, 's'))->toBe('<b>Acme & Co</b>');
});

it('lists the variables a template uses', function () {
    expect($this->renderer->variablesIn('{{First_Name}} {{company|x}} {{first_name}}'))->toBe(['first_name', 'company']);
});
