<?php

use Illuminate\Support\Facades\Route;

// The public marketing site comes later; send visitors to the app for now.
Route::redirect('/', '/app');
