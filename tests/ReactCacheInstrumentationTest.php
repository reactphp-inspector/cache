<?php

declare(strict_types=1);

namespace ReactInspector\Tests\Cache;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Override;
use PHPUnit\Framework\Attributes\Test;
use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use ReactInspector\Cache\ReactCacheInstrumentation;
use WyriHaximus\TestUtilities\TestCase;

use function assert;

final class ReactCacheInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private CacheInterface $adapter;

    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->storage        = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage),
            ),
        );
        $this->scope          = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withPropagator(new TraceContextPropagator())
            ->activate();
        $this->adapter        = $this->createMemoryCacheAdapter();
    }

    #[Override]
    public function tearDown(): void
    {
        $this->scope->detach();

        parent::tearDown();
    }

    #[Test]
    public function getKey(): void
    {
        self::assertCount(0, $this->storage);
        $this->adapter->get('foo');
        self::assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        self::assertInstanceOf(ImmutableSpan::class, $span);
        self::assertSame('get', $span->getName());
        self::assertSame('get', $span->getAttributes()->get('cache.operation'));
        self::assertSame('foo', $span->getAttributes()->get('cache.key'));
    }

    #[Test]
    public function setKey(): void
    {
        self::assertCount(0, $this->storage);
        $this->adapter->set('foo', 'bar');
        self::assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        self::assertInstanceOf(ImmutableSpan::class, $span);
        self::assertSame('set', $span->getName());
        self::assertSame('set', $span->getAttributes()->get('cache.operation'));
        self::assertSame('foo', $span->getAttributes()->get('cache.key'));
    }

    #[Test]
    public function deleteKey(): void
    {
        self::assertCount(0, $this->storage);
        $this->adapter->delete('foo');
        self::assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        self::assertInstanceOf(ImmutableSpan::class, $span);
        self::assertSame('delete', $span->getName());
        self::assertSame('delete', $span->getAttributes()->get('cache.operation'));
        self::assertSame('foo', $span->getAttributes()->get('cache.key'));
    }

    #[Test]
    public function clearKeys(): void
    {
        self::assertCount(0, $this->storage);
        $this->adapter->clear();
        self::assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        self::assertInstanceOf(ImmutableSpan::class, $span);
        self::assertSame('clear', $span->getName());
        self::assertSame('clear', $span->getAttributes()->get('cache.operation'));
        self::assertNull($span->getAttributes()->get('cache.key'));
        self::assertNull($span->getAttributes()->get('cache.keys'));
    }

    #[Test]
    public function getMultipleKeys(): void
    {
        self::assertCount(0, $this->storage);
        $this->adapter->getMultiple(['foo', 'bar']);
        self::assertCount(3, $this->storage);
        $spanOne = $this->storage->offsetGet(0);
        assert($spanOne instanceof ImmutableSpan);
        $spanTwo = $this->storage->offsetGet(1);
        assert($spanTwo instanceof ImmutableSpan);
        $spanThree = $this->storage->offsetGet(2);
        assert($spanThree instanceof ImmutableSpan);
        self::assertSame('get', $spanOne->getName());
        self::assertSame('get', $spanTwo->getName());
        self::assertSame('getMultiple', $spanThree->getName());
        self::assertSame('get', $spanOne->getAttributes()->get('cache.operation'));
        self::assertSame('get', $spanTwo->getAttributes()->get('cache.operation'));
        self::assertSame('getMultiple', $spanThree->getAttributes()->get('cache.operation'));
        self::assertSame('foo', $spanOne->getAttributes()->get('cache.key'));
        self::assertSame('bar', $spanTwo->getAttributes()->get('cache.key'));
        self::assertSame('foo,bar', $spanThree->getAttributes()->get('cache.keys'));
    }

    #[Test]
    public function setMultipleKeys(): void
    {
        self::assertCount(0, $this->storage);
        $this->adapter->setMultiple(['foo' => 'bar', 'baz' => 'baa']);
        self::assertCount(3, $this->storage);
        $spanOne = $this->storage->offsetGet(0);
        assert($spanOne instanceof ImmutableSpan);
        $spanTwo = $this->storage->offsetGet(1);
        assert($spanTwo instanceof ImmutableSpan);
        $spanThree = $this->storage->offsetGet(2);
        assert($spanThree instanceof ImmutableSpan);
        self::assertSame('set', $spanOne->getName());
        self::assertSame('set', $spanTwo->getName());
        self::assertSame('setMultiple', $spanThree->getName());
        self::assertSame('set', $spanOne->getAttributes()->get('cache.operation'));
        self::assertSame('set', $spanTwo->getAttributes()->get('cache.operation'));
        self::assertSame('setMultiple', $spanThree->getAttributes()->get('cache.operation'));
        self::assertSame('foo', $spanOne->getAttributes()->get('cache.key'));
        self::assertSame('baz', $spanTwo->getAttributes()->get('cache.key'));
        self::assertSame('foo,baz', $spanThree->getAttributes()->get('cache.keys'));
    }

    #[Test]
    public function deleteMultipleKeys(): void
    {
        self::assertCount(0, $this->storage);
        $this->adapter->deleteMultiple(['foo', 'bar']);
        self::assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        self::assertInstanceOf(ImmutableSpan::class, $span);
        self::assertSame('deleteMultiple', $span->getName());
        self::assertSame('deleteMultiple', $span->getAttributes()->get('cache.operation'));
        self::assertSame('foo,bar', $span->getAttributes()->get('cache.keys'));
    }

    #[Test]
    public function hasKey(): void
    {
        self::assertCount(0, $this->storage);
        $this->adapter->has('foo');
        self::assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        self::assertInstanceOf(ImmutableSpan::class, $span);
        self::assertSame('has', $span->getName());
        self::assertSame('has', $span->getAttributes()->get('cache.operation'));
        self::assertSame('foo', $span->getAttributes()->get('cache.key'));
    }

    #[Test]
    public function canRegister(): void
    {
        $this->expectNotToPerformAssertions();

        ReactCacheInstrumentation::register();
    }

    private function createMemoryCacheAdapter(): CacheInterface
    {
        return new ArrayCache();
    }
}
