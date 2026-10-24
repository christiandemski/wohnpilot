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
use Wohnpilot\Auth\BearId;
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
            // Which ways to sign in the login page offers.
            $auth->get('/methods', fn (Request $r, Response $response) => self::json($response, [
                'bearid' => $services->bearId() !== null,
                'password' => true,
                'redirect' => $services->redirectLogin() !== null ? 'dev' : null,
            ]));

            // BearID: take the person from the bearid cookie, or go to BearID and come back here once.
            $auth->get('/bearid', function (Request $request, Response $response) use ($services): Response {
                $bearId = $services->bearId() ?? throw AppException::notFound('BearID login');
                try {
                    $identity = $bearId->identity($request->getCookieParams());
                } catch (LoginFailed) {
                    return $response->withHeader('Location', '/?login=nicht-freigegeben')->withStatus(302);
                }
                if ($identity === null) {
                    $target = isset($request->getQueryParams()['zurueck'])
                        ? '/?login=fehlgeschlagen'
                        : $bearId->loginUrl($services->appUrl() . '/api/auth/bearid?zurueck=1');
                    return $response->withHeader('Location', $target)->withStatus(302);
                }
                $services->notes()->refresh();
                if ($services->household()->userFor($identity) === null) {
                    return $response->withHeader('Location', '/?login=kein-mitglied')->withStatus(302);
                }
                self::startSession($services, $identity);
                return $response->withHeader('Location', '/')->withStatus(302);
            });

            $auth->post('/password', function (Request $request, Response $response) use ($services): Response {
                $body = self::body($request);
                $email = trim((string) ($body['email'] ?? ''));
                $password = (string) ($body['password'] ?? '');
                if ($email === '' || $password === '') {
                    throw AppException::validation(array_filter(['email' => $email === '' ? 'required' : null, 'password' => $password === '' ? 'required' : null]));
                }
                $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
                try {
                    $identity = $services->accounts()->verify($email, $password, $ip);
                } catch (LoginFailed $e) {
                    throw $e->getMessage() === 'rate_limited'
                        ? new AppException('rate_limited', 429, 'Too many failed attempts')
                        : new AppException('invalid_login', 401, 'E-mail or password wrong');
                }
                $services->notes()->refresh();
                $user = $services->household()->userFor($identity);
                if ($user === null) {
                    throw new AppException('forbidden', 403, 'Not a member of this household');
                }
                self::startSession($services, $identity);
                return self::json($response, ['user' => $user->toArray()]);
            });

            $auth->get('/login', function (Request $request, Response $response) use ($services): Response {
                $login = $services->redirectLogin() ?? throw AppException::notFound('Redirect login');
                return $response->withHeader('Location', $login->start($services->session()))->withStatus(302);
            });
            $auth->get('/callback', function (Request $request, Response $response) use ($services): Response {
                $session = $services->session();
                $login = $services->redirectLogin() ?? throw AppException::notFound('Redirect login');
                try {
                    $identity = $login->finish($session, $request->getQueryParams());
                } catch (LoginFailed $e) {
                    error_log('Wohnpilot login failed: ' . $e->getMessage());
                    return $response->withHeader('Location', '/?login=fehlgeschlagen')->withStatus(302);
                }
                self::startSession($services, $identity);
                return $response->withHeader('Location', '/')->withStatus(302);
            });

            // While a BearID cookie is valid it would sign you in again at once,
            // so logging out also leads through BearID's logout.
            $auth->post('/logout', function (Request $request, Response $response) use ($services): Response {
                $services->session()->destroy();
                $redirect = null;
                $bearId = $services->bearId();
                if ($bearId !== null && $bearId->verify((string) ($request->getCookieParams()[BearId::COOKIE] ?? '')) !== null) {
                    $redirect = $bearId->logoutUrl($services->appUrl() . '/');
                }
                return self::json($response, ['redirect' => $redirect]);
            });
        });

        $app->group('/api', function (RouteCollectorProxy $api) use ($services): void {
            $api->get('/me', fn (Request $request, Response $response) => self::json($response, ['user' => self::user($request)->toArray()]));

            $api->get('/overview', function (Request $r, Response $response) use ($services): Response {
                $overview = $services->meters()->overview();
                return self::json($response, $overview + $services->agenda()->forOverview($overview['meters']));
            });
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
            $api->get('/contracts', fn (Request $r, Response $response) => self::json($response, ['contracts' => $services->contracts()->list()]));
            $api->post('/contracts', fn (Request $request, Response $response) => self::json(
                $response,
                $services->contracts()->create(self::body($request), self::user($request)),
                201,
            ));
            $api->get('/contracts/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->contracts()->detail($args['id'])));
            $api->put('/contracts/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->contracts()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            $api->post('/contracts/{id}/prices', fn (Request $request, Response $response, array $args) => self::json(
                $response,
                $services->contracts()->addPrice($args['id'], self::body($request), self::user($request)),
                201,
            ));

            $api->get('/lease', fn (Request $r, Response $response) => self::json($response, ['lease' => $services->lease()->get()]));
            $api->post('/lease', fn (Request $request, Response $response) => self::json(
                $response,
                ['lease' => $services->lease()->create(self::body($request), self::user($request))],
                201,
            ));
            $api->put('/lease', function (Request $request, Response $response) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, ['lease' => $services->lease()->update($changes, $original, $etag, self::user($request))]);
            });
            $api->post('/lease/rent', fn (Request $request, Response $response) => self::json(
                $response,
                ['lease' => $services->lease()->addRentChange(self::body($request), self::user($request))],
                201,
            ));
            $api->put('/lease/rent/{validFrom}/agreed', function (Request $request, Response $response, array $args) use ($services): Response {
                $date = self::body($request)['datum'] ?? null;
                return self::json($response, ['lease' => $services->lease()->agree($args['validFrom'], is_string($date) ? $date : null, self::user($request))]);
            });

            $api->get('/deadlines', fn (Request $r, Response $response) => self::json($response, $services->deadlines()->list()));
            $api->post('/deadlines', fn (Request $request, Response $response) => self::json(
                $response,
                $services->deadlines()->create(self::body($request), self::user($request)),
                201,
            ));
            $api->get('/deadlines/{id}', fn (Request $r, Response $response, array $args) => self::json($response, $services->deadlines()->detail($args['id'])));
            $api->put('/deadlines/{id}', function (Request $request, Response $response, array $args) use ($services): Response {
                [$changes, $original, $etag] = self::merge($request);
                return self::json($response, $services->deadlines()->update($args['id'], $changes, $original, $etag, self::user($request)));
            });
            $api->put('/deadlines/{id}/done', function (Request $request, Response $response, array $args) use ($services): Response {
                $date = self::body($request)['datum'] ?? null;
                return self::json($response, $services->deadlines()->setDone($args['id'], is_string($date) ? $date : null, self::user($request)));
            });

            $api->post('/sync', function (Request $r, Response $response) use ($services): Response {
                $services->notes()->refresh(true);
                return $response->withStatus(204);
            });
        })->add(function (Request $request, Handler $handler) use ($services): Response {
            $identity = $services->session()->get('identity');
            $identity = is_array($identity) ? Identity::fromArray($identity) : null;
            if ($identity === null && $services->bearId() !== null) {
                // Signed in at BearID already: no click needed.
                try {
                    $identity = $services->bearId()->identity($request->getCookieParams());
                } catch (LoginFailed) {
                    throw new AppException('not_released', 403, 'Wohnpilot is not released for this BearID account');
                }
            }
            if ($identity === null) {
                throw new AppException('unauthorized', 401, 'Not signed in');
            }
            $services->notes()->refresh();
            $user = $services->household()->userFor($identity);
            if ($user !== null && $services->session()->get('identity') === null) {
                self::startSession($services, $identity);
            }
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

    private static function startSession(Services $services, Identity $identity): void
    {
        $session = $services->session();
        $session->regenerate();
        $session->set('identity', $identity->toArray());
    }

    private static function user(Request $request): User
    {
        return $request->getAttribute('user');
    }

    /** @return array{array<string, mixed>, array<string, mixed>, string} changes, original, etag of a three-way merge */
    private static function merge(Request $request): array
    {
        $body = self::body($request);
        return [(array) ($body['changes'] ?? []), (array) ($body['original'] ?? []), (string) ($body['etag'] ?? '')];
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
