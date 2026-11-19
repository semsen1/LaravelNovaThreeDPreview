<?php

use Illuminate\Support\Facades\Route;
use Semsen1\ThreeDPreviewField\Http\Controllers\PreviewSourceController;

Route::get('{resource}/{resourceId}/{field}/source', [PreviewSourceController::class, 'source'])
    ->name('three-d-preview.source');

Route::get('{resource}/{resourceId}/{field}/stream', [PreviewSourceController::class, 'stream'])
    ->name('three-d-preview.stream');
