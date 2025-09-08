<?php
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('/explore', [WalletController::class, 'explore'])->name('explore');