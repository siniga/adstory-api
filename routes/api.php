<?php

use App\Http\Controllers\Api\Adstory\AdstoryEpisodeController;
use App\Http\Controllers\Api\Adstory\AdstoryAiTaskController;
use App\Http\Controllers\Api\Adstory\AdstoryEnvironmentController;
use App\Http\Controllers\Api\Adstory\AdstoryProjectController;
use App\Http\Controllers\Api\Adstory\AdstoryCharacterController;
use App\Http\Controllers\Api\Adstory\AdstorySceneboardController;
use App\Http\Controllers\Api\Adstory\AdstoryStoryboardController;
use App\Http\Controllers\Api\Adstory\AdstorySceneController;
use App\Http\Controllers\Api\Adstory\AdstoryShotController;
use App\Http\Controllers\Api\Adstory\AdstoryShotImageController;
use App\Http\Controllers\Api\Adstory\StoryGenerationController;
use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::get('/adstory/projects', [AdstoryProjectController::class, 'index']);
Route::post('/adstory/projects', [AdstoryProjectController::class, 'store']);
Route::get('/adstory/projects/{project}', [AdstoryProjectController::class, 'show']);
Route::delete('/adstory/projects/{project}', [AdstoryProjectController::class, 'destroy']);
Route::get('/adstory/projects/{project}/full', [AdstoryProjectController::class, 'full']);
Route::put('/adstory/projects/{project}/story', [AdstoryProjectController::class, 'updateStory']);
Route::put('/adstory/projects/{project}/script', [AdstoryProjectController::class, 'updateScript']);
Route::put('/adstory/projects/{project}/screenplay', [AdstoryProjectController::class, 'updateScreenplay']);
Route::put('/adstory/projects/{project}/core', [AdstoryProjectController::class, 'updateCore']);

Route::get('/adstory/projects/{project}/sceneboard', [AdstorySceneboardController::class, 'index']);

Route::get('/adstory/projects/{project}/storyboard', [AdstoryStoryboardController::class, 'index']);
Route::get('/adstory/projects/{project}/storyboard/scenes/{scene}', [AdstoryStoryboardController::class, 'show']);
Route::post('/adstory/projects/{project}/storyboard/scenes/{scene}/generate-shots', [AdstoryStoryboardController::class, 'generateShots']);
Route::post('/adstory/projects/{project}/storyboard/scenes/{scene}/shots/cancel', [AdstoryStoryboardController::class, 'cancelShots']);
Route::get('/adstory/projects/{project}/storyboard/scenes/{scene}/shots/progress', [AdstoryStoryboardController::class, 'shotProgress']);
Route::post('/adstory/projects/{project}/storyboard/scenes/{scene}/generate-all-shot-images', [AdstoryStoryboardController::class, 'generateAllShotImages']);
Route::get('/adstory/projects/{project}/storyboard/scenes/{scene}/shot-images/progress', [AdstoryStoryboardController::class, 'shotImageProgress']);
Route::post('/adstory/projects/{project}/storyboard/scenes/{scene}/shot-images/resume', [AdstoryStoryboardController::class, 'resumeShotImages']);
Route::post('/adstory/projects/{project}/storyboard/scenes/{scene}/shot-images/cancel', [AdstoryStoryboardController::class, 'cancelShotImages']);
Route::get('/adstory/projects/{project}/generation-progress', [AdstoryStoryboardController::class, 'generationProgress']);

Route::post('/adstory/projects/{project}/episodes/plan', [AdstoryEpisodeController::class, 'plan']);
Route::get('/adstory/projects/{project}/episodes/{episode}', [AdstoryEpisodeController::class, 'show']);
Route::post('/adstory/projects/{project}/episodes/{episode}/generate-scenes', [AdstoryEpisodeController::class, 'generateScenes']);
Route::get('/adstory/projects/{project}/episodes/{episode}/scenes/progress', [AdstoryEpisodeController::class, 'sceneProgress']);
Route::get('/adstory/projects/{project}/episodes/{episode}/storyboard', [AdstoryEpisodeController::class, 'storyboard']);
Route::post('/adstory/projects/{project}/episodes/{episode}/generate-shots', [AdstoryEpisodeController::class, 'generateShots']);
Route::get('/adstory/projects/{project}/episodes/{episode}/shots/progress', [AdstoryEpisodeController::class, 'shotProgress']);

Route::get('/adstory/projects/{project}/ai-tasks/summary', [AdstoryAiTaskController::class, 'summary']);
Route::get('/adstory/projects/{project}/ai-tasks/progress', [AdstoryAiTaskController::class, 'progress']);
Route::post('/adstory/projects/{project}/ai-tasks/retry', [AdstoryAiTaskController::class, 'retry']);

Route::get('/adstory/projects/{project}/scenes', [AdstorySceneController::class, 'index']);
Route::get('/adstory/projects/{project}/scenes/progress', [AdstorySceneController::class, 'progress']);
Route::post('/adstory/projects/{project}/scenes/start-generation', [AdstorySceneController::class, 'startGeneration']);
Route::post('/adstory/projects/{project}/scenes/pause-generation', [AdstorySceneController::class, 'pauseGeneration']);
Route::post('/adstory/projects/{project}/scenes/resume-generation', [AdstorySceneController::class, 'resumeGeneration']);
Route::post('/adstory/projects/{project}/scenes/cancel-generation', [AdstorySceneController::class, 'cancelGeneration']);
Route::post('/adstory/projects/{project}/scenes/restart-generation', [AdstorySceneController::class, 'restartGeneration']);
Route::post('/adstory/projects/{project}/scenes/retry-failed', [AdstorySceneController::class, 'retryFailed']);
Route::post('/adstory/projects/{project}/scenes/{scene}/retry', [AdstorySceneController::class, 'retry']);
Route::get('/adstory/projects/{project}/scenes/{scene}/sceneboard', [AdstorySceneboardController::class, 'show']);
// Legacy: per-scene shot generation (use Storyboard/Shots after Characters + Environments)
Route::post('/adstory/projects/{project}/scenes/{scene}/generate-shots', [AdstorySceneboardController::class, 'generateShots']);
Route::get('/adstory/projects/{project}/scenes/{scene}/shots/progress', [AdstorySceneboardController::class, 'shotProgress']);
Route::put('/adstory/projects/{project}/scenes/bulk', [AdstorySceneController::class, 'bulkReplace']);
Route::post('/adstory/projects/{project}/scenes', [AdstorySceneController::class, 'store']);
Route::put('/adstory/projects/{project}/scenes/{scene}', [AdstorySceneController::class, 'update']);
Route::delete('/adstory/projects/{project}/scenes/{scene}', [AdstorySceneController::class, 'destroy']);

Route::get('/adstory/projects/{project}/shots', [AdstoryShotController::class, 'index']);
Route::get('/adstory/projects/{project}/shots/progress', [AdstoryShotController::class, 'progress']);
Route::post('/adstory/projects/{project}/shots/start-generation', [AdstoryShotController::class, 'startGeneration']);
Route::post('/adstory/projects/{project}/shots/resume-generation', [AdstoryShotController::class, 'resumeGeneration']);
Route::put('/adstory/projects/{project}/shots/bulk', [AdstoryShotController::class, 'bulkReplace']);
Route::post('/adstory/projects/{project}/shots', [AdstoryShotController::class, 'store']);
Route::post('/adstory/projects/{project}/shots/generate-images', [AdstoryShotImageController::class, 'generateBatch']);
Route::get('/adstory/projects/{project}/scenes/{scene}/shots', [AdstoryShotController::class, 'indexByScene']);
Route::get('/adstory/projects/{project}/shots/{shot}/images', [AdstoryShotImageController::class, 'index']);
Route::post('/adstory/projects/{project}/shots/{shot}/generate-image', [AdstoryShotImageController::class, 'generateForShot']);
Route::post('/adstory/projects/{project}/shots/{shot}/retry', [AdstoryShotController::class, 'retryImageGeneration']);
Route::post('/adstory/projects/{project}/shots/{shot}/director', [AdstoryShotController::class, 'director']);
Route::put('/adstory/projects/{project}/shots/{shot}/images/{image}/approve', [AdstoryShotImageController::class, 'approve']);
Route::delete('/adstory/projects/{project}/shots/{shot}/images/{image}', [AdstoryShotImageController::class, 'destroy']);
Route::put('/adstory/projects/{project}/shots/{shot}/storyboard-settings', [AdstoryShotController::class, 'updateStoryboardSettings']);
Route::put('/adstory/projects/{project}/shots/{shot}', [AdstoryShotController::class, 'update']);
Route::delete('/adstory/projects/{project}/shots/{shot}', [AdstoryShotController::class, 'destroy']);

Route::get('/adstory/projects/{project}/characters', [AdstoryCharacterController::class, 'index']);
Route::get('/adstory/projects/{project}/characters/progress', [AdstoryCharacterController::class, 'progress']);
Route::post('/adstory/projects/{project}/characters/start-generation', [AdstoryCharacterController::class, 'startGeneration']);
Route::post('/adstory/projects/{project}/characters/resume-generation', [AdstoryCharacterController::class, 'resumeGeneration']);
Route::post('/adstory/projects/{project}/characters/start-extraction', [AdstoryCharacterController::class, 'startExtraction']);
Route::post('/adstory/projects/{project}/characters/start-image-generation', [AdstoryCharacterController::class, 'startImageGeneration']);
Route::put('/adstory/projects/{project}/characters/bulk', [AdstoryCharacterController::class, 'bulkReplace']);
Route::post('/adstory/projects/{project}/characters', [AdstoryCharacterController::class, 'store']);
Route::put('/adstory/projects/{project}/characters/{character}', [AdstoryCharacterController::class, 'update']);
Route::delete('/adstory/projects/{project}/characters/{character}', [AdstoryCharacterController::class, 'destroy']);

Route::get('/adstory/projects/{project}/environments', [AdstoryEnvironmentController::class, 'index']);
Route::get('/adstory/projects/{project}/environments/progress', [AdstoryEnvironmentController::class, 'progress']);
Route::post('/adstory/projects/{project}/environments/start-generation', [AdstoryEnvironmentController::class, 'startGeneration']);
Route::post('/adstory/projects/{project}/environments/start-image-generation', [AdstoryEnvironmentController::class, 'startImageGeneration']);
Route::post('/adstory/projects/{project}/environments/regenerate-images', [AdstoryEnvironmentController::class, 'regenerateAllImages']);
Route::post('/adstory/projects/{project}/environments/resume-generation', [AdstoryEnvironmentController::class, 'resumeGeneration']);
Route::post('/adstory/projects/{project}/environments/start-extraction', [AdstoryEnvironmentController::class, 'startExtraction']);
Route::post('/adstory/projects/{project}/environments/{environment}/regenerate-image', [AdstoryEnvironmentController::class, 'regenerateImage']);
Route::post('/adstory/projects/{project}/environments/{environment}/retry', [AdstoryEnvironmentController::class, 'retry']);
Route::put('/adstory/projects/{project}/environments/bulk', [AdstoryEnvironmentController::class, 'bulkReplace']);
Route::post('/adstory/projects/{project}/environments', [AdstoryEnvironmentController::class, 'store']);
Route::put('/adstory/projects/{project}/environments/{environment}', [AdstoryEnvironmentController::class, 'update']);
Route::delete('/adstory/projects/{project}/environments/{environment}', [AdstoryEnvironmentController::class, 'destroy']);
Route::post('/adstory/projects/{project}/environments/{environment}/generate-reference', [AdstoryEnvironmentController::class, 'generateReference']);

Route::post('/adstory/generate-script', [StoryGenerationController::class, 'generateScript']);
Route::post('/adstory/generate-screenplay', [StoryGenerationController::class, 'generateScreenplay']);
Route::post('/adstory/generate-scenes', [StoryGenerationController::class, 'generateScenes']);
Route::post('/adstory/generate-shots', [StoryGenerationController::class, 'generateShots']);
Route::post('/adstory/extract-characters', [StoryGenerationController::class, 'extractCharacters']);
Route::post('/adstory/extract-environments', [StoryGenerationController::class, 'extractEnvironments']);
Route::post('/adstory/generate-character-image', [StoryGenerationController::class, 'generateCharacterImage']);
Route::post('/adstory/generate-character-reference', [StoryGenerationController::class, 'generateCharacterReference']);
Route::post('/adstory/generate-environment-image', [StoryGenerationController::class, 'generateEnvironmentImage']);
Route::post('/adstory/generate-shot-image', [AdstoryShotImageController::class, 'generate']);
Route::put('/adstory/shot-images/{image}/approve', [AdstoryShotImageController::class, 'approveLegacy']);
