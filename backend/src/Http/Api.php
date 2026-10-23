<?php

declare(strict_types=1);

namespace Wohnpilot\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Throwable;
use Wohnpilot\App\AppException;
use Wohnpilot\App\User;
use Wohnpilot\Auth\Identity;
use Wohnpilot\Auth\LoginFailed;
use Wohnpilot\Services;
use Wohnpilot\Storage\StorageException;

/**
 * The JSON API under /api. Everything except /api/auth/* needs a signed-in
 * person of the household. Writing requests must carry the header
 * "X-Requested-With: wohnpilot", which other sites cannot send without CORS.
 */
final class Api
{
    public static function create(Services $services): App
    {
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $app->group('/api/auth', function (RouteCollectorProxy $auth) use ($services): void {
            $auth->get('/login', function (Request $request, Response $response) use ($services): Response {
                return $response->withHeader('Location', $services->login()->start($services->session()))->withStatus(302);
            });
            $auth->get('/callback', function (Request $request, Response $response) use ($services): Response {
                $session = $services->session();
                try {
                    $identity = $services->login()->finish($session, $request->getQueryParams());
                } catch (LoginFailed $e) {
                    error_log('Wohnpilot login failed: ' . $e->getMessage());
                    return $response->withHeader('Location', '/?login=fehlgeschlagen')->withStatus(302);
                }
                $session->regenerate();
                $session->set('identity', $identity->toArray());
                return $response->withHeader('Location', '/')->withStatus(302);
            });
            $auth->post('/logout', function (Request $request, Response $response) use ($services): Response {
                $services->session()->destroy();
                return $response->withStatus(204);
            });
        });

        $app->group('/api', function (RouteCollectorProxy $api) use ($services): void {
            $api->get('/me', fn (Request $request, Response $response) => self::json($response, ['user' => self::user($request)->toArray()]));

            $api->get('/overview', fn (Request $r, Response $response) => self::json($response, $services->meters()->overview()));
            $api->get('/meters', fn (Request $r, Response $response) => self::json($response, ['meters' => $services->meters()->list()]));
            $api->post('/meters', fn (Request $request, Response $response) => self::json(
                $response,
                $services->meters()->create(self::body($request), self::user($request)),
                201,
            ));
            $api->get('/meters/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->meters()->detail($args['id'])));
            $api->put('/meters/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $body = self::body($request);
                return self::json($response, $services->meters()->update(
                    $args['id'],
                    (array) ($body['changes'] ?? []),
                    (array) ($body['original'] ?? []),
                    (string) ($body['etag'] ?? ''),
                    self::user($request),
                ));
            });
            $api->post('/meters/{id}/readings', fn (Request $request, Response $response, array $args) => self::json(
                $response,
                $services->meters()->addReading($args['id'], self::body($request), self::user($request)),
                201,
            ));

            $api->get('/objects/active', fn (Request $r, Response $response) => self::json($response, ['object' => $services->household()->activeObject()]));
            $api->post('/objects', fn (Request $request, Response $response) => self::json(
                $response,
                ['object' => $services->household()->createObject(self::body($request), self::user($request))],
                201,
            ));
            $api->put('/objects/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                $body = self::body($request);
                return self::json($response, ['object' => $services->household()->updateObject(
                    $args['id'],
                    (array) ($body['changes'] ?? []),
                    (array) ($body['original'] ?? []),
                    (string) ($body['etag'] ?? ''),
                    self::user($request),
                )]);
            });
            $api->post('/sync', function (Request $r, Response $response) use ($services): Response {
                $services->notes()->refresh(true);
                return $response->withStatus(204);
            });
        })->add(function (Request $request, Handler $handler) use ($services): Response {
            $identity = $services->session()->get('identity');
            if (!is_array($identity)) {
                throw new AppException('unauthorized', 401, 'Not signed in');
            }
            $services->notes()->refresh();
            $user = $services->household()->userFor(Identity::fromArray($identity));
            if ($user === null) {
                throw new AppException('forbidden', 403, 'Not a member of this household');
            }
            return $handler->handle($request->withAttribute('user', $user));
        });

        $app->add(function (Request $request, Handler $handler): Response {
            $writes = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
            if ($writes && $request->getHeaderLine('X-Requested-With') !== 'wohnpilot') {
                throw new AppException('forbidden', 403, 'Missing X-Requested-With header');
            }
            return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
        });

        $errors = $app->addErrorMiddleware($services->config->isDev(), true, true);
        $errors->setDefaultErrorHandler(function (Request $request, Throwable $e) use ($app): Response {
            [$status, $body] = match (true) {
                $e instanceof AppException => [$e->status, ['error' => $e->errorCode, 'message' => $e->getMessage()] + $e->details],
                $e instanceof StorageException => [503, ['error' => 'storage_unavailable', 'message' => $e->getMessage()]],
                $e instanceof \Slim\Exception\HttpNotFoundException => [404, ['error' => 'not_found']],
                $e instanceof \Slim\Exception\HttpMethodNotAllowedException => [405, ['error' => 'method_not_allowed']],
                $e instanceof \JsonException, $e instanceof \Slim\Exception\HttpBadRequestException => [400, ['error' => 'bad_request']],
                default => [500, ['error' => 'internal']],
            };
            if ($status >= 500) {
                error_log('Wohnpilot: ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            return self::json($app->getResponseFactory()->createResponse(), $body, $status);
        });

        return $app;
    }

    private static function user(Request $request): User
    {
        return $request->getAttribute('user');
    }

    private static function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new AppException('bad_request', 400, 'Expected a JSON object');
        }
        return $body;
    }

    private static function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
