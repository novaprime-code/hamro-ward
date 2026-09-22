<?php

declare(strict_types=1);

it('reports service status', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response
        ->assertOk()
        ->assertJsonStructure(['status', 'service', 'version', 'time'])
        ->assertJsonPath('status', 'ok');
});

it('hides component detail from public callers', function (): void {
    config()->set('app.env', 'production');
    app()->detectEnvironment(fn (): string => 'production');

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->getJson('/api/v1/health?detail=1');

    $response->assertOk()->assertJsonMissingPath('checks');
});
