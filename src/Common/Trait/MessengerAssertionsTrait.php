<?php
declare(strict_types=1);

namespace EonX\EasyTest\Common\Trait;

use PHPUnit\Framework\Constraint\IsEqual;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * @mixin \EonX\EasyTest\Common\Trait\ExceptionTrait
 * @mixin \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait MessengerAssertionsTrait
{
    /**
     * Asserts that the given count of messages was sent to async transport.
     *
     * @param class-string|null $messageClass
     */
    public static function assertCountOfMessagesSentToAsyncTransport(int $count, ?string $messageClass = null): void
    {
        self::assertCount($count, self::getMessagesSentToAsyncTransport($messageClass));
    }

    /**
     * Asserts that the given count of messages was sent to failed transport.
     *
     * @param class-string|null $messageClass
     */
    public static function assertCountOfMessagesSentToFailedTransport(int $count, ?string $messageClass = null): void
    {
        self::assertCount($count, self::getMessagesSentToFailedTransport($messageClass));
    }

    /**
     * Asserts that the given count of messages was sent to transport.
     *
     * @param class-string|null $messageClass
     */
    public static function assertCountOfMessagesSentToTransport(
        int $count,
        string $transportName,
        ?string $messageClass = null,
    ): void {
        self::assertCount($count, self::getMessagesSentToTransport($transportName, $messageClass));
    }

    /**
     * Asserts that the given message class was dispatched by the message bus to async transport.
     *
     * @param class-string $messageClass
     */
    public static function assertMessageSentToAsyncTransport(
        string $messageClass,
        array $expectedProperties = [],
        int $messagesCount = 1,
    ): void {
        self::assertMessageSentToTransport($messageClass, 'async', $expectedProperties, $messagesCount);
    }

    /**
     * Asserts that the given message class was dispatched by the message bus to failed transport.
     *
     * @param class-string $messageClass
     */
    public static function assertMessageSentToFailedTransport(
        string $messageClass,
        array $expectedProperties = [],
        int $messagesCount = 1,
    ): void {
        self::assertMessageSentToTransport($messageClass, 'failed', $expectedProperties, $messagesCount);
    }

    /**
     * Asserts that the given message class was dispatched by the message bus.
     *
     * @param class-string $messageClass
     */
    public static function assertMessageSentToTransport(
        string $messageClass,
        string $transportName,
        array $expectedProperties = [],
        int $messagesCount = 1,
    ): void {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        $envelopes = \array_filter(
            self::getMessagesSentToTransport($transportName),
            static function (object $message) use ($messageClass, $expectedProperties, $propertyAccessor): bool {
                if ($message instanceof $messageClass === false) {
                    return false;
                }

                foreach ($expectedProperties as $property => $expectedValue) {
                    $actualValue = $propertyAccessor->getValue($message, $property);

                    $isEqualConstraint = new IsEqual($expectedValue);
                    if ($isEqualConstraint->evaluate($actualValue, '', true) === false) {
                        return false;
                    }
                }

                return true;
            }
        );

        self::assertCount($messagesCount, $envelopes);
    }

    /**
     * @param array<int, class-string<\Throwable>|array<class-string<\Throwable>, int|string>> $expectedExceptions
     * @param array<int|float> $expectedDelays Expected delays in seconds between worker runs
     */
    public static function consumeAsyncMessages(
        array $expectedExceptions = [],
        array $expectedDelays = [],
    ): void {
        self::consumeMessages($expectedExceptions, $expectedDelays, ['async']);
    }

    /**
     * @param array<int, class-string<\Throwable>|array<class-string<\Throwable>, int|string>> $expectedExceptions
     * @param array<int|float> $expectedDelays Expected delays in seconds between worker runs
     * @param array<int, string> $transports Transports to consume messages from
     */
    public static function consumeMessages(
        array $expectedExceptions = [],
        array $expectedDelays = [],
        array $transports = [],
    ): void {
        /**
         * If some exception occurs during message handling, it will be caught by Symfony Messenger Worker,
         * and the message will be sent to failed transport.
         *
         * After consuming messages we do some checks that can throw `Runtime` exceptions.
         *
         * If some exception is thrown, it will be caught by safeCall() method, and the test will be marked as passed,
         * but it should be marked as failed.
         */
        if (isset(self::$isInsideSafeCall) && self::$isInsideSafeCall) {
            throw new RuntimeException(
                "You can't use MessengerAssertionsTrait::consumeAsyncMessages() in ExceptionTrait::safeCall()"
            );
        }

        $realDelays = \array_map(static fn (int|float $value): float => $value + 0.000001, $expectedDelays ?? []);

        self::runMessengerWorker($transports, 'default', $expectedExceptions, $realDelays);
    }

    /**
     * Returns dispatched messages by the message bus to async transport.
     *
     * @template TMessageClass
     *
     * @param class-string<TMessageClass>|null $messageClass
     *
     * @return ($messageClass is null ? array<int, object> : array<int, TMessageClass>)
     */
    public static function getMessagesSentToAsyncTransport(?string $messageClass = null): array
    {
        return self::getMessagesSentToTransport('async', $messageClass);
    }

    /**
     * Returns dispatched messages by the message bus to failed transport.
     *
     * @template TMessageClass
     *
     * @param class-string<TMessageClass>|null $messageClass
     *
     * @return ($messageClass is null ? array<int, object> : array<int, TMessageClass>)
     */
    public static function getMessagesSentToFailedTransport(?string $messageClass = null): array
    {
        return self::getMessagesSentToTransport('failed', $messageClass);
    }

    /**
     * Returns dispatched messages by the message bus to async transport.
     *
     * @template TMessageClass
     *
     * @param class-string<TMessageClass>|null $messageClass
     *
     * @return ($messageClass is null ? array<int, object> : array<int, TMessageClass>)
     */
    public static function getMessagesSentToTransport(string $transportName, ?string $messageClass = null): array
    {
        /** @var \Symfony\Component\Messenger\Transport\TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.' . $transportName);

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($messageClass === null || $message instanceof $messageClass) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param \Symfony\Component\Messenger\Transport\TransportInterface[] $transports
     */
    private static function countMessagesInTransports(array $transports): int
    {
        return \array_reduce(
            $transports,
            static fn (int $count, TransportInterface $transport): int => $count + \count((array)$transport->get()),
            0
        );
    }

    private static function printExceptionDetails(ErrorDetailsStamp $errorDetailsStamp): void
    {
        echo "\n";
        echo "\033[31mAn unexpected exception occurred while processing the message.\033[0m\n";
        echo "\n";
        echo 'Exception class: ' . $errorDetailsStamp->getExceptionClass() . "\n";
        echo 'Exception code: ' . $errorDetailsStamp->getExceptionCode() . "\n";
        echo 'Exception message: ' . $errorDetailsStamp->getExceptionMessage() . "\n";
        echo "Stack trace:\n";
        echo $errorDetailsStamp->getFlattenException() !== null
            ? $errorDetailsStamp->getFlattenException()
                ->getTraceAsString()
            : 'No stack trace available.';
        echo "\n";
    }

    /**
     * @param array<string> $transportNames
     * @param array<int, class-string<\Throwable>|array<class-string<\Throwable>, int|string>> $expectedExceptions
     */
    private static function runMessengerWorker(
        array $transportNames,
        string $busName,
        array $expectedExceptions = [],
        array $expectedDelays = [],
    ): void {
        /** @var \Symfony\Component\Messenger\Transport\TransportInterface[] $transports */
        $transports = [];
        foreach ($transportNames ?: ['async'] as $transportName) {
            $transports[$transportName] = self::getContainer()->get('messenger.transport.' . $transportName);
        }
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        /** @var \Symfony\Component\Messenger\MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get('messenger.bus.' . $busName);

        $tries = 0;
        $messageLimitListener = null;
        $stopWorkerOnTimeLimitListener = new StopWorkerOnTimeLimitListener(2);
        $eventDispatcher->addSubscriber($stopWorkerOnTimeLimitListener);
        /** @var \Symfony\Component\Messenger\EventListener\ResetServicesListener $resetServicesListener */
        $resetServicesListener = self::getContainer()->get('messenger.listener.reset_services');
        $eventDispatcher->addSubscriber($resetServicesListener);
        while (++$tries < 10) {
            $messagesCount = self::countMessagesInTransports($transports);

            if ($messagesCount === 0) {
                break;
            }

            if ($messageLimitListener !== null) {
                $eventDispatcher->removeSubscriber($messageLimitListener);
            }

            $messageLimitListener = new StopWorkerOnMessageLimitListener($messagesCount);
            $eventDispatcher->addSubscriber($messageLimitListener);
            $clock = Clock::get();

            $worker = new Worker($transports, $messageBus, $eventDispatcher, clock: $clock);
            $worker->run();

            if (isset($expectedDelays[$tries - 1])) {
                $clock->sleep($expectedDelays[$tries - 1]);
            }
        }

        $eventDispatcher->removeSubscriber($stopWorkerOnTimeLimitListener);
        $eventDispatcher->removeSubscriber($resetServicesListener);
        if ($messageLimitListener !== null) {
            $eventDispatcher->removeSubscriber($messageLimitListener);
        }

        // Message handler may dispatch new messages to specified transports and this can happen multiple times.
        // We need to consume all messages from specified transports to make sure that all messages were processed.
        // By default, we try to consume messages from specified transports 10 times, and this is enough for most cases.
        // If we don't do this, we may get false positive results
        if (self::countMessagesInTransports($transports) > 0) {
            throw new RuntimeException('Unable to consume all messages from specified transports.');
        }

        // Symfony Messenger does not throw exceptions when message handler throws an exception.
        // Instead, it stores exception details in ErrorDetailsStamp, and we need to check it manually.
        // We can't throw this exception, because it stored as \Symfony\Component\ErrorHandler\Exception\FlattenException,
        // and we can't get original exception
        foreach ($transports as $transport) {
            foreach ($transport->getRejected() as $envelope) {
                /** @var \Symfony\Component\Messenger\Stamp\ErrorDetailsStamp $errorDetailsStamp */
                foreach ($envelope->all(ErrorDetailsStamp::class) as $errorDetailsStamp) {
                    foreach ($expectedExceptions as $key => $expectedExceptionPairOrClass) {
                        // If $expectedExceptionPairOrClass is a string = exceptionClass
                        if ($expectedExceptionPairOrClass === $errorDetailsStamp->getExceptionClass() &&
                            $errorDetailsStamp->getExceptionCode() === 0
                        ) {
                            unset($expectedExceptions[$key]);

                            continue 2;
                        }
                        // If $expectedExceptionPairOrClass is an array{exceptionClass => exceptionCode}
                        if (
                            isset($expectedExceptionPairOrClass[$errorDetailsStamp->getExceptionClass()])
                            && (
                                $expectedExceptionPairOrClass[$errorDetailsStamp->getExceptionClass()]
                                === $errorDetailsStamp->getExceptionCode()
                            )
                        ) {
                            unset($expectedExceptions[$key]);

                            continue 2;
                        }
                    }

                    self::printExceptionDetails($errorDetailsStamp);

                    throw new RuntimeException('Exception was thrown during messages processing.');
                }
            }
        }

        if (\count($expectedExceptions) > 0) {
            $exceptions = \array_map(
                static fn (array $expectedExceptionPair): string => \key($expectedExceptionPair),
                $expectedExceptions
            );

            throw new RuntimeException(
                "The following exceptions were expected but not thrown: \n - "
                . \implode("\n - ", $exceptions)
            );
        }
    }
}
