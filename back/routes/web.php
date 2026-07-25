<?php
// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
