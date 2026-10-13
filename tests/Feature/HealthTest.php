<?php

it('responds on the health check endpoint', function () {
    $this->get('/up')->assertOk();
});

it('redirects the home page to the app panel when the public site is off', function () {
    config(['marketing.enabled' => false]);

    $this->get('/')->assertRedirect('/app');
});
