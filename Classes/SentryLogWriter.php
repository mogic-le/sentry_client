<?php

namespace Networkteam\SentryClient;

use Networkteam\SentryClient\Service\ConfigurationService;
use Networkteam\SentryClient\Service\SentryService;
use Networkteam\SentryClient\Trait\IgnoreMessage;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Stacktrace;
use Sentry\State\Scope;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\AbstractWriter;
use function Sentry\withScope;

class SentryLogWriter extends AbstractWriter
{
    use IgnoreMessage;

    protected const SOURCE_TAG = 'source';

    protected const SOURCE_IDENTIFIER = 'logwriter';

    public function __construct(array $options = [])
    {
        SentryService::inititalize();
        parent::__construct($options);
    }

    /**
     * Forwards the log record to Sentry
     *
     * @return \TYPO3\CMS\Core\Log\Writer\WriterInterface $this
     */
    public function writeLog(LogRecord $record)
    {
        if (SentryService::isEnabled()
            && $this->shouldHandleLogMessage($record)
        ) {
            $data = $record->getData();
            if (isset($data['exception']) && $data['exception'] instanceof \Throwable) {
                $this->writeException($record);
                return $this;
            }

            withScope(function (Scope $scope) use ($record): void {
                $scope->setExtra('component', $record->getComponent());
                if ($record->getData()) {
                    $scope->setExtra('data', $record->getData());
                }
                $scope->setTag(self::SOURCE_TAG, self::SOURCE_IDENTIFIER);
                $scope->setFingerprint([
                    $record->getMessage(),
                    $record->getComponent()
                ]);

                $message = $record->getMessage();
                if (method_exists($this, 'interpolate')) {
                    $message = $this->interpolate($message, $record->getData());
                }

                Client::captureMessage($message, $record->getLevel());
            });
        }

        return $this;
    }

    /**
     * Report a log record with an 'exception' object in its data.
     *
     * Exception objects in Sentry data properties do not get serialized
     * and thus get lost.
     * To keep them and their stacktrace, we send it as exception to Sentry.
     * The log message is kept as breadcrumb.
     *
     * TYPO3 scheduler logs such messages when tasks fail.
     */
    protected function writeException(LogRecord $record): void
    {
        $data = $record->getData();
        $exception = $data['exception'];
        unset($data['exception']);

        withScope(function (Scope $scope) use ($data, $exception, $record): void {
            $scope->setExtra('component', $record->getComponent());
            $scope->setExtra('data', $data);

            $message = $record->getMessage();
            if (method_exists($this, 'interpolate')) {
                $message = $this->interpolate($message, $record->getData());
            }
            $scope->addBreadcrumb(new Breadcrumb('error', 'default', 'log message', $message, []));

            Client::captureException($exception);
        });
    }

    protected function shouldHandleLogMessage(LogRecord $logRecord): bool
    {
        if ($this->shouldIgnoreMessage($logRecord->getMessage())) {
            return false;
        }

        $componentIgnorelist = array_merge([
            'TYPO3.CMS.Frontend.ContentObject.Exception.ProductionExceptionHandler',
            'TYPO3.CMS.Core.Error.ErrorHandler',
            'Networkteam.SentryClient.ProductionExceptionHandler'
        ], ConfigurationService::getLogWriterComponentIgnorelist());

        foreach ($componentIgnorelist as $componentInIgnorelist) {
            if (str_starts_with($logRecord->getComponent() . '.', $componentInIgnorelist . '.')) {
                return false;
            }
        }

        return true;
    }

    public static function cleanupStacktrace(Event $event): Event
    {
        if (($event->getTags()[self::SOURCE_TAG] ?? false) === self::SOURCE_IDENTIFIER) {
            $stacktrace = $event->getStacktrace();
            if ($stacktrace instanceof Stacktrace) {
                foreach($stacktrace->getFrames() as $no => $frame) {
                    if (str_starts_with($frame->getFunctionName() ?? '', 'Psr\Log\AbstractLogger::')
                        || str_starts_with($frame->getFunctionName() ?? '', 'TYPO3\CMS\Core\Log\Logger::')
                    ) {
                        $stacktraceBeforeLogCall = new Stacktrace(array_slice($stacktrace->getFrames(), 0, $no));
                        $event->setStacktrace($stacktraceBeforeLogCall);
                        break;
                    }
                }
            }
        }

        return $event;
    }
}
