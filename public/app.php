<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

use telesign\sdk\messaging\MessagingClient;
use Firebase\JWT\JWT;
use Dotenv\Dotenv;
use Predis\Client as PredisClient;

use function telesign\sdk\util\randomWithNDigits;

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

$app->group('/api/authenticate', function () use ($app) {
  $app->get('/send_otp', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $phone = $params['phone'];
    $otp = randomWithNDigits(5);

    $telesign_response = (
      new MessagingClient(getenv('TELESIGN_CUSTOMER_ID'), getenv('TELESIGN_SECRET_KEY'), getenv('TELESIGN_REST_URL'))
    )->message($phone, "Your OTP is $otp.", 'OTP', [
      'account_lifecycle_event' => 'sign-in'
    ]);

    if ($telesign_response->status_code != 200 or !isset($telesign_response->json->reference_id)) {
      return $response->withJson([
        'error' => "$telesign_response->status_code: $telesign_response->body"
      ]);
    }

    try {
      $redis = new PredisClient(getenv('REDIS_URL'));
      $redis->set($telesign_response->json->reference_id, $otp);
      $redis->expire($telesign_response->json->reference_id, 60);
    }
    catch (\Exception $e) {
      return $response->withJson([
        'error' => 'Problem accessing storage'
      ]);
    }

    $jwt = JWT::encode([
      'sub' => $phone,
      'reference_id' => $telesign_response->json->reference_id,
      'exp' => strtotime('1 minute')
    ], getenv('SECRET_KEY'));

    return $response->withJson([
      'authorization_code' => $jwt // Anyone w/o this won't be able to sign in
    ]);
  });

  $app->get('/verify_otp', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $user_supplied_otp = $params['otp'];

    try {
      $decoded = JWT::decode($params['authorization_code'], getenv('SECRET_KEY'), ['HS256']);
    }
    catch (\Exception $e) {
      return $response->withJson([
        'error' => 'Bad authorization code'
      ]);
    }

    try {
      $otp = (new PredisClient(getenv('REDIS_URL')))->get($decoded->reference_id);
    }
    catch (\Exception $e) {
      return $response->withJson([
        'error' => 'Problem accessing storage'
      ]);
    }

    if ($otp === null or $user_supplied_otp != $otp) {
      return $response->withJson([
        'error' => "Could not verify your OTP"
      ]);
    }

    $jwt = JWT::encode([
      'sub' => $decoded->sub,
      'exp' => strtotime('5 minutes')
    ], getenv('SECRET_KEY'));

    return $response->withJson([
      'id_token' => $jwt,
      'token_type' => 'Bearer',
      'phone' => $decoded->sub
    ]);
  });
})->add(function (Request $request, Response $response, callable $next) {
  $dotenv = new Dotenv(__DIR__ . '/..');
  $dotenv->load();

  try {
    $dotenv->required('SECRET_KEY')->notEmpty();
    $dotenv->required('REDIS_URL')->notEmpty();
  }
  catch (\Exception $e) {
    return $response->withJson([
      'error' => $e->getMessage()
    ]);
  }

  return $next($request, $response);
});

$app->group('/api/protected', function () use ($app) {
  $app->get('/albums', function (Request $request, Response $response) {
    return $response
      ->withHeader('Content-Type', 'application/json')
      ->write(file_get_contents(__DIR__ . '/../albums.json'));
  });
})->add(function (Request $request, Response $response, callable $next) {
  (new Dotenv(__DIR__ . '/..'))->load();

  $authorization_header = $request->getHeader('Authorization');

  if (count($authorization_header) == 0) {
    return $response->withJson([
      'error' => 'Missing authorization header'
    ]);
  }

  $id_token = preg_replace('/^Bearer (.+)$/', '$1', $authorization_header[0]);

  try {
    JWT::decode($id_token, getenv('SECRET_KEY'), ['HS256']);
  }
  catch (\Exception $e) {
    return $response->withJson([
      'error' => 'Bad authorization header'
    ]);
  }

  return $next($request, $response);
});

$app->run();
