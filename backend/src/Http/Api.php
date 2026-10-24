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

        // Calendar apps cannot sign in: the secret link is the key (CalendarLinks).
        $app->get('/api/calendar/{token:[A-Za-z0-9_-]+}.ics', function (Request $r, Response $response, array $args) use ($services): Response {
            if ($services->calendarLinks()->personFor($args['token']) === null) {
                throw AppException::notFound('Calendar');
            }
            $services->notes()->refresh();
            $agenda = $services->agenda()->forOverview([])['agenda'];
            $response->getBody()->write($services->calendarFeed()->build($agenda, gmdate('Ymd\THis\Z')));
            return $response
                ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
                ->withHeader('Content-Disposition', 'inline; filename="wohnpilot.ics"');
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

            // The person's own calendar link: status, a new one (the old one stops), or none.
            $api->get('/calendar', function (Request $request, Response $response) use ($services): Response {
                $created = $services->calendarLinks()->createdAt(self::user($request)->id);
                return self::json($response, ['active' => $created !== null, 'createdAt' => $created === null ? null : gmdate('c', $created)]);
            });
            $api->post('/calendar', function (Request $request, Response $response) use ($services): Response {
                $token = $services->calendarLinks()->create(self::user($request)->id);
                return self::json($response, ['active' => true, 'createdAt' => gmdate('c'), 'url' => $services->appUrl() . "/api/calendar/$token.ics"], 201);
            });
            $api->delete('/calendar', function (Request $request, Response $response) use ($services): Response {
                $services->calendarLinks()->revoke(self::user($request)->id);
                return $response->withStatus(204);
            });

            // Push: the key for the browser, devices of the signed-in person, a test message.
            $api->get('/push', fn (Request $r, Response $response) => self::json($response, [
                'publicKey' => $services->vapidPublicKey() ?: null,
            ]));
            $api->post('/push/subscriptions', function (Request $request, Response $response) use ($services): Response {
                $body = self::body($request);
                $endpoint = (string) ($body['endpoint'] ?? '');
                $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];
                $encoding = (string) ($body['contentEncoding'] ?? 'aes128gcm');
                if (!preg_match('#^https://[^\s]+$#', $endpoint) || strlen($endpoint) > 1000) {
                    throw AppException::validation(['endpoint' => 'url']);
                }
                if (!is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null) || !in_array($encoding, ['aes128gcm', 'aesgcm'], true)) {
                    throw AppException::validation(['keys' => 'required']);
                }
                $services->pushSubscriptions()->save(self::user($request)->id, $endpoint, $keys['p256dh'], $keys['auth'], $encoding);
                return $response->withStatus(204);
            });
            $api->post('/push/unsubscribe', function (Request $request, Response $response) use ($services): Response {
                $services->pushSubscriptions()->remove((string) (self::body($request)['endpoint'] ?? ''), self::user($request)->id);
                return $response->withStatus(204);
            });
            $api->post('/push/test', function (Request $request, Response $response) use ($services): Response {
                $sender = $services->pushSender() ?? throw new AppException('push_off', 409, 'VAPID keys are not set');
                $subscription = $services->pushSubscriptions()->find((string) (self::body($request)['endpoint'] ?? ''));
                if ($subscription === null || $subscription['person'] !== self::user($request)->id) {
                    throw AppException::notFound('Push subscription');
                }
                $outcome = $sender->send($subscription, ['title' => 'Wohnpilot', 'body' => 'So sehen deine Erinnerungen aus. Sie kommen morgens, wenn eine Frist näher rückt.', 'url' => '/mehr', 'tag' => 'wohnpilot-test']);
                if ($outcome === \Wohnpilot\Push\PushSender::GONE) {
                    $services->pushSubscriptions()->remove($subscription['endpoint']);
                }
                return self::json($response, ['result' => $outcome]);
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
