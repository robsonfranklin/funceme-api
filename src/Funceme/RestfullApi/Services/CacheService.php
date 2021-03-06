<?php
namespace Funceme\RestfullApi\Services;

use \Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

use Funceme\RestfullApi\Jobs\UpdateCacheJob;
use Funceme\RestfullApi\DTOs\CacheOptionsDTO;
use Funceme\RestfullApi\DTOs\CacheableObjectDTO;
use Funceme\RestfullApi\DTOs\MetaRequestDTO;
use Funceme\RestfullApi\DTOs\MetaCacheDTO;

class CacheService
{
    private $cacheable_service;
    private $cached_object;

    private $cache_service;

    const BUILDING_FLAG = 'BUILDING';
    const WAIT_BUILDING = 3;
    const BUILDING_TIMEOUT = 60;

    public function __construct($cacheable_service)
    {
        $this->cacheable_service  = $cacheable_service;
    }

    protected function getElapsedTime($start)
    {
        return round((microtime(true) - $start), 4);
    }

    public function get(): CacheableObjectDTO
    {
        $start = microtime(true);

        $this->cached_object = $this->getCachedObject();

        // if ($this->cached_object == self::BUILDING_FLAG) {
        //     sleep(self::WAIT_BUILDING);
        //     return $this->get();
        // }

        if ($this->cached_object && $this->cacheable_service->getMetaRequest()->getCacheOptions()->getUseCache()) {
            $object = $this->cached_object;

            if ($this->cacheable_service->getMetaRequest()->getCacheOptions()->getMaxAge() > 0 
                && $this->isOlderThan($object, $this->cacheable_service->getMetaRequest()->getCacheOptions()->getMaxAge())) {
                $object = $this->getFromDatabase();
            }

            if ($this->isReadyToQueue($object)) {
                dispatch(new UpdateCacheJob($this->cacheable_service, $object));
            }
        } else {
            $object = $this->getFromDatabase();
        }

        $object->getMetaCache()->setBuiltIn($this->getElapsedTime($start));

        return $object;
    }

    private function getCachedObject()
    {
        $cached_object = null;

        /*if (!Redis::connection()->client()->isConnected()) {
            Log::warning('Redis is not connected!');
            return null;
        }   */ 

        $hash = $this->cacheable_service->hash();
        $cache_tags = array_merge([env('APP_NAME')], $this->cacheable_service->getCacheTags());

        if (Cache::tags($cache_tags)->has($hash)) {

            $obj = Cache::tags($cache_tags)->get($hash);

            // if ($obj == self::BUILDING_FLAG)
            //     return $obj;

            $cached_object = unserialize($obj, ['allowed_classes' => true]);

            $age = Carbon::now()->diffInSeconds($cached_object->getMetaCache()->getCachedAt());

            $cached_object->getMetaCache()->setExpiresIn($this->cacheable_service->cache_times->getExpirationTime() - $age);
            $cached_object->getMetaCache()->setQueueIn($this->cacheable_service->cache_times->getUpdateTime() - $age);
            $cached_object->getMetaCache()->setFromCache(true);

            Redis::connection()->client()->quit();
        }

        return $cached_object;
    }

    private function cacheIsOlderThan(int $life_in_seconds): bool
    {
        if (!$this->cached_object)
            return false;

        $age = Carbon::now()->diffInSeconds($this->cached_object->getCachedAt());
        return ($age > $life_in_seconds)? true : false;
    }

    private function cacheIsNewerThan(int $life_in_seconds): bool
    {
        if (!$this->cached_object)
            return false;

        $age = Carbon::now()->diffInSeconds($this->cached_object->getMetaCache()->getCachedAt());
        return ($age < $life_in_seconds)? true : false;
    }

    private function flagAsBuilding()
    {
        $cache_tags = array_merge([env('APP_NAME')], $this->cacheable_service->getCacheTags());
        $hash = $this->cacheable_service->hash();

        Cache::tags($cache_tags)->put($hash, self::BUILDING_FLAG, Carbon::now()->addSeconds(self::BUILDING_TIMEOUT));
    }

    public function getFromDatabase($ignore_cache = false): CacheableObjectDTO
    {
        if (!$ignore_cache && ($this->cacheable_service->getMetaRequest()->getCacheOptions()->getOnlyIfCached()
            || ($this->cached_object
                && $this->cacheIsNewerThan($this->cacheable_service->cache_times->getMinDatabaseRefreshTime())))) {
            return $this->cached_object;
        }

        // $this->flagAsBuilding();

        $meta_cache = (new MetaCacheDTO)
            ->setExpiresIn($this->cacheable_service->cache_times->getExpirationTime())
            ->setQueueIn($this->cacheable_service->cache_times->getUpdateTime())
            ->setFromCache(false);

        $object = (new CacheableObjectDTO)
            ->setMetaRequest($this->cacheable_service->getMetaRequest())
            ->setMetaCache($meta_cache)
            ->setData($this->cacheable_service->doQuery());

        if (!$this->cacheable_service->getMetaRequest()->getCacheOptions()->getNoStore()) {
            $this->updateCache($object);
        }

        return $object;
    }

    private function isReadyToQueue(CacheableObjectDTO $object): bool
    {
        $age = Carbon::now()->diffInSeconds($object->getMetaCache()->getCachedAt());

        return ($age >= $this->cacheable_service->cache_times->getUpdateTime() && !$object->getMetaCache()->hasQueuedAt());
    }

    public function updateCache(CacheableObjectDTO $object)
    {
        /*if (!Redis::connection()->client()->isConnected())
            return null;*/

        $expiration_time = $this->cacheable_service->cache_times->getExpirationTime();
        $cache_tags = array_merge([env('APP_NAME')], $this->cacheable_service->getCacheTags());
        $hash = $this->cacheable_service->hash();

        $object->getMetaCache()->setCachedAt(Carbon::now());

        Cache::tags($cache_tags)
            ->put($hash, serialize($object), Carbon::now()->addSeconds($expiration_time));

        Redis::connection()->client()->pipeline()->getClient()->disconnect();
        Redis::connection()->client()->disconnect();
    }
}
