<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

use \Firebase\JWT\JWT;

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

$app->get('/api/authenticate', function (Request $request, Response $response) {
  $params = $request->getQueryParams();
  $phone = $params['phone'];
  $expires_in = 5 * 60;
  $payload = [
    'sub' => $phone,
    'exp' => time() + $expires_in
  ];

  // TODO Call TeleSign SDK

  $jwt = JWT::encode($payload, 'session_secret_key');

  return $response->withJson([
    'id_token' => $jwt,
    'token_type' => 'Bearer',
    'expires_in' => $expires_in
  ]);
});

$app->group('/api/protected', function () use ($app) {
  $app->get('/albums', function (Request $request, Response $response) {
    return $response
      ->withHeader('Content-Type', 'application/json')
      ->write(file_get_contents(__DIR__ . '/../albums.json'));
  });
})->add(function (Request $request, Response $response, callable $next) {

  // TODO Validate jwt

  return $next($request, $response);
});

$app->run();
