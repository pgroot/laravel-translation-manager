<?php
/**
 * Date: 2018-03-22 17:13
 * @author: GROOT (pzyme@outlook.com)
 */

namespace Barryvdh\TranslationManager;

use Illuminate\Translation\FileLoader;
use Illuminate\Contracts\Translation\Loader;
use DB;
use Cache;

class DatabaseLoader extends FileLoader implements Loader
{
    public function load($locale, $group, $namespace = null): array
    {
        if ($group == '*' && $namespace == '*') {
            return $this->loadFromDatabase($locale);
        }

        if (is_null($namespace) || $namespace == '*') {
            return $this->loadPath($this->path, $locale, $group);
        }

        return $this->loadNamespaced($locale, $group, $namespace);

    }

    /**
     * @param $locale
     * @return array|mixed
     */
    protected function loadFromDatabase($locale) {
        $cacheEnabled = config('translation-manager.cache.enable', false);
        $cacheTag = config('translation-manager.cache.tag', 'trans');
        $cacheKey = 'locale_' . $locale;
        $table = config('translation-manager.database.table', 'ltm_translations');

        if($cacheEnabled) {
            $trans = Cache::tags($cacheTag)->get($cacheKey);
            if(!empty($trans)) {
                return $trans;
            }
        }

        $result = DB::table($table)->where([
            'locale' => $locale
        ])->get()->toArray();

        $trans = [];
        foreach($result as $key => $val) {
            $trans[$val->key] = $val->value;
        }

        if($cacheEnabled) Cache::tags($cacheTag)->forever($cacheKey, $trans);

        return $trans;
    }
}