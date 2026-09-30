<?php

it('responds on the health check endpoint', function () {
    $this->get('/up')->assertOk();
});

it('redirects the home page to the app panel', function () {
    $this->get('/')->assertRedirect('/app');
});
