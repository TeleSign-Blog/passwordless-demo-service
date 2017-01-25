<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require __DIR__ . '/../vendor/autoload.php';

$app = new \Slim\App([
  'settings' => [
    'displayErrorDetails' => true
  ]
]);

$app->add(function (Request $request, Response $response, callable $next) {
  return $next($request, $response)
    ->withHeader('Access-Control-Allow-Origin', 'http://localhost:8080')
    ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, Origin, Authorization')
    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

$app->options('/{routes:.+}', function (Request $request, Response $response) {
  return $response;
});

$app->get('/ping', function (Request $request, Response $response) {
  return $response->write('pong');
});

$app->post('/api/authenticate', function (Request $request, Response $response) {
  // TODO Call TeleSign SDK
  return $response->write('');
});

$app->run();
