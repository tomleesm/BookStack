<?php namespace BookStack\Settings;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Class SettingService
 * The settings are a simple key-value database store.
 * For non-authenticated users, user settings are stored via the session instead.
 */
class SettingService
{

    protected $setting;
    protected $cache;
    protected $localCache = [];

    protected $cachePrefix = 'setting-';

    /**
     * SettingService constructor.
     * @param Setting $setting
     * @param Cache   $cache
     */
    public function __construct(Setting $setting, Cache $cache)
    {
        $this->setting = $setting;
        $this->cache = $cache;
    }

    /**
     * Gets a setting from the database,
     * If not found, Returns default, Which is false by default.
     * @param             $key
     * @param string|bool $default
     * @return bool|string
     */
    public function get($key, $default = false)
    {
        // 如果沒有預設值，就使用 app/Config/settinh-defaults.php 的設定
        if ($default === false) {
            $default = config('setting-defaults.' . $key, false);
        }

        // 如果這個物件週期還沒結束，又存取，就用先前儲存的設定
        if (isset($this->localCache[$key])) {
            return $this->localCache[$key];
        }


        // 從 $this->cache->get() 抓資料，沒有的話才從資料庫
        // SELECT * FROM settings WHERE setting_key = '$key' LIMIT 1
        $value = $this->getValueFromStore($key, $default);
        $formatted = $this->formatValue($value, $default);
        // 儲存到 property $localCache
        $this->localCache[$key] = $formatted;
        return $formatted;
    }

    /**
     * Get a value from the session instead of the main store option.
     * @param $key
     * @param bool $default
     * @return mixed
     */
    protected function getFromSession($key, $default = false)
    {
        // 從這個登入的使用者 session 中抓取 $key = user:123:bookshelves_view_type 的資料
        // 否則回傳 $default 值
        $value = session()->get($key, $default);
        $formatted = $this->formatValue($value, $default);
        return $formatted;
    }

    /**
     * Get a user-specific setting from the database or cache.
     * @param \BookStack\Auth\User $user
     * @param $key
     * @param bool $default
     * @return bool|string
     */
    public function getUser($user, $key, $default = false)
    {
        // 目前登入的使用者的資料庫欄位 system_name 是否爲 public
        if ($user->isDefault()) {
            return $this->getFromSession($key, $default);
        }
        // userKey(): 回傳字串 user:123:bookshelves_view_type
        return $this->get($this->userKey($user->id, $key), $default);
    }

    /**
     * Get a value for the current logged-in user.
     * @param $key
     * @param bool $default
     * @return bool|string
     */
    public function getForCurrentUser($key, $default = false)
    {
        // 目前登入的使用者的
        return $this->getUser(user(), $key, $default);
    }

    /**
     * Gets a setting value from the cache or database.
     * Looks at the system defaults if not cached or in database.
     * @param $key
     * @param $default
     * @return mixed
     */
    protected function getValueFromStore($key, $default)
    {
        // Check the cache
        $cacheKey = $this->cachePrefix . $key;
        $cacheVal = $this->cache->get($cacheKey, null);
        if ($cacheVal !== null) {
            return $cacheVal;
        }

        // Check the database
        $settingObject = $this->getSettingObjectByKey($key);
        if ($settingObject !== null) {
            $value = $settingObject->value;
            $this->cache->forever($cacheKey, $value);
            return $value;
        }

        return $default;
    }

    /**
     * Clear an item from the cache completely.
     * @param $key
     */
    protected function clearFromCache($key)
    {
        $cacheKey = $this->cachePrefix . $key;
        $this->cache->forget($cacheKey);
        if (isset($this->localCache[$key])) {
            unset($this->localCache[$key]);
        }
    }

    /**
     * Format a settings value
     * @param $value
     * @param $default
     * @return mixed
     */
    // $value 是字串 true 或 false，回傳布林值 true 或 false
    // $value 是空值，則回傳 $default
    protected function formatValue($value, $default)
    {
        // Change string booleans to actual booleans
        if ($value === 'true') {
            $value = true;
        }
        if ($value === 'false') {
            $value = false;
        }

        // Set to default if empty
        if ($value === '') {
            $value = $default;
        }
        return $value;
    }

    /**
     * Checks if a setting exists.
     * @param $key
     * @return bool
     */
    public function has($key)
    {
        $setting = $this->getSettingObjectByKey($key);
        return $setting !== null;
    }

    /**
     * Check if a user setting is in the database.
     * @param $key
     * @return bool
     */
    public function hasUser($key)
    {
        return $this->has($this->userKey($key));
    }

    /**
     * Add a setting to the database.
     * @param $key
     * @param $value
     * @return bool
     */
    public function put($key, $value)
    {
        // $this->setting: app/Setting/Setting.php 存取 Setting 資料表的 eloquent
        $setting = $this->setting->firstOrNew([
            'setting_key' => $key
        ]);
        $setting->value = $value;
        $setting->save();
        // 剛剛新增一筆資料，所以要刪除快取
        $this->clearFromCache($key);
        return true;
    }

    /**
     * Put a user-specific setting into the database.
     * @param \BookStack\Auth\User $user
     * @param $key
     * @param $value
     * @return bool
     */
    public function putUser($user, $key, $value)
    {
        if ($user->isDefault()) {
            return session()->put($key, $value);
        }
        return $this->put($this->userKey($user->id, $key), $value);
    }

    /**
     * Convert a setting key into a user-specific key.
     * @param $key
     * @return string
     */
    protected function userKey($userId, $key = '')
    {
        return 'user:' . $userId . ':' . $key;
    }

    /**
     * Removes a setting from the database.
     * @param $key
     * @return bool
     */
    public function remove($key)
    {
        $setting = $this->getSettingObjectByKey($key);
        if ($setting) {
            $setting->delete();
        }
        $this->clearFromCache($key);
        return true;
    }

    /**
     * Delete settings for a given user id.
     * @param $userId
     * @return mixed
     */
    public function deleteUserSettings($userId)
    {
        return $this->setting->where('setting_key', 'like', $this->userKey($userId) . '%')->delete();
    }

    /**
     * Gets a setting model from the database for the given key.
     * @param $key
     * @return mixed
     */
    protected function getSettingObjectByKey($key)
    {
        return $this->setting->where('setting_key', '=', $key)->first();
    }
}
