<?php 
use Illuminate\Support\Facades\Route;

Route::post('/duitku/webhook', [App\Extensions\Gateways\Duitku\Duitku::class, 'webhook'])->name('webhook');
