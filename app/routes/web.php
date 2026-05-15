<?php

use App\Http\Controllers\CareCenterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\MedicationSchemaUploadController;
use App\Http\Controllers\PhilBulkController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ReviewExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/care-centers', [CareCenterController::class, 'index'])->name('care-centers.index');
    Route::get('/care-centers/{careCenter:slug}', [CareCenterController::class, 'show'])->name('care-centers.show');
    Route::get('/departments/{department}', [DepartmentController::class, 'show'])->name('departments.show');
    Route::get('/residents/{resident:slug}', [ResidentController::class, 'show'])->name('residents.show');

    Route::post('/residents/{resident:slug}/reviews', [ReviewController::class, 'start'])->name('reviews.start');
    Route::get('/reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
    Route::post('/reviews/{review}/refresh-gheops', [ReviewController::class, 'refreshGheops'])->name('reviews.refresh-gheops');
    Route::post('/reviews/{review}/refresh-phil', [ReviewController::class, 'refreshPhil'])->name('reviews.refresh-phil');
    Route::post('/departments/{department}/phil-bulk', [PhilBulkController::class, 'forDepartment'])->name('phil.bulk.department');
    Route::post('/care-centers/{careCenter:slug}/phil-bulk', [PhilBulkController::class, 'forCareCenter'])->name('phil.bulk.care-center');
    Route::post('/reviews/{review}/findings', [ReviewController::class, 'storeFinding'])->name('reviews.findings.store');
    Route::patch('/reviews/{review}/findings/{finding}', [ReviewController::class, 'updateFinding'])->name('reviews.findings.update');
    Route::delete('/reviews/{review}/findings/{finding}', [ReviewController::class, 'destroyFinding'])->name('reviews.findings.destroy');
    Route::post('/reviews/{review}/attentions', [ReviewController::class, 'storeAttention'])->name('reviews.attentions.store');
    Route::delete('/reviews/{review}/attentions/{attention}', [ReviewController::class, 'destroyAttention'])->name('reviews.attentions.destroy');
    Route::post('/reviews/{review}/finalize', [ReviewController::class, 'finalize'])->name('reviews.finalize');

    Route::get('/exports/create', [ReviewExportController::class, 'create'])->name('exports.create');
    Route::post('/exports', [ReviewExportController::class, 'store'])->name('exports.store');
    Route::get('/exports/{export}/download', [ReviewExportController::class, 'download'])->name('exports.download');

    Route::get('/uploads/create', [MedicationSchemaUploadController::class, 'create'])->name('uploads.create');
    Route::post('/uploads', [MedicationSchemaUploadController::class, 'store'])->name('uploads.store');
    Route::get('/uploads/{upload}', [MedicationSchemaUploadController::class, 'show'])->name('uploads.show');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
