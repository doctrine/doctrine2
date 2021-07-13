<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Cache;

use Doctrine\ORM\Cache\CacheKey;
use Doctrine\ORM\Cache\ConcurrentRegion;
use Doctrine\ORM\Cache\Lock;
use Doctrine\ORM\Cache\Region;
use Doctrine\ORM\Cache\Region\DefaultRegion;
use Doctrine\ORM\Cache\Region\FileLockRegion;
use Doctrine\Tests\Mocks\CacheEntryMock;
use Doctrine\Tests\Mocks\CacheKeyMock;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionProperty;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const E_WARNING;

/**
 * @group DDC-2183
 */
class FileLockRegionTest extends AbstractRegionTest
{
    /** @var ConcurrentRegion */
    protected $region;

    /** @var string */
    protected $directory;

    public function tearDown(): void
    {
        $this->cleanTestDirectory($this->directory);
    }

    private function getFileName(ConcurrentRegion $region, CacheKey $key): string
    {
        $reflection = new ReflectionMethod($region, 'getLockFileName');

        $reflection->setAccessible(true);

        return $reflection->invoke($region, $key);
    }

    protected function createRegion(): Region
    {
        $this->directory = sys_get_temp_dir() . '/doctrine_lock_' . uniqid();

        $region = new DefaultRegion('concurren_region_test', $this->cache);

        return new FileLockRegion($region, $this->directory, 60);
    }

    public function testGetRegionName(): void
    {
        $this->assertEquals('concurren_region_test', $this->region->getName());
    }

    public function testLockAndUnlock(): void
    {
        $key   = new CacheKeyMock('key');
        $entry = new CacheEntryMock(['foo' => 'bar']);
        $file  = $this->getFileName($this->region, $key);

        $this->assertFalse($this->region->contains($key));
        $this->assertTrue($this->region->put($key, $entry));
        $this->assertTrue($this->region->contains($key));

        $lock = $this->region->lock($key);

        $this->assertFileExists($file);
        $this->assertInstanceOf(Lock::class, $lock);
        $this->assertEquals($lock->value, file_get_contents($file));

        // should be not available after lock
        $this->assertFalse($this->region->contains($key));
        $this->assertNull($this->region->get($key));

        $this->assertTrue($this->region->unlock($key, $lock));
        $this->assertFileDoesNotExist($file);
    }

    public function testLockWithExistingLock(): void
    {
        $key   = new CacheKeyMock('key');
        $entry = new CacheEntryMock(['foo' => 'bar']);
        $file  = $this->getFileName($this->region, $key);

        $this->assertFalse($this->region->contains($key));
        $this->assertTrue($this->region->put($key, $entry));
        $this->assertTrue($this->region->contains($key));

        file_put_contents($file, 'foo');
        $this->assertFileExists($file);
        $this->assertEquals('foo', file_get_contents($file));

        $this->assertNull($this->region->lock($key));
        $this->assertEquals('foo', file_get_contents($file));
        $this->assertFileExists($file);

        // should be not available
        $this->assertFalse($this->region->contains($key));
        $this->assertNull($this->region->get($key));
    }

    public function testUnlockWithExistingLock(): void
    {
        $key   = new CacheKeyMock('key');
        $entry = new CacheEntryMock(['foo' => 'bar']);
        $file  = $this->getFileName($this->region, $key);

        $this->assertFalse($this->region->contains($key));
        $this->assertTrue($this->region->put($key, $entry));
        $this->assertTrue($this->region->contains($key));

        $this->assertInstanceOf(Lock::class, $lock = $this->region->lock($key));
        $this->assertEquals($lock->value, file_get_contents($file));
        $this->assertFileExists($file);

        // change the lock
        file_put_contents($file, 'foo');
        $this->assertFileExists($file);
        $this->assertEquals('foo', file_get_contents($file));

        //try to unlock
        $this->assertFalse($this->region->unlock($key, $lock));
        $this->assertEquals('foo', file_get_contents($file));
        $this->assertFileExists($file);

        // should be not available
        $this->assertFalse($this->region->contains($key));
        $this->assertNull($this->region->get($key));
    }

    public function testPutWithExistingLock(): void
    {
        $key   = new CacheKeyMock('key');
        $entry = new CacheEntryMock(['foo' => 'bar']);
        $file  = $this->getFileName($this->region, $key);

        $this->assertFalse($this->region->contains($key));
        $this->assertTrue($this->region->put($key, $entry));
        $this->assertTrue($this->region->contains($key));

        // create lock
        file_put_contents($file, 'foo');
        $this->assertFileExists($file);
        $this->assertEquals('foo', file_get_contents($file));

        $this->assertFalse($this->region->contains($key));
        $this->assertFalse($this->region->put($key, $entry));
        $this->assertFalse($this->region->contains($key));

        $this->assertFileExists($file);
        $this->assertEquals('foo', file_get_contents($file));
    }

    public function testLockedEvict(): void
    {
        $key   = new CacheKeyMock('key');
        $entry = new CacheEntryMock(['foo' => 'bar']);
        $file  = $this->getFileName($this->region, $key);

        $this->assertFalse($this->region->contains($key));
        $this->assertTrue($this->region->put($key, $entry));
        $this->assertTrue($this->region->contains($key));

        $this->assertInstanceOf(Lock::class, $lock = $this->region->lock($key));
        $this->assertEquals($lock->value, file_get_contents($file));
        $this->assertFileExists($file);

        $this->assertFalse($this->region->contains($key));
        $this->assertTrue($this->region->evict($key));
        $this->assertFalse($this->region->contains($key));
        $this->assertFileDoesNotExist($file);
    }

    public function testLockedEvictAll(): void
    {
        $key1   = new CacheKeyMock('key1');
        $entry1 = new CacheEntryMock(['foo1' => 'bar1']);
        $file1  = $this->getFileName($this->region, $key1);

        $key2   = new CacheKeyMock('key2');
        $entry2 = new CacheEntryMock(['foo2' => 'bar2']);
        $file2  = $this->getFileName($this->region, $key2);

        $this->assertFalse($this->region->contains($key1));
        $this->assertTrue($this->region->put($key1, $entry1));
        $this->assertTrue($this->region->contains($key1));

        $this->assertFalse($this->region->contains($key2));
        $this->assertTrue($this->region->put($key2, $entry2));
        $this->assertTrue($this->region->contains($key2));

        $this->assertInstanceOf(Lock::class, $lock1 = $this->region->lock($key1));
        $this->assertInstanceOf(Lock::class, $lock2 = $this->region->lock($key2));

        $this->assertEquals($lock2->value, file_get_contents($file2));
        $this->assertEquals($lock1->value, file_get_contents($file1));

        $this->assertFileExists($file1);
        $this->assertFileExists($file2);

        $this->assertTrue($this->region->evictAll());

        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);

        $this->assertFalse($this->region->contains($key1));
        $this->assertFalse($this->region->contains($key2));
    }

    public function testLockLifetime(): void
    {
        $key      = new CacheKeyMock('key');
        $entry    = new CacheEntryMock(['foo' => 'bar']);
        $file     = $this->getFileName($this->region, $key);
        $property = new ReflectionProperty($this->region, 'lockLifetime');

        $property->setAccessible(true);
        $property->setValue($this->region, -10);

        $this->assertFalse($this->region->contains($key));
        $this->assertTrue($this->region->put($key, $entry));
        $this->assertTrue($this->region->contains($key));

        $this->assertInstanceOf(Lock::class, $lock = $this->region->lock($key));
        $this->assertEquals($lock->value, file_get_contents($file));
        $this->assertFileExists($file);

        // outdated lock should be removed
        $this->assertTrue($this->region->contains($key));
        $this->assertNotNull($this->region->get($key));
        $this->assertFileDoesNotExist($file);
    }

    /**
     * @group 1072
     * @group DDC-3191
     */
    public function testHandlesScanErrorsGracefullyOnEvictAll(): void
    {
        $region              = $this->createRegion();
        $reflectionDirectory = new ReflectionProperty($region, 'directory');

        $reflectionDirectory->setAccessible(true);
        $reflectionDirectory->setValue($region, str_repeat('a', 10000));

        set_error_handler(static function (): void {
        }, E_WARNING);
        $this->assertTrue($region->evictAll());
        restore_error_handler();
    }

    /**
     * @param string|null $path directory to clean
     */
    private function cleanTestDirectory(?string $path): void
    {
        $path = $path ?: $this->directory;

        if (! is_dir($path)) {
            return;
        }

        $directoryIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($directoryIterator as $file) {
            if ($file->isFile()) {
                @unlink((string) $file->getRealPath());
            } else {
                @rmdir((string) $file->getRealPath());
            }
        }
    }
}
