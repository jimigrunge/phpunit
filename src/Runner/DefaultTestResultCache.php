<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Runner;

use const DIRECTORY_SEPARATOR;
use function assert;
use function defined;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_float;
use function is_string;
use function serialize;
use function sprintf;
use function unserialize;
use PHPUnit\Framework\TestStatus\Error;
use PHPUnit\Framework\TestStatus\Failure;
use PHPUnit\Framework\TestStatus\Incomplete;
use PHPUnit\Framework\TestStatus\Risky;
use PHPUnit\Framework\TestStatus\Skipped;
use PHPUnit\Framework\TestStatus\Success;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\Framework\TestStatus\Unknown;
use PHPUnit\Framework\TestStatus\Warning;
use PHPUnit\Util\Error\Handler;
use PHPUnit\Util\Filesystem;
use Serializable;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class DefaultTestResultCache implements Serializable, TestResultCache
{
    /**
     * @var string
     */
    public const DEFAULT_RESULT_CACHE_FILENAME = '.phpunit.result.cache';

    private string $cacheFilename;

    /**
     * @var array<string, TestStatus>
     */
    private array $defects = [];

    /**
     * @var array<string, float>
     */
    private array $times = [];

    public function __construct(?string $filepath = null)
    {
        if ($filepath !== null && is_dir($filepath)) {
            // cache path provided, use default cache filename in that location
            $filepath .= DIRECTORY_SEPARATOR . self::DEFAULT_RESULT_CACHE_FILENAME;
        }

        $this->cacheFilename = $filepath ?? $_ENV['PHPUNIT_RESULT_CACHE'] ?? self::DEFAULT_RESULT_CACHE_FILENAME;
    }

    /**
     * @throws Exception
     */
    public function persist(): void
    {
        $this->saveToFile();
    }

    /**
     * @throws Exception
     */
    public function saveToFile(): void
    {
        if (defined('PHPUNIT_TESTSUITE_RESULTCACHE')) {
            return;
        }

        if (!Filesystem::createDirectory(dirname($this->cacheFilename))) {
            throw new Exception(
                sprintf(
                    'Cannot create directory "%s" for result cache file',
                    $this->cacheFilename
                )
            );
        }

        file_put_contents(
            $this->cacheFilename,
            serialize($this)
        );
    }

    public function setStatus(string $testName, TestStatus $status): void
    {
        if (!$status->isSuccess()) {
            $this->defects[$testName] = $status;
        }
    }

    public function status(string $testName): TestStatus
    {
        return $this->defects[$testName] ?? TestStatus::unknown();
    }

    public function setTime(string $testName, float $time): void
    {
        $this->times[$testName] = $time;
    }

    public function time(string $testName): float
    {
        return $this->times[$testName] ?? 0.0;
    }

    public function load(): void
    {
        $this->clear();

        if (!is_file($this->cacheFilename)) {
            return;
        }

        $cacheData = @file_get_contents($this->cacheFilename);

        // @codeCoverageIgnoreStart
        if ($cacheData === false) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $cache = Handler::invokeIgnoringWarnings(
            static function () use ($cacheData) {
                return @unserialize(
                    $cacheData,
                    [
                        'allowed_classes' => [
                            self::class,
                            Error::class,
                            Failure::class,
                            Incomplete::class,
                            Risky::class,
                            Skipped::class,
                            Success::class,
                            Unknown::class,
                            Warning::class,
                        ],
                    ]
                );
            }
        );

        if ($cache === false) {
            return;
        }

        if ($cache instanceof self) {
            /* @var DefaultTestResultCache $cache */
            $cache->copyStateToCache($this);
        }
    }

    public function copyStateToCache(self $targetCache): void
    {
        foreach ($this->defects as $name => $state) {
            $targetCache->setStatus($name, $state);
        }

        foreach ($this->times as $name => $time) {
            $targetCache->setTime($name, $time);
        }
    }

    public function clear(): void
    {
        $this->defects = [];
        $this->times   = [];
    }

    public function serialize(): string
    {
        return serialize([
            'defects' => $this->defects,
            'times'   => $this->times,
        ]);
    }

    /**
     * @param string $serialized
     */
    public function unserialize($serialized): void
    {
        $data = unserialize($serialized);

        if (isset($data['times'])) {
            foreach ($data['times'] as $testName => $testTime) {
                assert(is_string($testName));
                assert(is_float($testTime));
                $this->times[$testName] = $testTime;
            }
        }

        if (isset($data['defects'])) {
            foreach ($data['defects'] as $testName => $testStatus) {
                assert(is_string($testName));
                assert($testStatus instanceof TestStatus);

                $this->defects[$testName] = $testStatus;
            }
        }
    }
}
