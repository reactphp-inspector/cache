<?php

declare(strict_types=1);

namespace ReactInspector\Cache;

use Composer\InstalledVersions;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use React\Cache\CacheInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function array_key_exists;
use function array_keys;
use function array_values;
use function implode;
use function is_array;
use function is_string;
use function OpenTelemetry\Instrumentation\hook;
use function sprintf;

final class ReactCacheInstrumentation
{
    private const int FIRST_ARGUMENT = 0;

    /** @phpstan-ignore shipmonk.deadConstant */
    public const string NAME = 'reactphp';

    /**
     * The name of the Composer package.
     *
     * @see https://getcomposer.org/doc/04-schema.md#name
     */
    private const string COMPOSER_NAME = 'react-inspector/cache';

    /**
     * Name of this instrumentation library which provides the instrumentation for Bunny.
     *
     * @see https://opentelemetry.io/docs/specs/otel/glossary/#instrumentation-library
     */
    private const string INSTRUMENTATION_LIBRARY_NAME = 'io.opentelemetry.contrib.php.react-cache';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            self::INSTRUMENTATION_LIBRARY_NAME,
            InstalledVersions::getPrettyVersion(self::COMPOSER_NAME),
            Version::VERSION_1_32_0->url(),
        );

        $pre  = static function (CacheInterface $cache, array $params, string $class, string $function, string|null $filename, int|null $lineno) use ($instrumentation): void {
            $builder = self::makeSpanBuilder($instrumentation, $function, $function, $class, $filename, $lineno);

            if (array_key_exists(self::FIRST_ARGUMENT, $params) && is_string($params[self::FIRST_ARGUMENT])) {
                $builder->setAttribute('cache.key', $params[self::FIRST_ARGUMENT]);
            }

            if (array_key_exists(self::FIRST_ARGUMENT, $params) && is_array($params[self::FIRST_ARGUMENT])) {
                $keys = array_values($params[self::FIRST_ARGUMENT]) !== $params[self::FIRST_ARGUMENT] ? array_keys($params[self::FIRST_ARGUMENT]) : $params[self::FIRST_ARGUMENT];
                $builder->setAttribute('cache.keys', implode(',', $keys));
            }

            $parent = Context::getCurrent();
            $span   = $builder->startSpan();

            Context::storage()->attach($span->storeInContext($parent));
        };
        $post = static function (
            CacheInterface $cache,
            array $params,
            PromiseInterface $promise,
        ): PromiseInterface {
            $scope = Context::storage()->scope();
            if (! $scope instanceof ContextStorageScopeInterface) {
                return $promise;
            }

            $span = Span::fromContext($scope->context());
            if (! $span->isRecording()) {
                return $promise;
            }

            return $promise->then(static function (mixed $data) use ($span, $scope): mixed {
                $scope->detach();
                $span->end();

                return $data;
            }, static function (Throwable $exception) use ($span, $scope): never {
                $scope->detach();
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                $span->end();

                /** @phpstan-ignore shipmonk.checkedExceptionInCallable */
                throw $exception;
            });
        };

        foreach (['get', 'set', 'delete', 'clear', 'getMultiple', 'setMultiple', 'deleteMultiple', 'has'] as $f) {
            hook(class: CacheInterface::class, function: $f, pre: $pre, post: $post);
        }
    }

    private static function makeSpanBuilder(
        CachedInstrumentation $instrumentation,
        string $name,
        string $function,
        string $class,
        string|null $filename,
        int|null $lineno,
    ): SpanBuilderInterface {
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
            ->setAttribute('cache.operation', $name);
    }
}
