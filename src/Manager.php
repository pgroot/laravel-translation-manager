<?php namespace Barryvdh\TranslationManager;

use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Events\Dispatcher;
use Barryvdh\TranslationManager\Models\Translation;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

class Manager{

    protected $jsonGroup = '_json';

    /** @var \Illuminate\Foundation\Application  */
    protected $app;
    /** @var \Illuminate\Filesystem\Filesystem  */
    protected $files;
    /** @var \Illuminate\Events\Dispatcher  */
    protected $events;

    protected $config;

    protected $locales;

    protected $ignoreLocales;

    protected $ignoreFilePath;

    public function __construct(Application $app, Filesystem $files, Dispatcher $events)
    {
        $this->app = $app;
        $this->files = $files;
        $this->events = $events;
        $this->config = $app['config']['translation-manager'];
        $this->ignoreFilePath = storage_path('.ignore_locales');
        $this->locales = [];
        $this->ignoreLocales = $this->getIgnoredLocales();
        $this->jsonGroup = config('translation-manager.default_group', '_json');
    }

    public function missingKey($namespace, $group, $key)
    {
        if (!in_array($group, $this->config['exclude_groups'])) {
            $placeholderAllLocales = config('translation-manager.placeholder_all_locales');

            if ($placeholderAllLocales) {
                foreach (config('app.available_locales', config('translation-manager.available_locales')) as $locale) {
                    Translation::firstOrCreate([
                        'locale' => $locale,
                        'group' => $group,
                        'key' => $key,
                    ], $this->dataWithTimestamp([
                        'locale' => $locale,
                        'group' => $group,
                        'key' => $key,
                        'value' => config('translation-manager.database.key_as_default_value', false) ? $key : null
                    ]));
                }
            } else {
                Translation::firstOrCreate([
                    'locale' => config('translation-manager.base_locale', $this->app['config']['app.locale']),
                    'group' => $group,
                    'key' => $key,
                ],$this->dataWithTimestamp([
                    'locale' => $this->app['config']['app.locale'],
                    'group' => $group,
                    'key' => $key,
                    'value' => config('translation-manager.database.key_as_default_value', false) ? $key : null
                ]));
            }
        }
    }

    public function importTranslations($replace = false)
    {
        $counter = 0;

        foreach ($this->files->directories($this->app['path.lang']) as $langPath) {
            $locale = basename($langPath);
            foreach ($this->files->allfiles($langPath) as $file) {
                $info = pathinfo($file);
                $group = $info['filename'];
                if (in_array($group, $this->config['exclude_groups'])) {
                    continue;
                }
                $subLangPath = str_replace($langPath . DIRECTORY_SEPARATOR, "", $info['dirname']);
                $subLangPath = str_replace(DIRECTORY_SEPARATOR, "/", $subLangPath);
                $langPath = str_replace(DIRECTORY_SEPARATOR, "/", $langPath);

                if ($subLangPath != $langPath) {
                    $group = $subLangPath . "/" . $group;
                }
                $translations = \Lang::getLoader()->load($locale, $group);
                if ($translations && is_array($translations)) {
                    foreach (array_dot($translations) as $key => $value) {
                        $importedTranslation = $this->importTranslation($key, $value, $locale, $group, $replace);
                        $counter += $importedTranslation ? 1 : 0;
                    }
                }
            }
        }

        foreach ($this->files->files($this->app['path.lang']) as $jsonTranslationFile) {
            if (strpos($jsonTranslationFile, '.json') === false) {
                continue;
            }
            $locale = basename($jsonTranslationFile, '.json');
            $group = $this->jsonGroup;
            $translations = \Lang::getLoader()->load($locale, '*', '*'); // Retrieves JSON entries of the given locale only
            if ($translations && is_array($translations)) {
                foreach ($translations as $key => $value) {
                    $importedTranslation = $this->importTranslation($key, $value, $locale, $group, $replace);
                    $counter += $importedTranslation ? 1 : 0;
                }
            }
        }

        return $counter;
    }

    public function importTranslation($key, $value, $locale, $group, $replace = false) {
        // process only string values
        if (is_array($value)) {
            return false;
        }
        $value = (string)$value;
        $translation = Translation::firstOrNew(array(
            'locale' => $locale,
            'group'  => $group,
            'key'    => $key,
        ));

        // Check if the database is different then the files
        $newStatus = $translation->value === $value ? Translation::STATUS_SAVED : Translation::STATUS_CHANGED;
        if ($newStatus !== (int)$translation->status) {
            $translation->status = $newStatus;
        }

        // Only replace when empty, or explicitly told so
        if ($replace || !$translation->value) {
            $translation->value = $value;
        }

        $translation->save();
        return true;
    }

    public function findTranslations($path = null)
    {
        $path = $path ?: base_path();
        $groupKeys = array();
        $stringKeys = array();
        $functions =  array('trans', 'trans_choice', 'Lang::get', 'Lang::choice', 'Lang::trans', 'Lang::transChoice', '@lang', '@choice', '__');

        $groupPattern =                              // See http://regexr.com/392hu
            "[^\w|>]".                          // Must not have an alphanum or _ or > before real method
            "(".implode('|', $functions) .")".  // Must start with one of the functions
            "\(".                               // Match opening parenthesis
            "[\'\"]".                           // Match " or '
            "(".                                // Start a new group to match:
                "[a-zA-Z0-9_-]+".               // Must start with group
                "([.|\/][^\1)]+)+".             // Be followed by one or more items/keys
            ")".                                // Close group
            "[\'\"]".                           // Closing quote
            "[\),]";                            // Close parentheses or new parameter

        $stringPattern =
            "[^\w|>]".                                     // Must not have an alphanum or _ or > before real method
            "(".implode('|', $functions) .")".             // Must start with one of the functions
            "\(".                                          // Match opening parenthesis
            "(?P<quote>['\"])".                            // Match " or ' and store in {quote}
            "(?P<string>(?:\\\k{quote}|(?!\k{quote}).)*)". // Match any string that can be {quote} escaped
            "\k{quote}".                                   // Match " or ' previously matched
            "[\),]";                                       // Close parentheses or new parameter

        // Find all PHP + Twig files in the app folder, except for storage
        $finder = new Finder();
        $finder->in($path)->exclude('storage')->name('*.php')->name('*.twig')->name('*.vue')->files();

        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            // Search the current file for the pattern
            if(preg_match_all("/$groupPattern/siU", $file->getContents(), $matches)) {
                // Get all matches
                foreach ($matches[2] as $key) {
                    $groupKeys[] = $key;
                }
            }

            if(preg_match_all("/$stringPattern/siU", $file->getContents(), $matches)) {
                foreach ($matches['string'] as $key) {
                    if (preg_match("/(^[a-zA-Z0-9_-]+([.][^\1)\ ]+)+$)/siU", $key, $groupMatches)) {
                        // group{.group}.key format, already in $groupKeys but also matched here
                        // do nothing, it has to be treated as a group
                        continue;
                    }
                    $stringKeys[] = $key;
                }
            }
        }
        // Remove duplicates
        $groupKeys = array_unique($groupKeys);
        $stringKeys = array_unique($stringKeys);

        // Add the translations to the database, if not existing.
        foreach($groupKeys as $key) {
            if(strpos($key, '.') === false) {
                continue;
            }
            // Split the group and item
            list($group, $item) = explode('.', $key, 2);
            $this->missingKey('', $group, $item);
        }

        foreach($stringKeys as $key){
            $group = $this->jsonGroup;
            $item = $key;
            $this->missingKey('', $group, $item);
        }


        // Return the number of found translations
        return count($groupKeys + $stringKeys);
    }

    public function exportTranslations($group = null, $json = false)
    {
        if (!is_null($group) && !$json) {
            if (!in_array($group, $this->config['exclude_groups'])) {
                if ($group == '*')
                    return $this->exportAllTranslations();

                $tree = $this->makeTree(Translation::ofTranslatedGroup($group)->orderByGroupKeys(array_get($this->config, 'sort_keys', false))->get());

                foreach ($tree as $locale => $groups) {
                    if (isset($groups[$group])) {
                        $translations = $groups[$group];
                        $path = $this->app['path.lang'] . '/' . $locale;
                        if(!is_dir($path)){
                            mkdir($path, 0777, true);
                        }
                        $path = $path . '/' . $group . '.php';
                        
                        $output = "<?php\n\nreturn " . var_export($translations, true) . ";".\PHP_EOL;
                        $this->files->put($path, $output);
                    }
                }
                Translation::ofTranslatedGroup($group)->update(array('status' => Translation::STATUS_SAVED));
            }
        }

        if ($json) {
            $tree = $this->makeTree(Translation::ofTranslatedGroup($this->jsonGroup)->orderByGroupKeys(array_get($this->config, 'sort_keys', false))->get(), true);

            foreach($tree as $locale => $groups){
                if(isset($groups[$this->jsonGroup])){
                    $translations = $groups[$this->jsonGroup];
                    $path = $this->app['path.lang'].'/'.$locale.'.json';
                    $output = json_encode($translations, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
                    $this->files->put($path, $output);
                }
            }

            Translation::ofTranslatedGroup($this->jsonGroup)->update(array('status' => Translation::STATUS_SAVED));
        }
    }

    public function exportAllTranslations()
    {
        $groups = Translation::whereNotNull('value')->selectDistinctGroup()->get('group');

        foreach($groups as $group){
            if ($group == $this->jsonGroup) {
                $this->exportTranslations(null, true);
            } else {
                $this->exportTranslations($group->group);
            }
        }
    }

    public function cleanTranslations()
    {
        Translation::whereNull('value')->delete();
    }

    public function truncateTranslations()
    {
        Translation::truncate();
    }

    public function getLocales()
    {
        if (empty($this->locales)) {
            $locales = array_merge([config('app.locale')], Translation::groupBy('locale')->pluck('locale')->toArray());
            foreach ($this->files->directories($this->app->langPath()) as $localeDir) {
                $locales[] = $this->files->name($localeDir);
            }
            $this->locales = array_unique($locales);
            sort($this->locales);
        }

        return array_diff($this->locales, $this->ignoreLocales);
    }

    public function addLocale($locale) 
    {
        $localeDir = $this->app->langPath() . '/' . $locale;

        $this->ignoreLocales = array_diff($this->ignoreLocales, [$locale]);
        $this->saveIgnoredLocales();
        $this->ignoreLocales = $this->getIgnoredLocales();

        if (!$this->files->exists($localeDir) || !$this->files->isDirectory($localeDir)) {
            return $this->files->makeDirectory($localeDir);
        }

        return true;
    }

    public function removeLocale($locale)
    {
        if (!$locale) {
            return false;
        }
        $this->ignoreLocales = array_merge($this->ignoreLocales, [$locale]);
        $this->saveIgnoredLocales();
        $this->ignoreLocales = $this->getIgnoredLocales();

        Translation::where('locale', $locale)->delete();
    }

    protected function makeTree($translations, $json = false)
    {
        $array = array();
        foreach($translations as $translation){
            if ($json) {
                $this->jsonSet($array[$translation->locale][$translation->group], $translation->key, $translation->value);
            } else {
                array_set($array[$translation->locale][$translation->group], $translation->key, $translation->value);
            }
        }
        return $array;
    }
    
    public function jsonSet(&$array, $key, $value)
    {
        if (is_null($key)) {
            return $array = $value;
        }
        $array[$key] = $value;
        return $array;
    }

    public function getConfig($key = null)
    {
        if($key == null) {
            return $this->config;
        }
        else {
            return $this->config[$key];
        }
    }

    protected function getIgnoredLocales()
    {
        if (!$this->files->exists($this->ignoreFilePath))
        {
            return [];
        }
        $result = json_decode($this->files->get($this->ignoreFilePath));
        return ($result && is_array($result)) ? $result : [];
    }

    protected function saveIgnoredLocales()
    {
        return $this->files->put($this->ignoreFilePath, json_encode($this->ignoreLocales));
    }

    public function dataWithTimestamp($data, $updated = false) {
        $createdAtColumn = config('translation-manager.database.created_column', 'created_at');
        $updatedAtColumn = config('translation-manager.database.updated_column', 'updated_at');
        $format = config('translation-manager.database.time_format', 'Y-m-d H:i:s');

        $now = Carbon::now();

        $timestamp = [];
        if($updated) {
            $timestamp[$updatedAtColumn] = $now->format($format);
        } else {
            $timestamp  = [
                $createdAtColumn => $now->format($format),
                $updatedAtColumn => $now->format($format),
            ];
        }

        return array_merge($data, $timestamp);
    }

}
