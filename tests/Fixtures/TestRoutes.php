<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Fixtures;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\App;
use PDOException;
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Response\BuildResponse;
use RoayaPay\PlatformCore\Transactions\IdempotencyManager;
use RoayaPay\PlatformCore\Validation\ApiValidator;
use RuntimeException;

final class TestRoutes
{
    public const string SECRET_MESSAGE = 'SQLSTATE[42S02] select * from users where password = hunter2 in /var/www/roayapay/app/Secret.php db_password=Sup3rS3cret';

    public static function register(Router $router): void
    {
        $router->middleware('roayapay.api')->prefix('api')->group(function (Router $router): void {
            $router->get('success', fn () => BuildResponse::setCode('SUCCESS')->setData(['id' => 7, 'amount' => '150.00'])->getResponse());
            $router->get('empty', fn () => BuildResponse::setCode('SUCCESS')->getResponse());
            $router->get('empty-array', fn () => BuildResponse::setCode('SUCCESS')->setData([])->getResponse());
            $router->get('custom-message', fn () => BuildResponse::setCode('INSUFFICIENT_BALANCE')
                ->setData(['available' => '10.00'])
                ->setMessage(['ar' => 'الرصيد غير كافٍ للتحويل', 'en' => 'Balance too low for transfer'])
                ->setStatus(false)
                ->getResponse());
            $router->get('code-err', fn () => BuildResponse::setCode('CODE_ERR')->getResponse());

            $router->get('balance', function () {
                throw RoayaPayException::business('INSUFFICIENT_BALANCE', ['available' => '10.00'])
                    ->withContext(['merchant_id' => 9, 'password' => 'never-logged']);
            });
            $router->get('balance-message', function () {
                throw RoayaPayException::business('INSUFFICIENT_BALANCE', message: ['ar' => 'رسالة مخصصة', 'en' => 'Custom message']);
            });
            $router->get('legacy-constructor', function () {
                throw new RoayaPayException('INSUFFICIENT_BALANCE');
            });
            $router->get('unknown-code', function () {
                throw RoayaPayException::business('NOT_A_REGISTERED_CODE');
            });
            $router->get('merchant-missing', function () {
                throw RoayaPayException::notFound('MERCHANT_NOT_FOUND');
            });
            $router->get('forbidden', function () {
                throw RoayaPayException::forbidden();
            });
            $router->get('provider-down', function () {
                throw RoayaPayException::external('PAYMENT_PROVIDER_UNAVAILABLE');
            });

            $router->get('boom', function () {
                throw new RuntimeException(self::SECRET_MESSAGE);
            });
            $router->get('type-error', function () {
                return strlen([]); // TypeError
            });
            $router->get('query', function () {
                throw new QueryException('testing', 'select * from cards where pan = ?', ['4111111111111111'], new PDOException('SQLSTATE[HY000]: General error: no such table: cards'));
            });
            $router->get('model', function () {
                throw (new ModelNotFoundException())->setModel(FakeMerchant::class, [42]);
            });
            $router->get('auth', function () {
                throw new AuthenticationException('Unauthenticated.');
            });
            $router->get('authz', function () {
                throw new AuthorizationException('This action is unauthorized.');
            });
            $router->get('timeout', function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
            });
            $router->get('refused', function () {
                throw new ConnectionException('cURL error 7: Failed to connect to provider port 443: Connection refused');
            });
            $router->get('abort-418', fn () => abort(418));
            $router->post('only-post', fn () => BuildResponse::setCode('SUCCESS')->getResponse());

            $router->post('register', function (Request $request) {
                $validator = ApiValidator::make($request->all(), [
                    'email' => 'required|email',
                    'password' => 'required|string|min:8',
                ]);

                if ($validator->fails()) {
                    return ApiValidator::getResponse($validator->errors());
                }

                return BuildResponse::setCode('SUCCESS')->getResponse();
            });
            $router->post('register-throw', function (Request $request) {
                ApiValidator::validate($request->all(), ['email' => 'required|email', 'password' => 'required|min:8']);

                return BuildResponse::setCode('SUCCESS')->getResponse();
            });
            $router->post('framework-validate', function (Request $request) {
                $request->validate(['email' => 'required|email', 'amount' => 'required|numeric|between:1,1000']);

                return BuildResponse::setCode('SUCCESS')->getResponse();
            });
            $router->post('form-request', fn (TransferRequest $request) => BuildResponse::setCode('SUCCESS')->setData($request->validated())->getResponse());
            $router->post('rules', function (Request $request) {
                return ApiValidator::getResponse(ApiValidator::make($request->all(), $request->input('_rules', [])));
            });

            $router->get('context', fn () => BuildResponse::setCode('SUCCESS')->setData([
                'language' => app(RequestContext::class)->language,
                'app_locale' => App::getLocale(),
                'request_id' => app(RequestContext::class)->requestId,
            ])->getResponse());

            $router->get('raw-json', fn () => response()->json(['a' => 1]));
            $router->get('raw-array', fn () => ['a' => 1]);
            $router->get('download', fn () => response()->streamDownload(fn () => print ('file-content'), 'report.csv'));
            $router->get('text', fn () => response('plain text', 200, ['Content-Type' => 'text/plain']));
            $router->get('exempt', [ExemptController::class, 'webhook']);
            $router->get('not-exempt', [ExemptController::class, 'normal']);

            $router->post('payments', function (Request $request, IdempotencyManager $idempotency) {
                return $idempotency->run('payments.create', (string) $request->header('Idempotency-Key'), $request->all(), function () use ($request) {
                    PaymentCounter::$calls++;

                    return match ($request->input('outcome')) {
                        'insufficient' => throw RoayaPayException::business('INSUFFICIENT_BALANCE'),
                        'invalid' => throw RoayaPayException::validation(['amount' => ['bad amount']]),
                        'crash' => throw new RuntimeException('provider socket closed'),
                        default => BuildResponse::setCode('SUCCESS')->setData(['payment_id' => 'pay_'.PaymentCounter::$calls])->getResponse(),
                    };
                });
            })->middleware('roayapay.idempotency');
        });

        $router->get('web/boom', function () {
            throw new RuntimeException('web failure');
        });
        $router->get('web/json', fn () => response()->json(['ok' => true]));
        $router->get('web/page', fn () => '<h1>Hello</h1>');
    }
}
